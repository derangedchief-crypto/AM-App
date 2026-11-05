package net.extremeiptv.account.data

import kotlinx.coroutines.suspendCancellableCoroutine
import okhttp3.*
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import org.json.JSONArray
import java.io.IOException
import java.math.BigDecimal
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

internal data class PaymentOption(val gateway: String, val mode: String)
internal fun gatewayName(gateway: String): String = when (gateway) { "paypal" -> "PayPal"; "stripe" -> "Stripe"; "rampex" -> "Rampex"; else -> "Square" }
internal data class RenewalOffer(val packageId: Int, val name: String, val amount: Long, val currency: String,
    val duration: Int, val period: String, val connections: Int, val promoId: String? = null,
    val paymentOptions: List<PaymentOption> = emptyList(), val gateway: String = "square", val email: String = "") {
    val price: String get() = "${BigDecimal.valueOf(amount, 2).toPlainString()} $currency"
}
internal data class RenewalQuote(val id: String, val offer: RenewalOffer, val mode: String, val promoTitle: String? = null)
internal data class PromotionalDeal(val offer: RenewalOffer, val title: String, val description: String,
    val bannerUrl: String, val endsAt: Long, val normalAmount: Long) {
    val normalPrice: String get() = "${BigDecimal.valueOf(normalAmount, 2).toPlainString()} ${offer.currency}"
}
internal data class RenewalOrder(val id: String, val name: String, val amount: Long, val currency: String,
    val mode: String, val state: String, val verified: Boolean, val createdAt: Long, val checkoutUrl: String?, val gateway: String = "square") {
    val price: String get() = "${BigDecimal.valueOf(amount, 2).toPlainString()} $currency"
    val complete: Boolean get() = state in setOf("applied", "sandbox_verified")
    val message: String get() = when (state) {
        "applied" -> "Service renewed."
        "sandbox_verified" -> "Sandbox test verified. No real payment or service renewal."
        "refresh_pending" -> "Renewal saved; service refresh is processing. Do not pay again."
        "review", "applying" -> if (verified) "Payment verified. Renewal needs provider review; do not pay again."
            else "Verification needs retry or provider review. Keep this order; do not create another payment."
        else -> "Awaiting payment verification. Continue this order to pay or check its status."
    }
}

