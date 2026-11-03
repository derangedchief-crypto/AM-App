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
)

/** Session exists only in this app's memory; no password or activation code is persisted. */
internal class AccountViewModel(private val client: AccountClient) : ViewModel() {
    constructor() : this(ServiceAccountClient())
    private val mutableState = MutableStateFlow(LiveAccountState())
    val state = mutableState.asStateFlow()
    private var credentials: ServiceCredentials? = null
    private var operation: Job? = null
    private var generation = 0

    fun signIn(code: String, username: String, password: String, useCode: Boolean) {
        if (mutableState.value.busy) return
        credentials = null
        val revision = ++generation
        mutableState.value = LiveAccountState(busy = true)
        operation = viewModelScope.launch {
            try {
                val login = if (useCode) client.resolveCode(code.trim()) else ServiceCredentials(username.trim(), password)
                val account = client.readAccount(login)
                if (revision != generation) return@launch
                credentials = login
                mutableState.value = LiveAccountState(account = account)
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                if (revision == generation) mutableState.value = LiveAccountState(error = safeMessage(error))
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
                if (revision == generation) mutableState.value = LiveAccountState(account = account)
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                if (revision != generation) return@launch
                if (error is AccountFailure && error.kind in setOf(FailureKind.UNAUTHORIZED, FailureKind.RESTRICTED)) {
                    credentials = null
                    mutableState.value = LiveAccountState(error = safeMessage(error))
                } else mutableState.value = mutableState.value.copy(busy = false, error = safeMessage(error), stale = true)
            }
        }
    }

    fun signOut() {
        generation++
        operation?.cancel()
        operation = null
        credentials = null
        mutableState.value = LiveAccountState()
    }

    override fun onCleared() { credentials = null; super.onCleared() }

    private fun safeMessage(error: Exception) =
        (error as? AccountFailure)?.message ?: "Unable to load your account. Please try again."
}
