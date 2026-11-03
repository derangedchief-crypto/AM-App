package net.extremeiptv.account

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.*
import kotlinx.coroutines.withContext
import net.extremeiptv.account.data.*
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class AccountViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    @Before fun setup() { Dispatchers.setMain(dispatcher) }
    @After fun cleanup() { Dispatchers.resetMain() }
    private fun account() = ServiceAccount("customer", ServiceStatus.ACTIVE, 1900000000, false, 2, 0, false, null, null)
    private class FakeClient(var read: suspend () -> ServiceAccount) : AccountClient {
        var requests = 0
        override suspend fun resolveCode(code: String) = ServiceCredentials("customer", "secret")
        override suspend fun readAccount(credentials: ServiceCredentials): ServiceAccount { requests++; return read() }
    }

    @Test fun `late login completion cannot resurrect signed out session`() = runTest(dispatcher) {
        val result = CompletableDeferred<ServiceAccount>()
        val client = FakeClient { withContext(NonCancellable) { result.await() } }
        val model = AccountViewModel(client)
        model.signIn("123456", "", "", true); runCurrent()
        model.signOut()
        result.complete(account()); advanceUntilIdle()
        assertEquals(LiveAccountState(), model.state.value)
    }

    @Test fun `refresh outage marks data stale and retry recovers`() = runTest(dispatcher) {
        val client = FakeClient { account() }; val model = AccountViewModel(client)
        model.signIn("", "customer", "secret", false); advanceUntilIdle()
        client.read = { throw AccountFailure(FailureKind.UNAVAILABLE) }
        model.refresh(); advanceUntilIdle()
        assertNotNull(model.state.value.account); assertTrue(model.state.value.stale)
        assertNotNull(model.state.value.error); assertFalse(model.state.value.busy)
        client.read = { account() }; model.refresh(); advanceUntilIdle()
        assertFalse(model.state.value.stale); assertNull(model.state.value.error)
    }

    @Test fun `refresh denial clears account and prevents further refresh`() = runTest(dispatcher) {
        val client = FakeClient { account() }; val model = AccountViewModel(client)
        model.signIn("", "customer", "secret", false); advanceUntilIdle()
        client.read = { throw AccountFailure(FailureKind.UNAUTHORIZED) }
        model.refresh(); advanceUntilIdle()
        assertNull(model.state.value.account)
        model.refresh(); advanceUntilIdle(); assertEquals(2, client.requests)
    }

    @Test fun `duplicate submission cannot start parallel account requests`() = runTest(dispatcher) {
        val result = CompletableDeferred<ServiceAccount>()
        val client = FakeClient { result.await() }; val model = AccountViewModel(client)
        model.signIn("123456", "", "", true); runCurrent()
        model.signIn("654321", "", "", true); runCurrent()
        assertEquals(1, client.requests)
        result.complete(account()); advanceUntilIdle()
        model.signOut(); assertNull(model.state.value.account)
    }
}
