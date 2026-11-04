package net.extremeiptv.account

import android.content.Intent
import android.net.Uri

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
import net.extremeiptv.account.data.ManagedAccountClient
import net.extremeiptv.account.data.RenewalOrder
import net.extremeiptv.account.data.RenewalOffer
import net.extremeiptv.account.data.EncryptedLoginStore
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

@Composable
internal fun LiveAccountApp() = AccountTheme {
    val context = LocalContext.current.applicationContext
    val model: AccountViewModel = viewModel { AccountViewModel(ManagedAccountClient(), EncryptedLoginStore(context)) }
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
        key(account.username) { LiveDashboard(account, state, model::refresh, model::signOut,
            model::requestQuote, model::confirmRenewal, model::dismissQuote, model::checkOrder, model::saveBouquets) }
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
        Hint("Your reseller must enable AM access for your service. AM verifies your service ownership and keeps its own session.")
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
private fun LiveDashboard(account: ServiceAccount, state: LiveAccountState, refresh: () -> Unit, signOut: () -> Unit,
    quote: (RenewalOffer) -> Unit, confirm: () -> Unit, dismissQuote: () -> Unit, checkOrder: (String) -> Unit,
    saveBouquets: (Set<Int>, String) -> Unit) {
    val context = LocalContext.current
    var linkError by remember { mutableStateOf(false) }
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
            state.selectedOrder?.let { RenewalOrderCard(it, state.busy, checkOrder) }
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
                    RenewalOptions(account, state.busy || state.stale, quote)
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
                        Detail("Trial account", when (account.trial) { true -> "Yes"; false -> "No"; null -> "Not provided" })
                    }
                    BouquetEditor(account, state.busy || state.stale, state.bouquetMessage, saveBouquets)
                    RenewalOptions(account, state.busy || state.stale, quote)
                    Hint("Contact your reseller for package or connection changes. Bouquet editing is free; renewal keeps your current package.")
                }
                "Bills" -> {
                    Heading("Your bills", "Renewal payments and invoices.")
                    if (state.orders.isEmpty()) Hint("No AM orders yet. Your new renewal orders will appear here.")
                    state.orders.forEach { RenewalOrderCard(it, state.busy, checkOrder) }
                }
                "Account" -> {
                    Heading("Your account", "Your service, connected independently.")
                    BoxCard {
                        Label("SIGNED IN AS")
                        Text(account.username, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                        Detail("Account status", account.status.label)
                        Detail("App version", "0.3.1 · Bouquets & renewals")
                        Detail("Provider", account.providerName.ifBlank { "Your reseller" })
                        if (account.supportEmail.isNotBlank()) Detail("Reseller support", account.supportEmail)
                        Detail("Login", if (state.remembered) "Remembered on this device" else "Not saved on this device")
                    }
                    Hint("AM Account uses its own login code and session. No Hub settings, devices, profiles or subscriptions are changed by this app.")
                    if (account.supportEmail.isNotBlank()) Secondary("Contact reseller") {
                        linkError = runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.fromParts("mailto", account.supportEmail, null))) }.isFailure
                    }
                    Secondary("App downloads and updates") {
                        linkError = runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://github.com/derangedchief-crypto/AM-App/releases"))) }.isFailure
                    }
                    if (linkError) Hint("No compatible app could open this link. Use your browser or email app to contact your reseller.")
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
    state.quote?.let { q ->
        AlertDialog(onDismissRequest = dismissQuote, title = { Text(if (q.mode == "sandbox") "Confirm sandbox test" else "Confirm renewal") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(q.offer.name)
                Text("${q.offer.duration} ${q.offer.period} · ${q.offer.connections} connection(s)")
                Text(q.offer.price, fontWeight = FontWeight.Bold)
                Text(if (q.mode == "sandbox") "Test only. No real charge or service renewal."
                    else "Your current package will be renewed after verified payment. Remaining service time is preserved. Receipt item: Payment.")
                state.error?.let { Text(it, color = Amber) }
            } },
            confirmButton = { TextButton(onClick = confirm, enabled = !state.busy) { Text(if (state.busy) "Processing…" else "Confirm") } },
            dismissButton = { TextButton(onClick = dismissQuote, enabled = !state.busy) { Text("Back") } })
    }
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
private fun BouquetEditor(account: ServiceAccount, busy: Boolean, message: String?, save: (Set<Int>, String) -> Unit) = BoxCard {
    var selected by remember(account.bouquetRevision) { mutableStateOf(account.bouquetChoices.filter { it.selected }.map { it.id }.toSet()) }
    var confirm by remember { mutableStateOf(false) }
    Label("BOUQUETS")
    Hint("Add or remove bouquets permitted by your package. There is no extra charge.")
    if (account.bouquetChoices.isEmpty()) Hint("No bouquets are available for this package.")
    account.bouquetChoices.forEach { bouquet ->
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Checkbox(checked = bouquet.id in selected,
                enabled = account.canEditBouquets && !busy && (bouquet.available || bouquet.selected),
                onCheckedChange = { checked -> selected = if (checked) selected + bouquet.id else selected - bouquet.id })
            Column(Modifier.weight(1f)) {
                Text(bouquet.name)
                if (!bouquet.available) Hint("Existing bouquet; cannot be added again after removal.")
            }
        }
    }
    if (account.canEditBouquets) Button(enabled = !busy, onClick = { confirm = true }, modifier = Modifier.fillMaxWidth()) { Text("Save bouquets · Free") }
    else Hint("Your reseller has not enabled bouquet editing for this service. Ask them to enable it in AM customer permissions.")
    message?.let { Hint(it) }
    if (confirm) AlertDialog(onDismissRequest = { confirm = false }, title = { Text("Save bouquet selection?") },
        text = { Text(if (selected.isEmpty()) "Remove all selected bouquets? You will have no bouquet channel access until you add some again. No charge."
            else "Save ${selected.size} selected bouquets? Removed bouquets will no longer be available on your service. Your package, connections and expiry stay the same. No charge.") },
        confirmButton = { TextButton(enabled = !busy, onClick = { confirm = false; save(selected, account.bouquetRevision) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } })
}

