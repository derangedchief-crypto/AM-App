package net.extremeiptv.account.data

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import java.util.concurrent.TimeUnit

class ServiceAccountClientTest {
    private val server = MockWebServer()
    private lateinit var client: ServiceAccountClient
    @Before fun setup() {
        server.start()
        client = ServiceAccountClient(server.url("/"), server.url("/activate"))
    }
    @After fun cleanup() { server.shutdown() }
    private fun respond(body: String, status: Int = 200) {
        server.enqueue(MockResponse().setResponseCode(status).setHeader("Content-Type", "application/json").setBody(body))
    }
    private fun profile(username: String = "customer", status: String = "Active", expiry: Any = "1900000000"): String =
        JSONObject().put("user_info", JSONObject().put("auth", 1).put("username", username).put("status", status)
            .put("exp_date", expiry).put("max_connections", "3").put("active_cons", "1").put("is_trial", "0"))
            .put("server_info", JSONObject().put("timestamp_now", 1800000000)).toString()

    @Test fun `activation uses Hub protocol then fetches only account and channel groups`() = runBlocking {
        respond("""{"ok":true,"username":"customer","password":"secret"}""")
        respond(profile())
        respond("""[{"category_name":"Sports"},{"category_name":"News"}]""")
        val credentials = client.resolveCode("123456")
        val account = client.readAccount(credentials)
        assertEquals(3, account.maxConnections)
        assertEquals(1, account.activeConnections)
        assertEquals(listOf("Sports", "News"), account.categories)
        assertNull(account.packageName)
        val activate = server.takeRequest(1, TimeUnit.SECONDS)!!
        assertEquals("POST", activate.method)
        assertEquals("/activate", activate.path)
        assertEquals("123456", JSONObject(activate.body.readUtf8()).getString("activation_code"))
        val accountRequest = server.takeRequest(1, TimeUnit.SECONDS)!!
        assertEquals("GET", accountRequest.method)
        assertEquals("no-store", accountRequest.getHeader("Cache-Control"))
        assertNull(accountRequest.requestUrl!!.queryParameter("action"))
        assertEquals("get_live_categories", server.takeRequest(1, TimeUnit.SECONDS)!!.requestUrl!!.queryParameter("action"))
    }

    @Test fun `credentials are URL encoded and password whitespace is preserved`() = runBlocking {
        val user = "a&name+/?"
        val pass = " p&ss+=?# "
        respond(profile(user)); respond("[]")
        client.readAccount(ServiceCredentials(user, pass))
        val url = server.takeRequest(1, TimeUnit.SECONDS)!!.requestUrl!!
        assertEquals(user, url.queryParameter("username"))
        assertEquals(pass, url.queryParameter("password"))
        assertEquals(2, url.querySize)
        assertEquals("ServiceCredentials([redacted])", ServiceCredentials(user, pass).toString())
    }

    @Test fun `unauthorized responses cannot create an account`() = runBlocking {
        respond("""{"user_info":{"auth":0,"status":"Active"}}""")
        assertFailure(FailureKind.UNAUTHORIZED) { client.readAccount(ServiceCredentials("customer", "secret")) }
        assertEquals(1, server.requestCount)
    }

    @Test fun `a different echoed account cannot be displayed`() = runBlocking {
        respond(profile("somebody-else"))
        assertFailure(FailureKind.INVALID_RESPONSE) { client.readAccount(ServiceCredentials("customer", "secret")) }
    }

    @Test fun `redirects are refused without forwarding credentials`() = runBlocking {
        respond("", 302)
        assertFailure(FailureKind.UNAVAILABLE) { client.readAccount(ServiceCredentials("customer", "secret")) }
        assertEquals(1, server.requestCount)
    }

    @Test fun `HTML and oversized responses are rejected without leaking response contents`() = runBlocking {
        respond("<html>secret diagnostic page</html>")
        assertFailure(FailureKind.INVALID_RESPONSE) { client.readAccount(ServiceCredentials("customer", "secret")) }
        respond("x".repeat(65537))
        assertFailure(FailureKind.INVALID_RESPONSE) { client.readAccount(ServiceCredentials("customer", "secret")) }
    }

    @Test fun `rate limiting has a distinct safe error`() = runBlocking {
        respond("secret upstream message", 429)
        assertFailure(FailureKind.RATE_LIMITED) { client.resolveCode("123456") }
    }

    @Test fun `invalid activation input never sends a request`() = runBlocking {
        assertFailure(FailureKind.INVALID_INPUT) { client.resolveCode("12345x") }
        assertEquals(0, server.requestCount)
    }

    @Test fun `expired authenticated account stays read only and does not fetch channels`() = runBlocking {
        respond(profile(status = "Expired"))
        val account = client.readAccount(ServiceCredentials("customer", "secret"))
        assertEquals(ServiceStatus.EXPIRED, account.status)
        assertTrue(account.categories.isEmpty())
        assertEquals(1, server.requestCount)
    }

    @Test fun `category outage does not fabricate categories or fail verified login`() = runBlocking {
        respond(profile()); respond("unavailable", 503)
        val account = client.readAccount(ServiceCredentials("customer", "secret"))
        assertTrue(account.categories.isEmpty())
        assertNotNull(account.categoryWarning)
    }

    @Test fun `revocation during category fetch invalidates login`() = runBlocking {
        respond(profile()); respond("unauthorized", 401)
        assertFailure(FailureKind.UNAUTHORIZED) { client.readAccount(ServiceCredentials("customer", "secret")) }
    }

    @Test fun `missing auth and unknown status fail closed`() {
        listOf("""{"user_info":{"status":"Active"}}""", """{"user_info":{"auth":1,"status":"Unknown"}}""").forEach {
            try { AccountParser.account(JSONObject(it), "customer", null); fail("Must reject") }
            catch (expected: AccountFailure) { assertEquals(FailureKind.INVALID_RESPONSE, expected.kind) }
        }
    }

    @Test fun `server time expiry and unlimited distinction match provider semantics`() {
        val expired = AccountParser.account(JSONObject(profile(expiry = "1700000000")), "customer", null)
        assertEquals(ServiceStatus.EXPIRED, expired.status)
        val unlimited = AccountParser.account(JSONObject(profile(expiry = JSONObject.NULL)), "customer", null)
        assertTrue(unlimited.unlimited); assertNull(unlimited.expiresAt)
        val missing = JSONObject(profile()).apply { getJSONObject("user_info").remove("exp_date") }
        val unknown = AccountParser.account(missing, "customer", null)
        assertFalse(unknown.unlimited); assertNull(unknown.expiresAt)
    }

    @Test fun `blocked accounts cannot sign in even with auth one`() {
        try { AccountParser.account(JSONObject(profile(status = "Banned")), "customer", null); fail("Must reject") }
        catch (expected: AccountFailure) { assertEquals(FailureKind.RESTRICTED, expected.kind) }
    }

    private suspend fun assertFailure(kind: FailureKind, block: suspend () -> Unit) {
        try { block(); fail("Expected $kind") }
        catch (expected: AccountFailure) {
            assertEquals(kind, expected.kind)
            assertFalse(expected.message.orEmpty().contains("secret"))
            assertNull(expected.cause)
        }
    }
}