/** AM is authoritative for ownership, access, retail pricing and payment status. */
internal class ManagedAccountClient(
    private val endpoint: HttpUrl = "https://fecxbrfzku.net/am-app/customer-api.php".toHttpUrl(),
    private val activationResolver: AccountClient = ServiceAccountClient(),
) : AccountClient {
    private val http = OkHttpClient.Builder().connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS).callTimeout(70, TimeUnit.SECONDS)
        .followRedirects(false).followSslRedirects(false).retryOnConnectionFailure(false).build()
    private var token: String? = null
    private var pendingAccount: ServiceAccount? = null
    private var sessionGeneration = 0

    override fun clearSession() {
        sessionGeneration++
        val previous = token
        token = null; pendingAccount = null
        if (previous != null) {
            val request = Request.Builder().url(endpoint).header("Authorization", "Bearer $previous")
                .post("{\"action\":\"logout\"}".toRequestBody("application/json".toMediaType())).build()
            http.newCall(request).enqueue(object : Callback {
                override fun onFailure(call: Call, e: IOException) {} // Local token is already gone.
                override fun onResponse(call: Call, response: Response) { response.close() }
            })
        }
    }

    override suspend fun resolveCode(code: String): ServiceCredentials {
        val account = try { login(JSONObject().put("activation_code", code)) }
        catch (e: AccountFailure) {
            if (e.kind != FailureKind.UNAUTHORIZED) throw e
            // Older Hub codes resolve here, but AM still verifies ownership and opt-in.
            val credentials = activationResolver.resolveCode(code)
            login(JSONObject().put("username", credentials.username).put("password", credentials.password))
        }
        pendingAccount = account
        return ServiceCredentials(account.username, "")
    }

    override suspend fun readAccount(credentials: ServiceCredentials): ServiceAccount {
        pendingAccount?.let { pendingAccount = null; return it }
        return if (token == null) login(JSONObject().put("username", credentials.username).put("password", credentials.password))
        else parse { account(call("account").getJSONObject("account")) }
    }

    private suspend fun login(fields: JSONObject): ServiceAccount {
        val generation = sessionGeneration
        val reply = call("login", fields, authenticated = false)
        return parse {
            val t = reply.getString("token")
            require(t.matches(Regex("[a-f0-9]{64}")))
            val account = account(reply.getJSONObject("account"))
            if (generation != sessionGeneration) throw AccountFailure(FailureKind.UNAUTHORIZED)
            token = t
            account
        }
    }

    override suspend fun orders(): List<RenewalOrder> {
        val reply = call("orders")
        return parse { val rows = reply.getJSONArray("orders"); (0 until rows.length()).map { orderJson(rows.getJSONObject(it)) } }
    }
    override suspend fun quote(offer: RenewalOffer): RenewalQuote {
        val fields = JSONObject().put("package_id", offer.packageId).put("currency", offer.currency)
        offer.promoId?.let { fields.put("promo_id", it) }
        if (offer.gateway != "square") fields.put("gateway", offer.gateway)
        if (offer.gateway == "rampex") fields.put("email", offer.email)
        val reply = call("quote", fields)
        return parse {
            val q = reply.getJSONObject("quote")
            require(gateway(q) == offer.gateway)
            RenewalQuote(id(q, "quote_id"), offer.copy(name = q.getString("package_name"), amount = money(q),
                currency = currency(q), duration = q.getInt("duration"), period = q.getString("duration_period"),
                connections = q.getInt("max_connections"), email = ""), mode(q), if (q.isNull("promo_title")) null else q.getString("promo_title"))
        }
    }
    override suspend fun checkout(quote: RenewalQuote): RenewalOrder {
        val reply = call("checkout", JSONObject().put("quote_id", quote.id))
        return parse { orderJson(reply.getJSONObject("order")) }
    }
    override suspend fun order(id: String): RenewalOrder {
        val reply = call("order", JSONObject().put("order_id", id))
        return parse { orderJson(reply.getJSONObject("order")) }
    }

    override suspend fun saveBouquets(ids: Set<Int>, revision: String): BouquetSave {
        val reply = call("bouquets", JSONObject().put("bouquet_ids", JSONArray(ids.sorted())).put("revision", revision))
        return parse { BouquetSave(account(reply.getJSONObject("account")), reply.getBoolean("refresh_pending")) }
    }

    private fun account(json: JSONObject): ServiceAccount {
        val line = json.getJSONObject("service")
        val expiry = line.getLong("expire_at").also { require(it >= 0) }
        val offers = json.getJSONArray("offers")
        val bouquets = line.getJSONArray("bouquets")
        val deals = json.optJSONArray("deals") ?: JSONArray()
        return ServiceAccount(username = line.getString("username"),
            status = if (expiry > 0 && expiry <= json.getLong("server_time")) ServiceStatus.EXPIRED else ServiceStatus.ACTIVE,
            expiresAt = expiry.takeIf { it > 0 }, unlimited = expiry == 0L && line.getBoolean("activated"),
            maxConnections = line.getInt("max_connections"), activeConnections =
                (line.opt("active_connections") as? Number)?.toLong()?.takeIf { it in 0..Int.MAX_VALUE.toLong() }?.toInt(),
            trial = line.getBoolean("is_trial"),
            packageName = if (line.isNull("package_name")) null else line.getString("package_name"), createdAt = null,
            providerName = json.getString("provider"), supportEmail = json.getString("support_email"),
            renewalUnavailableMessage = if (json.isNull("renewal_unavailable_message")) null else json.getString("renewal_unavailable_message").take(2000),
            renewalMode = json.getString("renewal_mode").also { require(it in setOf("live", "sandbox")) },
            bouquetRevision = line.optString("bouquet_revision"),
            canEditBouquets = json.optBoolean("allow_bouquets") && line.optString("bouquet_revision").matches(Regex("[a-f0-9]{64}")),
            bouquetChoices = (0 until bouquets.length()).map { bouquets.getJSONObject(it) }.map {
                BouquetChoice(it.optInt("id"), it.getString("name"), it.getBoolean("selected"), it.optBoolean("available"))
            },
            bouquets = (0 until bouquets.length()).map { bouquets.getJSONObject(it) }.filter { it.getBoolean("selected") }.map { it.getString("name") },
            serverTime = json.getLong("server_time"), loadedNanos = System.nanoTime(),
            deals = (0 until deals.length()).map { i -> deals.getJSONObject(i).let {
                val packageId = it.getInt("package_id"); require(packageId == line.getInt("package_id"))
                val promoId = id(it, "promo_id")
                val banner = it.getString("banner_url").toHttpUrl()
                require(banner.scheme == endpoint.scheme && banner.host == endpoint.host && banner.port == endpoint.port &&
                    banner.username.isEmpty() && banner.password.isEmpty() && banner.encodedPath == endpoint.encodedPath.substringBeforeLast('/') + "/promo-banner.php" &&
                    banner.queryParameter("id") == promoId)
                val amount = money(it); val normal = it.getLong("normal_amount_minor"); require(normal > amount && normal <= 99999999)
                val connections = it.getInt("max_connections"); require(connections == line.getInt("max_connections"))
                PromotionalDeal(RenewalOffer(packageId, it.getString("name"), amount, currency(it), it.getInt("duration"),
                    it.getString("duration_period"), connections, promoId, paymentOptions(it, json.getString("renewal_mode"))), it.getString("title"), it.getString("description"),
                    banner.toString(), it.getLong("ends_at"), normal)
            } },
            offers = (0 until offers.length()).map { i -> offers.getJSONObject(i).let {
                val packageId = it.getInt("package_id"); require(packageId == line.getInt("package_id"))
                RenewalOffer(packageId, it.getString("name"), money(it), currency(it), it.getInt("duration"),
                    it.getString("duration_period"), it.getInt("max_connections").also { count -> require(count == line.getInt("max_connections")) }, paymentOptions = paymentOptions(it, json.getString("renewal_mode")))
            } })
    }

    private fun orderJson(o: JSONObject): RenewalOrder {
        val mode = mode(o)
        val gateway = gateway(o)
        require(gateway != "rampex" || mode == "live")
        val url = if (o.isNull("checkout_url")) null else o.getString("checkout_url").also { value ->
            val parsed = value.toHttpUrl()
            val allowed = when (gateway) {
                "stripe" -> setOf("checkout.stripe.com")
                "paypal" -> setOf(if (mode == "sandbox") "www.sandbox.paypal.com" else "www.paypal.com")
                "rampex" -> setOf("checkout.rampex.io", "rampex.io")
                else -> setOf("square.link", "checkout.square.site") + if (mode == "sandbox") setOf("sandbox.square.link", "sandbox.checkout.square.site") else emptySet()
            }
            require(parsed.isHttps && parsed.host in allowed && parsed.port == 443 && parsed.username.isEmpty() && parsed.password.isEmpty())
        }
        return RenewalOrder(id(o, "id"), o.getString("package_name"), money(o), currency(o), mode,
            o.getString("state").also { require(it in setOf("creating", "pending", "applying", "review", "refresh_pending", "applied", "sandbox_verified")) },
            o.getBoolean("payment_verified"), o.getLong("created_at"), url, gateway)
    }
    private fun gateway(o: JSONObject) = o.optString("gateway", "square").also { require(it in setOf("square", "stripe", "paypal", "rampex")) }
    private fun paymentOptions(o: JSONObject, fallbackMode: String): List<PaymentOption> {
        val rows = o.optJSONArray("payment_options") ?: return listOf(PaymentOption("square", fallbackMode))
        require(rows.length() in 1..4)
        return (0 until rows.length()).map { rows.getJSONObject(it).let { row ->
            PaymentOption(gateway(row), mode(row)).also { require(it.gateway != "rampex" || it.mode == "live") }
        } }.also { require(it.map { option -> option.gateway }.distinct().size == it.size) }
    }
    private fun id(o: JSONObject, key: String) = o.getString(key).also { require(it.matches(Regex("[a-f0-9]{32}"))) }
    private fun money(o: JSONObject) = (o.get("amount_minor") as Number).toLong().also { require(it in 0..99999999) }
    private fun currency(o: JSONObject) = o.getString("currency").also { require(it in setOf("CAD", "USD")) }
    private fun mode(o: JSONObject) = o.getString("mode").also { require(it in setOf("sandbox", "live")) }

    private suspend fun call(action: String, fields: JSONObject = JSONObject(), authenticated: Boolean = true): JSONObject {
        val builder = Request.Builder().url(endpoint).header("Accept", "application/json").header("Cache-Control", "no-store")
            .post(fields.put("action", action).toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
        if (authenticated) builder.header("Authorization", "Bearer ${token ?: throw AccountFailure(FailureKind.UNAUTHORIZED)}")
        return suspendCancellableCoroutine { continuation ->
            val call = http.newCall(builder.build())
            continuation.invokeOnCancellation { call.cancel() }
            call.enqueue(object : Callback {
                override fun onFailure(call: Call, e: IOException) {
                    if (continuation.isActive) continuation.resumeWithException(AccountFailure(FailureKind.UNAVAILABLE))
                }
                override fun onResponse(call: Call, response: Response) {
                    try {
                        val json = response.use {
                            if (!it.isSuccessful) throw AccountFailure(when (it.code) {
                                401 -> FailureKind.UNAUTHORIZED; 403 -> FailureKind.RESTRICTED; 429 -> FailureKind.RATE_LIMITED
                                409, 422 -> FailureKind.CONFLICT; else -> FailureKind.UNAVAILABLE
                            })
                            val source = it.body?.source() ?: throw AccountFailure(FailureKind.INVALID_RESPONSE)
                            source.request(262145)
                            if (source.buffer.size > 262144) throw AccountFailure(FailureKind.INVALID_RESPONSE)
                            parse { JSONObject(source.readUtf8()).also { json -> require(json.getBoolean("ok")) } }
                        }
                        if (continuation.isActive) continuation.resume(json)
                    } catch (e: Exception) {
                        if (continuation.isActive) continuation.resumeWithException(if (e is AccountFailure) e else AccountFailure(FailureKind.INVALID_RESPONSE))
                    }
                }
            })
        }
    }
    private inline fun <T> parse(block: () -> T): T = try { block() } catch (e: AccountFailure) { throw e }
        catch (_: Exception) { throw AccountFailure(FailureKind.INVALID_RESPONSE) }
}
