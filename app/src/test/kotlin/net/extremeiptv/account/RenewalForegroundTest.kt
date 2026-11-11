package net.extremeiptv.account

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import kotlinx.coroutines.CompletableDeferred
import net.extremeiptv.account.data.*
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RenewalForegroundTest {
    @get:Rule val compose = createComposeRule()

    @Test fun quoteAppearsAfterDialogDismissalWithoutSleepingPhone() {
        val offer = RenewalOffer(1,"Premium",2000,"CAD",1,"months",2,
            paymentOptions = listOf(PaymentOption("square","live")))
        val reply = CompletableDeferred<RenewalQuote>()
        val checkout = CompletableDeferred<RenewalOrder>()
        val client = object : AccountClient {
            override suspend fun resolveCode(code: String) = ServiceCredentials("fixture", "secret")
            override suspend fun readAccount(credentials: ServiceCredentials) = ServiceAccount(
                "fixture",ServiceStatus.ACTIVE,1900000000,false,2,0,false,"Premium",null,
                offers = listOf(offer), renewalMode = "live")
            override suspend fun quote(offer: RenewalOffer) = reply.await()
            override suspend fun checkout(quote: RenewalQuote) = checkout.await()
        }
        lateinit var model: AccountViewModel
        compose.runOnUiThread {
            model = AccountViewModel(client, object : LoginStore {
                override fun load(): SavedLogin? = null
                override fun save(login: SavedLogin) {}
                override fun clear() {}
            })
            model.signIn("", "fixture", "secret", false)
        }
        compose.setContent { AccountTheme { LiveAccountSession(model, {}, {}) } }
        compose.onNodeWithText("Services", useUnmergedTree = true).performClick()
        compose.onNodeWithText("Renew / add time").performScrollTo().performClick()
        compose.onNodeWithText("Review renewal · 20.00 CAD").performScrollTo().performClick()
        compose.onNodeWithText("Payment method").assertIsDisplayed()
        compose.onNodeWithText("Review renewal", substring = false).performClick()
        compose.onNodeWithText("Payment method").assertDoesNotExist()
        compose.runOnUiThread { reply.complete(RenewalQuote("a".repeat(32), offer, "live")) }
        compose.waitUntil(5_000) { model.state.value.quote != null && !model.state.value.busy }
        compose.waitForIdle()
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Confirm renewal").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Confirm renewal").performScrollTo().assertIsDisplayed().assertIsEnabled()
        compose.onNodeWithText("Confirm renewal").performClick()
        compose.runOnUiThread { checkout.complete(RenewalOrder("b".repeat(32), "Premium", 2000, "CAD", "live",
            "pending", false, 1800000000, "https://square.link/u/fixture")) }
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Open secure Square checkout").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Open secure Square checkout").performScrollTo().assertIsDisplayed().assertIsEnabled()
    }
}
