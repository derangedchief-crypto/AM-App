package net.extremeiptv.account.data

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class ManagedAccountClientTest {
    private val offer = """{"package_id":1,"name":"Monthly","amount_minor":725,"currency":"CAD","duration":30,"duration_period":"days","max_connections":2}"""
    private fun account() = """{"service":{"username":"customer","package_id":1,"package_name":"Monthly","expire_at":1900000000,"activated":true,"max_connections":2,"is_trial":false,"bouquets":[{"name":"News","selected":true},{"name":"Sports","selected":false}]},"provider":"Reseller","support_email":"support@example.com","renewal_mode":"live","offers":[$offer],"server_time":1800000000}"""
    private fun order(url: String = "https://square.link/test", state: String = "pending") = """{"ok":true,"order":{"id":"${"a".repeat(32)}","package_name":"Monthly","amount_minor":725,"currency":"CAD","mode":"live","state":"$state","payment_verified":false,"created_at":1800000000,"checkout_url":"$url"}}"""
    private fun MockWebServer.reply(body: String) = enqueue(MockResponse().setHeader("Content-Type", "application/json").setBody(body))

    @Test fun `managed login quote checkout and status use scoped bearer API only`() = runBlocking {
        MockWebServer().use { server ->
            server.start()
            val client = ManagedAccountClient(server.url("/customer-api.php"))
            server.reply("""{"ok":true,"token":"${"b".repeat(64)}","account":${account()}}""")
            val account = client.readAccount(ServiceCredentials("customer", "secret"))
            assertEquals(listOf("News"), account.bouquets)
            assertEquals("Reseller", account.serviceDisplayName)
            assertEquals("Extreme TV", account.copy(providerName="Extreme TV",packageName=null).serviceDisplayName)
            val login = server.takeRequest(); assertNull(login.getHeader("Authorization"))
            assertEquals("login", JSONObject(login.body.readUtf8()).getString("action"))
            server.reply("""{"ok":true,"quote":{"quote_id":"${"c".repeat(32)}","package_name":"Monthly","amount_minor":725,"currency":"CAD","mode":"live","duration":30,"duration_period":"days","max_connections":2}}""")
            val quote = client.quote(account.offers.single())
            val request = server.takeRequest()
            assertEquals("Bearer ${"b".repeat(64)}", request.getHeader("Authorization"))
            val body = JSONObject(request.body.readUtf8())
            assertFalse(body.has("reseller_id")); assertFalse(body.has("amount_minor"))
            assertEquals("7.25 CAD", quote.offer.price)
            server.reply(order()); val order = client.checkout(quote)
            assertEquals(quote.id, JSONObject(server.takeRequest().body.readUtf8()).getString("quote_id"))
            assertFalse(order.verified); assertFalse(order.complete)
            server.reply(order()); client.order(order.id)
            assertEquals(order.id, JSONObject(server.takeRequest().body.readUtf8()).getString("order_id"))
            server.reply("{\"ok\":true}")
            client.clearSession()
            assertEquals("logout", JSONObject(server.takeRequest().body.readUtf8()).getString("action"))
            try { client.orders(); fail("Cleared session must reject") } catch (e: AccountFailure) { assertEquals(FailureKind.UNAUTHORIZED, e.kind) }
        }
    }

    @Test fun `activation must pass AM access checks and cannot bypass a denial`() = runBlocking {
        MockWebServer().use { server ->
            server.start()
            val resolver = object : AccountClient {
                override suspend fun resolveCode(code: String): ServiceCredentials = error("Must not bypass access denial")
                override suspend fun readAccount(credentials: ServiceCredentials): ServiceAccount = error("Never read direct provider")
            }
            val client = ManagedAccountClient(server.url("/customer-api.php"), resolver)
            server.enqueue(MockResponse().setResponseCode(403).setBody("PRIVATE_SENTINEL"))
            try { client.resolveCode("123456"); fail("Must deny") }
            catch (e: AccountFailure) { assertEquals(FailureKind.RESTRICTED, e.kind); assertFalse(e.message!!.contains("PRIVATE_SENTINEL")) }
            assertEquals(1, server.requestCount)
        }
    }

    @Test fun `untrusted checkout and sandbox URL in live order are rejected`() = runBlocking {
        MockWebServer().use { server ->
            server.start(); val client = ManagedAccountClient(server.url("/customer-api.php"))
            server.reply("""{"ok":true,"token":"${"b".repeat(64)}","account":${account()}}""")
            client.readAccount(ServiceCredentials("customer", "secret"))
            for (url in listOf("https://evil.example/pay", "http://square.link/pay", "https://sandbox.square.link/pay", "https://user:pass@square.link/pay")) {
                server.reply(order(url))
                try { client.order("a".repeat(32)); fail("Unsafe link accepted") }
                catch (e: AccountFailure) { assertEquals(FailureKind.INVALID_RESPONSE, e.kind) }
            }
        }
    }

    @Test fun `bouquet save sends selection revision without tenant or payment data`() = runBlocking {
        MockWebServer().use { server ->
            server.start(); val client = ManagedAccountClient(server.url("/customer-api.php"))
            val data = JSONObject(account()).put("allow_bouquets",true)
            data.getJSONObject("service").put("bouquet_revision","d".repeat(64))
                .put("bouquets",org.json.JSONArray("""[{"id":1,"name":"News","selected":true,"available":true},{"id":2,"name":"Sports","selected":false,"available":true}]"""))
            server.reply(JSONObject().put("ok",true).put("token","b".repeat(64)).put("account",data).toString())
            val initial=client.readAccount(ServiceCredentials("customer","secret")); server.takeRequest()
            assertTrue(initial.canEditBouquets); assertEquals(2,initial.bouquetChoices.size)
            server.reply(JSONObject().put("ok",true).put("account",data).put("refresh_pending",true).toString())
            val saved=client.saveBouquets(setOf(2),initial.bouquetRevision)
            assertTrue(saved.refreshPending)
            val body=JSONObject(server.takeRequest().body.readUtf8())
            assertEquals("bouquets",body.getString("action")); assertEquals(2,body.getJSONArray("bouquet_ids").getInt(0))
            assertEquals(initial.bouquetRevision,body.getString("revision"))
            assertFalse(body.has("reseller_id")); assertFalse(body.has("amount_minor"))
        }
    }

    @Test fun `unavailable renewal preserves specific server explanation`() = runBlocking {
        MockWebServer().use { server ->
            server.start(); val client=ManagedAccountClient(server.url("/customer-api.php"))
            val data=JSONObject(account()).put("offers",org.json.JSONArray())
                .put("renewal_unavailable_message","Square checkout needs a matching sandbox CAD connection with its webhook signature key saved.")
            server.reply(JSONObject().put("ok",true).put("token","b".repeat(64)).put("account",data).toString())
            val result=client.readAccount(ServiceCredentials("customer","secret"))
            assertTrue(result.offers.isEmpty())
            assertEquals(data.getString("renewal_unavailable_message"),result.renewalUnavailableMessage)
        }
    }
}
