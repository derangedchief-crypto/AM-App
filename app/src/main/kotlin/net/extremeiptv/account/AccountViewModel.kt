package net.extremeiptv.account

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import net.extremeiptv.account.data.*

internal data class LiveAccountState(
    val account: ServiceAccount? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val stale: Boolean = false,
    val restoring: Boolean = false,
    val canRetrySavedLogin: Boolean = false,
    val remembered: Boolean = false,
    val orders: List<RenewalOrder> = emptyList(),
    val quote: RenewalQuote? = null,
    val selectedOrder: RenewalOrder? = null,
    val bouquetMessage: String? = null,
    val invoiceRevision: Int = 0,
)

/** Saved login is encrypted; account access still requires fresh provider verification. */
internal class AccountViewModel(private val client: AccountClient, private val store: LoginStore) : ViewModel() {
    private val mutableState = MutableStateFlow(LiveAccountState())
    val state = mutableState.asStateFlow()
    private var credentials: ServiceCredentials? = null
    private var operation: Job? = null
    private var generation = 0

    init { restoreSavedLogin() }

    fun restoreSavedLogin() {
        if (mutableState.value.busy) return
        val login = try { store.load() } catch (_: Exception) {
            val cleared = runCatching { store.clear() }.isSuccess
            mutableState.value = LiveAccountState(error = if (cleared)
                "Your saved login could not be opened. Please sign in again."
                else "Unable to open or remove your saved login. Clear this app's storage in Android settings, then sign in again.")
            return
        } ?: return
        authenticate(login, restoring = true)
    }

    fun signIn(code: String, username: String, password: String, useCode: Boolean) {
        if (mutableState.value.busy) return
        val login = try {
            if (useCode) SavedLogin(code.trim(), null, null) else SavedLogin(null, username.trim(), password)
        } catch (_: IllegalArgumentException) {
            mutableState.value = LiveAccountState(error = AccountFailure(FailureKind.INVALID_INPUT).message)
            return
        }
        authenticate(login, restoring = false)
    }

    private fun authenticate(login: SavedLogin, restoring: Boolean) {
        client.clearSession()
        credentials = null
        val revision = ++generation
        mutableState.value = LiveAccountState(busy = true, restoring = restoring)
        operation = viewModelScope.launch {
            try {
                val resolved = login.resolve(client)
                val account = client.readAccount(resolved)
                val orders = client.orders()
                if (revision != generation) return@launch
                // These tiny atomic store operations do not suspend, so sign-out cannot
                // interleave between the generation check and persisting a late result.
                val saved = restoring || runCatching { store.save(login) }.isSuccess
                credentials = resolved
                mutableState.value = LiveAccountState(account = account, remembered = saved, orders = orders,
                    selectedOrder = orders.firstOrNull { !it.complete },
                    error = if (saved) null else "Signed in, but this device could not save your login. You may need to sign in again after closing the app.")
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                if (revision != generation) return@launch
                val denied = error is AccountFailure && error.kind in setOf(FailureKind.UNAUTHORIZED, FailureKind.RESTRICTED)
                if (denied) runCatching { store.clear() }
                mutableState.value = LiveAccountState(error = safeMessage(error), canRetrySavedLogin = restoring && !denied)
            }
        }
    }

    fun refresh() {
        val login = credentials ?: return
        if (mutableState.value.busy) return
        val revision = generation
        mutableState.value = mutableState.value.copy(busy = true, error = null)
        operation = viewModelScope.launch {
            try {
                val orders = client.orders()
                val pending = orders.firstOrNull { !it.complete }
                val checked = pending?.let { client.order(it.id) }
                val account = client.readAccount(login)
                if (revision == generation) mutableState.value = mutableState.value.copy(account = account, busy = false,
                    stale = false, orders = orders.map { if (it.id == checked?.id) checked else it },
                    selectedOrder = checked ?: mutableState.value.selectedOrder)
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                if (revision != generation) return@launch
                if (error is AccountFailure && error.kind in setOf(FailureKind.UNAUTHORIZED, FailureKind.RESTRICTED)) {
                    credentials = null
                    runCatching { store.clear() }
                    mutableState.value = LiveAccountState(error = safeMessage(error))
                } else mutableState.value = mutableState.value.copy(busy = false, error = safeMessage(error), stale = true)
            }
        }
    }

