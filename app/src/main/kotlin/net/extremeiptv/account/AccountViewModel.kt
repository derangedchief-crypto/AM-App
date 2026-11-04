package net.extremeiptv.account

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
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
        credentials = null
        val revision = ++generation
        mutableState.value = LiveAccountState(busy = true, restoring = restoring)
        operation = viewModelScope.launch {
            try {
                val resolved = login.resolve(client)
                val account = client.readAccount(resolved)
                if (revision != generation) return@launch
                // These tiny atomic store operations do not suspend, so sign-out cannot
                // interleave between the generation check and persisting a late result.
                val saved = restoring || runCatching { store.save(login) }.isSuccess
                credentials = resolved
                mutableState.value = LiveAccountState(account = account, remembered = saved,
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
                val account = client.readAccount(login)
                if (revision == generation) mutableState.value = LiveAccountState(account = account, remembered = mutableState.value.remembered)
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
        mutableState.value = LiveAccountState()
    }

    override fun onCleared() { credentials = null; super.onCleared() }

    private fun safeMessage(error: Exception) =
        (error as? AccountFailure)?.message ?: "Unable to load your account. Please try again."
}