@Composable
private fun RenewalOptions(account: ServiceAccount, disabled: Boolean, quote: (RenewalOffer) -> Unit) = BoxCard {
    Label("RENEW CURRENT PACKAGE")
    if (account.renewalMode == "sandbox") Hint("Sandbox mode: test payments only; your service will not be renewed.")
    if (account.offers.isEmpty()) Hint("Your reseller has not enabled an available renewal price for this service. Contact your reseller to renew.")
    account.offers.forEach { offer ->
        Text("${offer.duration} ${offer.period} · ${offer.connections} connection(s)")
        Button(onClick = { quote(offer) }, enabled = !disabled, modifier = Modifier.fillMaxWidth()) { Text("Review renewal · ${offer.price}") }
    }
}

@Composable
private fun RenewalOrderCard(order: RenewalOrder, busy: Boolean, check: (String) -> Unit) = BoxCard {
    val context = LocalContext.current
    var browserError by remember { mutableStateOf(false) }
    Label(if (order.mode == "sandbox") "SANDBOX ORDER" else "RENEWAL ORDER")
    Text(order.name, fontWeight = FontWeight.Bold)
    Text(order.price)
    Hint("${dateTimeText(order.createdAt * 1000)} · ${order.id}")
    Text(order.message)
    order.checkoutUrl?.let { url ->
        Button(enabled = !busy, onClick = {
            browserError = runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }.isFailure
        }, modifier = Modifier.fillMaxWidth()) { Text("Open secure Square checkout") }
        Hint("After checkout, return here and check payment / renewal. Opening or returning from checkout does not confirm payment.")
    }
    if (browserError) Hint("Unable to open a browser. Install or enable a browser and try again.")
    if (!order.complete) OutlinedButton(enabled = !busy, onClick = { check(order.id) }, modifier = Modifier.fillMaxWidth()) {
        Text("Check payment / renewal")
    }
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
