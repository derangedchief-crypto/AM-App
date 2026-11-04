package net.extremeiptv.account

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import net.extremeiptv.account.data.ServiceAccount
import net.extremeiptv.account.data.ServiceStatus
import net.extremeiptv.account.data.ServiceAccountClient
import net.extremeiptv.account.data.EncryptedLoginStore
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

@Composable
internal fun LiveAccountApp() = AccountTheme {
    val context = LocalContext.current.applicationContext
    val model: AccountViewModel = viewModel { AccountViewModel(ServiceAccountClient(), EncryptedLoginStore(context)) }
    val state by model.state.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner, model) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_START) model.refresh()
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }
    val account = state.account
    if (account == null) {
        if (state.restoring || state.canRetrySavedLogin) SavedLoginScreen(state, model::restoreSavedLogin, model::signOut)
        else LiveSignIn(state, model::signIn)
    } else {
        // Account changes/logouts dispose navigation and any visible private account details.
        key(account.username) { LiveDashboard(account, state, model::refresh, model::signOut) }
    }
}

@Composable
private fun LiveSignIn(state: LiveAccountState, onSignIn: (String, String, String, Boolean) -> Unit) {
    var method by rememberSaveable { mutableStateOf("Activation code") }
    // These inputs intentionally do not use rememberSaveable or SavedStateHandle.
    var code by remember { mutableStateOf("") }
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val useCode = method == "Activation code"
    val valid = if (useCode) code.matches(Regex("[0-9]{6}")) else username.isNotBlank() && password.isNotBlank()
    Column(Modifier.fillMaxSize().background(Ink).safeDrawingPadding().imePadding()
        .verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
        Brand()
        Spacer(Modifier.height(12.dp))
        Badge("LIVE ACCOUNT ACCESS")
        Heading("Your service.\nAll in one place.", "Sign in using your Hub activation code or existing service credentials.")
        if (!state.busy) Choices(listOf("Activation code", "Username & password"), method) {
            method = it; code = ""; username = ""; password = ""; visible = false
        }
        if (useCode) {
            OutlinedTextField(value = code, onValueChange = { code = it.filter { c -> c in '0'..'9' }.take(6) },
                enabled = !state.busy, label = { Text("Six-digit activation code") }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
        } else {
            OutlinedTextField(value = username, onValueChange = { username = it.take(256) },
                enabled = !state.busy, label = { Text("Service username") }, singleLine = true,
                keyboardOptions = KeyboardOptions(autoCorrect = false),
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
            OutlinedTextField(value = password, onValueChange = { password = it.take(512) },
                enabled = !state.busy, label = { Text("Service password") }, singleLine = true,
                visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                trailingIcon = { TextButton(onClick = { visible = !visible }) { Text(if (visible) "Hide" else "Show") } },
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
        }
        state.error?.let { ErrorNotice(it) }
        Button(enabled = valid && !state.busy, onClick = {
            onSignIn(code, username, password, useCode)
            code = ""; password = ""; visible = false
        }, modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp), shape = RoundedCornerShape(14.dp)) {
            if (state.busy) {
                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(12.dp))
            }
            Text(if (state.busy) "Verifying your service…" else "Sign in", fontWeight = FontWeight.Bold)
        }
        Hint("Uses the same service-provider login as Hub. AM Account keeps its own session; it does not sign you in or out of Hub.")
        Hint("Your login is remembered securely on this device. AM verifies it automatically when you reopen the app. Signing out removes the saved login.")
    }
}

@Composable
private fun SavedLoginScreen(state: LiveAccountState, retry: () -> Unit, signOut: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Ink).safeDrawingPadding().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(24.dp)) {
        Brand()
        Heading("Welcome back.", "Your login is saved. Checking your service with the provider.")
        if (state.busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        state.error?.let { ErrorNotice(it) }
        if (!state.busy) {
            Hint("Your saved login is still on this device. A temporary connection problem does not sign you out.")
            Primary("Retry saved login", retry)
        }
        Secondary("Use another account", signOut)
    }
}