    fun signOut() {
        generation++
        operation?.cancel()
        operation = null
        if (runCatching { store.clear() }.isFailure) {
            mutableState.value = mutableState.value.copy(busy = false, restoring = false,
                error = "Unable to remove your saved login. Try signing out again, or clear this app's storage in Android settings.")
            return
        }
        credentials = null
        client.clearSession()
        mutableState.value = LiveAccountState()
    }

    fun requestQuote(offer: RenewalOffer) = renewalOperation {
        val outstanding = client.orders().firstOrNull { !it.complete }
        if (outstanding != null) {
            val order = client.order(outstanding.id)
            currentCoroutineContext().ensureActive()
            showOrder(order)
        } else {
            val quote = client.quote(offer)
            currentCoroutineContext().ensureActive()
            mutableState.value = mutableState.value.copy(quote = quote, selectedOrder = null)
        }
    }

    fun dismissQuote() { if (!mutableState.value.busy) mutableState.value = mutableState.value.copy(quote = null) }

    fun confirmRenewal() {
        val quote = mutableState.value.quote ?: return
        renewalOperation {
            val order = client.checkout(quote)
            currentCoroutineContext().ensureActive()
            showOrder(order)
            if (order.verified || order.complete) reloadAfterOrder()
        }
    }

    fun checkOrder(id: String) = renewalOperation {
        val order = client.order(id)
        currentCoroutineContext().ensureActive()
        showOrder(order)
        if (order.verified || order.complete) reloadAfterOrder()
    }

    fun saveBouquets(ids: Set<Int>, revision: String) = renewalOperation {
        val result = client.saveBouquets(ids, revision)
        currentCoroutineContext().ensureActive()
        mutableState.value = mutableState.value.copy(account = result.account, stale = false,
            bouquetMessage = if (result.refreshPending) "Bouquets saved. Service refresh is pending; tap Save bouquets again to retry."
                else "Bouquets saved and service refreshed. No charge.")
    }

    private fun showOrder(order: RenewalOrder) {
        mutableState.value = mutableState.value.copy(selectedOrder = order, quote = null,
            orders = listOf(order) + mutableState.value.orders.filterNot { it.id == order.id },
            invoiceRevision = mutableState.value.invoiceRevision + 1)
    }

    private suspend fun reloadAfterOrder() {
        val orders = client.orders()
        val account = client.readAccount(credentials ?: throw AccountFailure(FailureKind.UNAUTHORIZED))
        currentCoroutineContext().ensureActive()
        mutableState.value = mutableState.value.copy(orders = orders, account = account, stale = false)
    }

    private fun renewalOperation(action: suspend () -> Unit) {
        if (mutableState.value.busy || credentials == null) return
        val revision = generation
        mutableState.value = mutableState.value.copy(busy = true, error = null)
        operation = viewModelScope.launch {
            try { action() }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                if (revision != generation) return@launch
                if (error is AccountFailure && error.kind in setOf(FailureKind.UNAUTHORIZED, FailureKind.RESTRICTED)) {
                    credentials = null; client.clearSession(); runCatching { store.clear() }
                    mutableState.value = LiveAccountState(error = safeMessage(error))
                } else mutableState.value = mutableState.value.copy(error = safeMessage(error))
            } finally {
                if (revision == generation) mutableState.value = mutableState.value.copy(busy = false)
            }
        }
    }

    override fun onCleared() { credentials = null; client.clearSession(); super.onCleared() }

    private fun safeMessage(error: Exception) =
        (error as? AccountFailure)?.message ?: "Unable to load your account. Please try again."
}
