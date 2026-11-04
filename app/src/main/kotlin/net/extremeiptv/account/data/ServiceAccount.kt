package net.extremeiptv.account.data

import org.json.JSONObject
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter

/** Resolved credentials used in memory. Never use a generated data-class toString for secrets. */
internal class ServiceCredentials(val username: String, val password: String) {
    override fun toString() = "ServiceCredentials([redacted])"
}

internal enum class ServiceStatus(val label: String) { ACTIVE("Active"), EXPIRED("Expired") }

internal data class ServiceAccount(
    val username: String,
    val status: ServiceStatus,
    val expiresAt: Long?,
    val unlimited: Boolean,
    val maxConnections: Int?,
    val activeConnections: Int?,
    val trial: Boolean?,
    val packageName: String?,
    val createdAt: Long?,
    val checkedAtMillis: Long = System.currentTimeMillis(),
    val providerName: String = "",
    val supportEmail: String = "",
    val bouquets: List<String> = emptyList(),
    val offers: List<RenewalOffer> = emptyList(),
    val renewalMode: String = "sandbox",
    val bouquetChoices: List<BouquetChoice> = emptyList(),
    val bouquetRevision: String = "",
    val canEditBouquets: Boolean = false,
)

internal data class BouquetChoice(val id: Int, val name: String, val selected: Boolean, val available: Boolean)
internal data class BouquetSave(val account: ServiceAccount, val refreshPending: Boolean)

internal enum class FailureKind { INVALID_INPUT, UNAUTHORIZED, RESTRICTED, RATE_LIMITED, UNAVAILABLE, INVALID_RESPONSE, CONFLICT }

/** Fixed messages only: raw responses/URLs may contain credentials. */
internal class AccountFailure(val kind: FailureKind) : Exception(when (kind) {
    FailureKind.INVALID_INPUT -> "Enter your six-digit activation code or your service username and password."
    FailureKind.UNAUTHORIZED -> "Your login could not be verified. Check your code or service credentials."
    FailureKind.RESTRICTED -> "This service is disabled or restricted. Please contact your provider."
    FailureKind.RATE_LIMITED -> "Too many requests. Please wait a few minutes before trying again."
    FailureKind.UNAVAILABLE -> "Unable to reach the service provider. Check your connection and try again."
    FailureKind.INVALID_RESPONSE -> "The provider returned incomplete account information. Please try again later."
    FailureKind.CONFLICT -> "This change could not be confirmed. Refresh your account before trying again. For payments, check the existing order; do not pay again. Contact your reseller if it needs review."
})

internal object AccountParser {
    fun activation(json: JSONObject): ServiceCredentials {
        if (json.opt("ok") != true) throw AccountFailure(FailureKind.UNAUTHORIZED)
        val username = text(json, "username", 256)
        // Password whitespace is significant; do not trim a resolved credential.
        val password = (json.opt("password") as? String)?.takeIf { it.isNotBlank() && it.length <= 512 }
        if (username == null || password == null) throw AccountFailure(FailureKind.INVALID_RESPONSE)
        return ServiceCredentials(username, password)
    }

    fun account(json: JSONObject, expectedUsername: String, dateHeader: String?): ServiceAccount {
        val user = json.optJSONObject("user_info") ?: throw AccountFailure(FailureKind.INVALID_RESPONSE)
        when (user.opt("auth")?.toString()) {
            "0", "false" -> throw AccountFailure(FailureKind.UNAUTHORIZED)
            "1" -> Unit
            else -> throw AccountFailure(FailureKind.INVALID_RESPONSE)
        }
        if (user.has("username") && text(user, "username", 256) != expectedUsername) {
            throw AccountFailure(FailureKind.INVALID_RESPONSE)
        }
        var status = when (text(user, "status", 32)?.lowercase(java.util.Locale.ROOT)) {
            "active" -> ServiceStatus.ACTIVE
            "expired" -> ServiceStatus.EXPIRED
            "banned", "disabled", "inactive", "deleted", "removed", "invalid", "blocked" ->
                throw AccountFailure(FailureKind.RESTRICTED)
            else -> throw AccountFailure(FailureKind.INVALID_RESPONSE)
        }
        val rawExpiry = user.opt("exp_date")
        val unlimited = user.has("exp_date") && (rawExpiry == JSONObject.NULL || rawExpiry?.toString() == "0")
        val expiresAt = when {
            !user.has("exp_date") || unlimited -> null
            else -> positiveTimestamp(rawExpiry) ?: throw AccountFailure(FailureKind.INVALID_RESPONSE)
        }
        // Use provider time (as Hub does), not the phone clock, to interpret expiry.
        val bodyTime = positiveTimestamp(json.optJSONObject("server_info")?.opt("timestamp_now"))
        val headerTime = runCatching { ZonedDateTime.parse(dateHeader, DateTimeFormatter.RFC_1123_DATE_TIME).toEpochSecond() }.getOrNull()
        val providerTime = listOfNotNull(bodyTime, headerTime).maxOrNull()
        if (expiresAt != null && providerTime != null && expiresAt <= providerTime) status = ServiceStatus.EXPIRED
        return ServiceAccount(
            username = expectedUsername, status = status, expiresAt = expiresAt, unlimited = unlimited,
            maxConnections = count(user, "max_connections"), activeConnections = count(user, "active_cons"),
            trial = when (user.opt("is_trial")?.toString()) { "1", "true" -> true; "0", "false" -> false; else -> null },
            // Only explicit fields: Hub's fuzzy entitlement labels are not a billing package name.
            packageName = text(user, "package_name", 160) ?: text(user, "package", 160),
            createdAt = positiveTimestamp(user.opt("created_at")),
        )
    }

    private fun count(json: JSONObject, key: String): Int? = json.opt(key)?.toString()?.toIntOrNull()?.takeIf { it >= 0 }
    private fun positiveTimestamp(raw: Any?): Long? = raw?.toString()?.toLongOrNull()?.takeIf { it in 1..253402300799L }
    private fun text(json: JSONObject, key: String, max: Int): String? =
        (json.opt(key) as? String)?.trim()?.takeIf { it.isNotEmpty() && it.length <= max && it.none(Char::isISOControl) }
}
