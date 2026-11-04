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
        val model = AccountViewModel(client, TestLoginStore())
        model.signIn("123456", "", "", true); runCurrent()
        model.signOut()
        result.complete(account()); advanceUntilIdle()
        assertEquals(LiveAccountState(), model.state.value)
    }

    @Test fun `refresh outage marks data stale and retry recovers`() = runTest(dispatcher) {
        val client = FakeClient { account() }; val model = AccountViewModel(client, TestLoginStore())
        model.signIn("", "customer", "secret", false); advanceUntilIdle()
        client.read = { throw AccountFailure(FailureKind.UNAVAILABLE) }
        model.refresh(); advanceUntilIdle()
        assertNotNull(model.state.value.account); assertTrue(model.state.value.stale)
        assertNotNull(model.state.value.error); assertFalse(model.state.value.busy)
        client.read = { account() }; model.refresh(); advanceUntilIdle()
        assertFalse(model.state.value.stale); assertNull(model.state.value.error)
    }

    @Test fun `refresh denial clears account and prevents further refresh`() = runTest(dispatcher) {
        val client = FakeClient { account() }; val model = AccountViewModel(client, TestLoginStore())
        model.signIn("", "customer", "secret", false); advanceUntilIdle()
        client.read = { throw AccountFailure(FailureKind.UNAUTHORIZED) }
        model.refresh(); advanceUntilIdle()
        assertNull(model.state.value.account)
        model.refresh(); advanceUntilIdle(); assertEquals(2, client.requests)
    }

    @Test fun `duplicate submission cannot start parallel account requests`() = runTest(dispatcher) {
        val result = CompletableDeferred<ServiceAccount>()
        val client = FakeClient { result.await() }; val model = AccountViewModel(client, TestLoginStore())
        model.signIn("123456", "", "", true); runCurrent()
        model.signIn("654321", "", "", true); runCurrent()
        assertEquals(1, client.requests)
        result.complete(account()); advanceUntilIdle()
        model.signOut(); assertNull(model.state.value.account)
    }

    @Test fun `successful code login is restored by a new view model`() = runTest(dispatcher) {
        val store = TestLoginStore()
        val client = FakeClient { account() }
        val first = AccountViewModel(client, store)
        first.signIn("123456", "", "", true); advanceUntilIdle()
        assertEquals("123456", store.saved?.code)
        assertNull(store.saved?.password) // Don't save credentials returned by code resolution.
        val restarted = AccountViewModel(client, store)
        assertTrue(restarted.state.value.restoring)
        assertNull(restarted.state.value.account) // Saved bytes alone cannot authorize access.
        advanceUntilIdle()
        assertEquals("customer", restarted.state.value.account?.username)
        assertTrue(restarted.state.value.remembered)
        assertEquals(2, client.requests)
    }

    @Test fun `restart outage retains saved credentials and retry restores account`() = runTest(dispatcher) {
        val login = SavedLogin(null, "customer", "secret")
        val store = TestLoginStore(login)
        val client = FakeClient { throw AccountFailure(FailureKind.UNAVAILABLE) }
        val model = AccountViewModel(client, store); advanceUntilIdle()
        assertSame(login, store.saved)
        assertNull(model.state.value.account)
        assertTrue(model.state.value.canRetrySavedLogin)
        client.read = { account() }; model.restoreSavedLogin(); advanceUntilIdle()
        assertNotNull(model.state.value.account)
        assertFalse(model.state.value.canRetrySavedLogin)
    }

    @Test fun `rejected saved login is removed rather than repeatedly restored`() = runTest(dispatcher) {
        val store = TestLoginStore(SavedLogin(null, "customer", "secret"))
        val model = AccountViewModel(FakeClient { throw AccountFailure(FailureKind.UNAUTHORIZED) }, store)
        advanceUntilIdle()
        assertNull(store.saved); assertNull(model.state.value.account)
        assertFalse(model.state.value.canRetrySavedLogin)
    }

    @Test fun `sign out during restore removes saved login and rejects late completion`() = runTest(dispatcher) {
        val store = TestLoginStore(SavedLogin("123456", null, null))
        val result = CompletableDeferred<ServiceAccount>()
        val model = AccountViewModel(FakeClient { withContext(NonCancellable) { result.await() } }, store)
        runCurrent(); model.signOut(); result.complete(account()); advanceUntilIdle()
        assertNull(store.saved); assertEquals(LiveAccountState(), model.state.value)
    }

    @Test fun `only verified logins are saved and storage failure is visible`() = runTest(dispatcher) {
        val store = TestLoginStore()
        val client = FakeClient { throw AccountFailure(FailureKind.UNAUTHORIZED) }
        val model = AccountViewModel(client, store)
        model.signIn("", "customer", "wrong", false); advanceUntilIdle(); assertNull(store.saved)
        client.read = { account() }; store.failSave = true
        model.signIn("", "customer", "secret", false); advanceUntilIdle()
        assertNotNull(model.state.value.account); assertFalse(model.state.value.remembered)
        assertNotNull(model.state.value.error)
    }

    @Test fun `unreadable stored login is cleared and asks for sign in`() = runTest(dispatcher) {
        val store = TestLoginStore(SavedLogin("123456", null, null)).apply { failLoad = true }
        val client = FakeClient { account() }
        val model = AccountViewModel(client, store)
        assertNull(store.saved); assertNotNull(model.state.value.error); assertEquals(0, client.requests)
    }

    @Test fun `failed sign out does not falsely claim saved login was removed`() = runTest(dispatcher) {
        val store = TestLoginStore(SavedLogin(null, "customer", "secret"))
        val model = AccountViewModel(FakeClient { account() }, store); advanceUntilIdle()
        store.failClear = true; model.signOut()
        assertNotNull(model.state.value.account); assertNotNull(model.state.value.error); assertNotNull(store.saved)
        store.failClear = false; model.signOut()
        assertNull(store.saved); assertNull(model.state.value.account)
    }
}

private class TestLoginStore(var saved: SavedLogin? = null) : LoginStore {
    var failSave = false
    var failLoad = false
    var failClear = false
    override fun load(): SavedLogin? { check(!failLoad); return saved }
    override fun save(login: SavedLogin) { check(!failSave); saved = login }
    override fun clear() { check(!failClear); saved = null }
}