@Composable
private fun LiveDashboard(account: ServiceAccount, state: LiveAccountState, refresh: () -> Unit, signOut: () -> Unit) {
    var tab by rememberSaveable { mutableStateOf("Home") }
    var showLogout by remember { mutableStateOf(false) }
    val scroll = key(tab) { rememberScrollState() }
    BackHandler(enabled = tab != "Home") { tab = "Home" }
    Scaffold(containerColor = Ink, bottomBar = {
        NavigationBar(containerColor = Ink) {
            listOf("Home", "Services", "Bills", "Account").forEach { item ->
                NavigationBarItem(selected = tab == item, onClick = { tab = item },
                    icon = { Icon(when (item) {
                        "Home" -> Icons.Default.Home
                        "Services" -> Icons.Default.Settings
                        "Bills" -> Icons.AutoMirrored.Filled.List
                        else -> Icons.Default.AccountCircle
                    }, contentDescription = null) }, label = { Text(item) },
                    colors = NavigationBarItemDefaults.colors(indicatorColor = Panel, selectedIconColor = Lime, selectedTextColor = Lime))
            }
        }
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).imePadding().verticalScroll(scroll)
            .padding(horizontal = 24.dp).padding(top = 16.dp, bottom = 28.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp)) {
            Brand()
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text(if (state.stale) "LAST VERIFIED ACCOUNT DATA" else "LIVE PROVIDER ACCOUNT", color = if (state.stale) Amber else Lime,
                    fontSize = 10.sp, letterSpacing = 1.sp, modifier = Modifier.weight(1f))
                TextButton(onClick = refresh, enabled = !state.busy) { Text(if (state.busy) "Refreshing…" else "Refresh") }
            }
            if (state.busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            state.error?.let { ErrorNotice(if (state.stale) "$it\nShowing your last verified account details." else it) }
            when (tab) {
                "Home" -> {
                    Heading("Everything,\nin your control.", "Welcome, ${account.username}.")
                    LiveServiceCard(account)
                    BoxCard {
                        Label("ACCOUNT DETAILS")
                        Detail("Expires", account.expiryText())
                        Detail("Connection limit", account.maxConnections?.toString() ?: "Not provided")
                        Detail("Currently connected", account.activeConnections?.toString() ?: "Not provided")
                    }
                    Primary("View my service") { tab = "Services" }
                    BillingUnavailable()
                }
                "Services" -> {
                    Heading("Your service", "Verified details from your service provider.")
                    LiveServiceCard(account)
                    BoxCard {
                        Label("SERVICE DETAILS")
                        Detail("Username", account.username)
                        Detail("Package", account.packageName ?: "Not provided by provider")
                        Detail("Status", account.status.label)
                        Detail("Expires", account.expiryText())
                        Detail("Connections", account.maxConnections?.toString() ?: "Not provided")
                        Detail("Currently connected", account.activeConnections?.toString() ?: "Not provided")
                        Detail("Trial account", when (account.trial) { true -> "Yes"; false -> "No"; null -> "Not provided" })
                        Detail("Created", account.createdAt?.let(::dateText) ?: "Not provided")
                        Detail("Subscription length", "Not provided by this API")
                    }
                    BoxCard {
                        Label("BOUQUETS")
                        Text("Bouquet connection pending", fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                        Hint("Your assigned bouquets will appear here once AM's worker is connected. The current provider login API does not return your bouquet names.")
                    }
                    Button(onClick = {}, enabled = false, modifier = Modifier.fillMaxWidth()) { Text("Renewal — setup pending") }
                    Hint("Contact your reseller for package, connection or bouquet changes. AM will only offer renewal of your current package once checkout is connected.")
                }
                "Bills" -> {
                    Heading("Your bills", "Renewal payments and invoices.")
                    BillingUnavailable()
                    Hint("New transactions will build your history here once billing is enabled.")
                }
                "Account" -> {
                    Heading("Your account", "Your service, connected independently.")
                    BoxCard {
                        Label("SIGNED IN AS")
                        Text(account.username, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                        Detail("Account status", account.status.label)
                        Detail("App version", "0.2.2 · Live read-only")
                        Detail("Login", if (state.remembered) "Remembered on this device" else "Not saved on this device")
                    }
                    Hint("AM Account uses its own login code and session. No Hub settings, devices, profiles or subscriptions are changed by this app.")
                    Secondary("Sign out") { showLogout = true }
                }
            }
            Hint("Last verified: ${dateTimeText(account.checkedAtMillis)}. Dates use ${ZoneId.systemDefault().id}. Refresh to check for changes.")
        }
    }
    if (showLogout) AlertDialog(onDismissRequest = { showLogout = false }, title = { Text("Sign out of AM Account?") },
        text = { Text("Your saved login will be removed from this device. Your Hub login is unaffected.") },
        confirmButton = { TextButton(onClick = { showLogout = false; signOut() }) { Text("Sign out") } },
        dismissButton = { TextButton(onClick = { showLogout = false }) { Text("Cancel") } })
}

@Composable
private fun LiveServiceCard(account: ServiceAccount) = BoxCard {
    Label("YOUR SUBSCRIPTION")
    Text(account.status.label.uppercase(), color = if (account.status == ServiceStatus.ACTIVE) Lime else Amber,
        fontSize = 12.sp, fontWeight = FontWeight.Bold)
    Text(account.packageName ?: "Your service", fontSize = 30.sp, fontWeight = FontWeight.Bold)
    Text(account.username, color = Muted)
    HorizontalDivider(color = Muted.copy(alpha = 0.15f))
    Detail("Expires", account.expiryText())
}

@Composable
private fun BillingUnavailable() = BoxCard {
    Label("BILLING SETUP PENDING")
    Text("Your service is connected.\nBilling is next.", fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
    Hint("Invoice history and renewal payments need the new billing database. This app has not created or charged an invoice.")
}

@Composable
private fun ErrorNotice(message: String) {
    Surface(color = Amber.copy(alpha = 0.10f), shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth()) {
        Text(message, color = Amber, fontSize = 13.sp, modifier = Modifier.padding(16.dp).semantics { liveRegion = LiveRegionMode.Polite })
    }
}

private fun ServiceAccount.expiryText(): String = when {
    unlimited -> "No expiry set"
    expiresAt != null -> dateText(expiresAt)
    else -> "Not provided"
}
private fun dateText(seconds: Long): String = DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM)
    .withZone(ZoneId.systemDefault()).format(Instant.ofEpochSecond(seconds))
private fun dateTimeText(millis: Long): String = DateTimeFormatter.ofLocalizedDateTime(FormatStyle.SHORT)
    .withZone(ZoneId.systemDefault()).format(Instant.ofEpochMilli(millis))
