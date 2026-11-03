package net.extremeiptv.account.data

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.suspendCancellableCoroutine
import okhttp3.Call
import okhttp3.Callback
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

internal interface AccountClient {
    suspend fun resolveCode(code: String): ServiceCredentials
    suspend fun readAccount(credentials: ServiceCredentials): ServiceAccount
}

/** Independent AM implementation of Hub's wire protocol. No Hub SDK or shared app state. */
internal class ServiceAccountClient(
    private val provider: HttpUrl = "https://tv.extremeiptv.net/".toHttpUrl(),
    private val activation: HttpUrl = "https://activationworker.extremeiptv.net/activate".toHttpUrl(),
) : AccountClient {
    private val http = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS).readTimeout(12, TimeUnit.SECONDS).callTimeout(15, TimeUnit.SECONDS)
        .followRedirects(false).followSslRedirects(false).retryOnConnectionFailure(false).build()

    override suspend fun resolveCode(code: String): ServiceCredentials {
        if (!code.matches(Regex("[0-9]{6}"))) throw AccountFailure(FailureKind.INVALID_INPUT)
        val response = request(Request.Builder().url(activation)
            .post(JSONObject().put("activation_code", code).toString().toRequestBody("application/json; charset=utf-8".toMediaType())))
        return parse { AccountParser.activation(JSONObject(response.body)) }
    }

    override suspend fun readAccount(credentials: ServiceCredentials): ServiceAccount {
        if (credentials.username.isBlank() || credentials.username.length > 256 ||
            credentials.password.isBlank() || credentials.password.length > 512) throw AccountFailure(FailureKind.INVALID_INPUT)
        val response = request(Request.Builder().url(accountUrl(credentials)))
        val account = parse { AccountParser.account(JSONObject(response.body), credentials.username, response.date) }
        if (account.status != ServiceStatus.ACTIVE) return account.copy(
            categoryWarning = "Channel groups are unavailable while the service is expired.")
        return try {
            val categories = request(Request.Builder().url(accountUrl(credentials, "get_live_categories")), 1024 * 1024)
            account.copy(categories = parse { AccountParser.categories(JSONArray(categories.body)) })
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: AccountFailure) {
            // An authorization failure must never leave a usable account in the UI.
            if (failure.kind in setOf(FailureKind.UNAUTHORIZED, FailureKind.RESTRICTED)) throw failure
            account.copy(categoryWarning = "Account verified, but channel groups could not be loaded. Refresh to retry.")
        }
    }

    private fun accountUrl(credentials: ServiceCredentials, action: String? = null): HttpUrl =
        provider.newBuilder().addPathSegment("player_api.php")
            .addQueryParameter("username", credentials.username)
            .addQueryParameter("password", credentials.password)
            .apply { if (action != null) addQueryParameter("action", action) }.build()

    private class Payload(val body: String, val date: String?)

    private suspend fun request(builder: Request.Builder, limit: Int = 65536): Payload = suspendCancellableCoroutine { continuation ->
        val call = http.newCall(builder.header("Accept", "application/json").header("Cache-Control", "no-store")
            .header("User-Agent", "AM-Account/0.2").build())
        continuation.invokeOnCancellation { call.cancel() }
        call.enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                if (continuation.isActive) continuation.resumeWithException(AccountFailure(FailureKind.UNAVAILABLE))
            }
            override fun onResponse(call: Call, response: Response) {
                try {
                    val payload = response.use {
                        when (it.code) {
                            401 -> throw AccountFailure(FailureKind.UNAUTHORIZED)
                            403 -> throw AccountFailure(FailureKind.RESTRICTED)
                            429 -> throw AccountFailure(FailureKind.RATE_LIMITED)
                        }
                        if (!it.isSuccessful) throw AccountFailure(FailureKind.UNAVAILABLE)
                        val source = it.body?.source() ?: throw AccountFailure(FailureKind.INVALID_RESPONSE)
                        source.request(limit.toLong() + 1)
                        if (source.buffer.size > limit) throw AccountFailure(FailureKind.INVALID_RESPONSE)
                        Payload(source.readUtf8(), it.header("Date"))
                    }
                    if (continuation.isActive) continuation.resume(payload)
                } catch (error: Exception) {
                    if (continuation.isActive) continuation.resumeWithException(
                        if (error is AccountFailure) error else AccountFailure(FailureKind.UNAVAILABLE))
                }
            }
        })
    }

    private inline fun <T> parse(block: () -> T): T = try { block() }
    catch (error: AccountFailure) { throw error }
    catch (_: Exception) { throw AccountFailure(FailureKind.INVALID_RESPONSE) }
}
