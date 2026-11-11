package net.extremeiptv.account

import android.content.Intent
import android.net.Uri

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Star
import kotlinx.coroutines.delay
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.focus.FocusDirection
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
import net.extremeiptv.account.update.AppUpdateViewModel
import net.extremeiptv.account.update.UpdatePrompt
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

@Composable
internal fun LiveAccountApp() = AccountTheme {
    val context = LocalContext.current.applicationContext
    val model: AccountViewModel = viewModel { AccountViewModel(ManagedAccountClient(), EncryptedLoginStore(context)) }
    val state by model.state.collectAsStateWithLifecycle()
    val updates: AppUpdateViewModel = viewModel()
    UpdatePrompt(updates)
    val announcements = remember { net.extremeiptv.account.announcements.AnnouncementInboxState(context) }
    net.extremeiptv.account.announcements.AnnouncementPrompt(announcements)
    var paymentOffer by remember { mutableStateOf<RenewalOffer?>(null) }
    val requestRenewal: (RenewalOffer) -> Unit = { offer ->
        if (offer.amount == 0L || offer.paymentOptions.isEmpty()) model.requestQuote(offer.copy(gateway = offer.paymentOptions.firstOrNull()?.gateway ?: "square"))
        else paymentOffer = offer
    }
    paymentOffer?.let { offer ->
        PaymentChoice(offer, { paymentOffer = null }) { selected -> paymentOffer = null; model.requestQuote(selected) }
    }
    val checkUpdates = { updates.check(manual = true) }
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
        if (state.restoring || state.canRetrySavedLogin) SavedLoginScreen(state, model::restoreSavedLogin, model::signOut, checkUpdates,
            { net.extremeiptv.account.announcements.AnnouncementBell(announcements) })
        else LiveSignIn(state, model::signIn, checkUpdates,
            { net.extremeiptv.account.announcements.AnnouncementBell(announcements) })
    } else {
        // Account changes/logouts dispose navigation and any visible private account details.
        key(account.username) { LiveDashboard(account, state, model::refresh, model::signOut,
            requestRenewal, model::confirmRenewal, model::dismissQuote, model::checkOrder, model::saveBouquets, checkUpdates,
            { net.extremeiptv.account.announcements.AnnouncementBell(announcements) }) }
    }
}

@Composable
private fun LiveSignIn(state: LiveAccountState, onSignIn: (String, String, String, Boolean) -> Unit, checkUpdates: () -> Unit, notificationBell: @Composable () -> Unit) {
    var method by rememberSaveable { mutableStateOf("Activation code") }
    // These inputs intentionally do not use rememberSaveable or SavedStateHandle.
    var code by remember { mutableStateOf("") }
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var help by rememberSaveable { mutableStateOf(false) }
    val focus = LocalFocusManager.current
    val useCode = method == "Activation code"
    val valid = if (useCode) code.matches(Regex("[0-9]{6}")) else username.isNotBlank() && password.isNotBlank()
    val submit = {
        if (valid && !state.busy) {
            focus.clearFocus()
            onSignIn(code, username, password, useCode)
            code = ""; password = ""; visible = false
        }
    }
    Column(Modifier.fillMaxSize().background(Ink).safeDrawingPadding().imePadding()
        .verticalScroll(rememberScrollState()).padding(horizontal = 24.dp, vertical = 28.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(24.dp)) {
        Column(Modifier.widthIn(max = 440.dp), verticalArrangement = Arrangement.spacedBy(24.dp)) {
        Brand(notificationBell)
        Spacer(Modifier.height(4.dp))
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Welcome to My TV.", color = White, fontSize = 32.sp, lineHeight = 38.sp, fontWeight = FontWeight.Bold)
            Text("Your service, simply managed.", color = Muted, fontSize = 15.sp)
        }
        BoxCard {
        Text("Sign in to your account", fontSize = 19.sp, fontWeight = FontWeight.SemiBold)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("Activation code" to "Activation code", "Username & password" to "Service login").forEach { (value, label) ->
                Surface(onClick = {
                    method = value; code = ""; username = ""; password = ""; visible = false; focus.clearFocus()
                }, enabled = !state.busy, shape = RoundedCornerShape(12.dp),
                    color = if (method == value) Lime else Ink, contentColor = if (method == value) Ink else Muted,
                    modifier = Modifier.weight(1f).heightIn(min = 52.dp)
                        .semantics { selected = method == value }) {
                    Box(Modifier.padding(horizontal = 8.dp, vertical = 14.dp), contentAlignment = Alignment.Center) {
                        Text(label, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                            textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                    }
                }
            }
        }
        Hint(if (useCode) "Enter the 6-digit code from your reseller." else "Use your service username and password.")
        if (useCode) {
            OutlinedTextField(value = code, onValueChange = { code = it.filter { c -> c in '0'..'9' }.take(6) },
                enabled = !state.busy, label = { Text("Activation code") }, singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                textStyle = androidx.compose.ui.text.TextStyle(fontSize = 24.sp, letterSpacing = 7.sp, color = White),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { submit() }),
                supportingText = { Text("${code.length} of 6 digits", color = Muted) },
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
        } else {
            OutlinedTextField(value = username, onValueChange = { username = it.take(256) },
                enabled = !state.busy, label = { Text("Service username") }, singleLine = true,
                keyboardOptions = KeyboardOptions(autoCorrect = false, imeAction = ImeAction.Next),
                keyboardActions = KeyboardActions(onNext = { focus.moveFocus(FocusDirection.Down) }),
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
            OutlinedTextField(value = password, onValueChange = { password = it.take(512) },
                enabled = !state.busy, label = { Text("Service password") }, singleLine = true,
                visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { submit() }),
                trailingIcon = { TextButton(enabled = !state.busy, onClick = { visible = !visible }) { Text(if (visible) "Hide" else "Show") } },
                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth())
        }
        state.error?.let { ErrorNotice(it) }
        Button(enabled = valid && !state.busy, onClick = submit,
            colors = ButtonDefaults.buttonColors(disabledContainerColor = Lime.copy(alpha = 0.18f), disabledContentColor = Lime.copy(alpha = 0.7f)),
            modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp), shape = RoundedCornerShape(14.dp)) {
            if (state.busy) {
                CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(12.dp))
            }
            Text(if (state.busy) "Verifying your service…" else "Sign in", fontWeight = FontWeight.Bold)
        }
        Hint("Securely remembered on this device.")
        }
        TextButton(onClick = { help = !help }, modifier = Modifier.align(Alignment.CenterHorizontally)) {
            Text(if (help) "Hide sign-in help" else "Need help signing in?", color = Muted)
        }
        TextButton(onClick = checkUpdates, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("App updates", color = Muted) }
        if (help) BoxCard {
            Text("Your reseller can help", fontWeight = FontWeight.SemiBold)
            Hint("Use your Hub activation code or the service username and password supplied by your reseller. Ask them to enable My TV access if you cannot sign in.")
            Hint("Signing out removes the saved login from this device.")
        }
        }
    }
}

@Composable
private fun SavedLoginScreen(state: LiveAccountState, retry: () -> Unit, signOut: () -> Unit, checkUpdates: () -> Unit, notificationBell: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize().background(Ink).safeDrawingPadding().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(24.dp)) {
        Brand(notificationBell)
        Heading("Welcome back.", "Your login is saved. Checking your service with the provider.")
        if (state.busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        state.error?.let { ErrorNotice(it) }
        if (!state.busy) {
            Hint("Your saved login is still on this device. A temporary connection problem does not sign you out.")
            Primary("Retry saved login", retry)
        }
        Secondary("Use another account", signOut)
        TextButton(onClick = checkUpdates) { Text("App updates") }
    }
}

@Composable
private fun LiveDashboard(account: ServiceAccount, state: LiveAccountState, refresh: () -> Unit, signOut: () -> Unit,
    quote: (RenewalOffer) -> Unit, confirm: () -> Unit, dismissQuote: () -> Unit, checkOrder: (String) -> Unit,
    saveBouquets: (Set<Int>, String) -> Unit, checkUpdates: () -> Unit, notificationBell: @Composable () -> Unit) {
    val context = LocalContext.current
    var linkError by remember { mutableStateOf(false) }
    var tab by rememberSaveable { mutableStateOf("Home") }
    var invoiceId by rememberSaveable { mutableStateOf<String?>(null) }
    var billFilter by rememberSaveable { mutableStateOf("All") }
    var showLogout by remember { mutableStateOf(false) }
    var shownInvoiceRevision by rememberSaveable { mutableStateOf(0) }
    LaunchedEffect(state.invoiceRevision) {
        // Navigate on the actual result, including recovery after payment-method selection.
        if (state.invoiceRevision > shownInvoiceRevision && state.selectedOrder != null) {
            shownInvoiceRevision = state.invoiceRevision
            invoiceId = state.selectedOrder.id
            tab = "Invoice"
        }
    }
    val scroll = key(tab, state.quote != null) { rememberScrollState() }
    LaunchedEffect(state.error) {
        if (state.error != null) scroll.scrollTo(0)
    }
    BackHandler(enabled = tab != "Home" || state.quote != null) {
        if (state.quote != null) dismissQuote()
        else tab = when (tab) { "Modify", "Renew" -> "Services"; "Invoice" -> "Bills"; else -> "Home" }
    }
    Scaffold(containerColor = Ink, bottomBar = {
        NavigationBar(containerColor = Ink) {
            listOf("Home", "Services", "Deals", "Bills", "Account").forEach { item ->
                val selectedTab = when (tab) { "Modify", "Renew" -> "Services"; "Invoice" -> "Bills"; else -> tab }
                NavigationBarItem(selected = selectedTab == item, onClick = { dismissQuote(); tab = item },
                    icon = { Icon(when (item) {
                        "Home" -> Icons.Default.Home
                        "Services" -> Icons.Default.Settings
                        "Bills" -> Icons.AutoMirrored.Filled.List
                        "Deals" -> Icons.Default.Star
                        else -> Icons.Default.AccountCircle
                    }, contentDescription = null) }, label = { Text(item) },
                    colors = NavigationBarItemDefaults.colors(indicatorColor = Panel, selectedIconColor = Lime, selectedTextColor = Lime))
            }
        }
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).imePadding().verticalScroll(scroll)
            .padding(horizontal = 24.dp).padding(top = 16.dp, bottom = 28.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp)) {
            Brand(notificationBell)
            if (state.busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            state.error?.let { ErrorNotice(if (state.stale) "$it\nShowing your last verified account details." else it) }
            if (state.quote != null) {
                val q = state.quote
                Heading("A clear picture.\nBefore you confirm.", "Review your renewal and payment details.")
                BoxCard {
                    Label("RENEWAL SUMMARY")
                    q.promoTitle?.let { Detail("Promotion", it, accent = true) }
                    Detail("Service", account.serviceDisplayName)
                    Detail("Package", q.offer.name)
                    Detail("Connections", q.offer.connections.toString())
                    Detail("Subscription", "${q.offer.duration} ${q.offer.period}")
                }
                BoxCard {
                    Label("PAYMENT")
                    Detail("Checkout", net.extremeiptv.account.data.gatewayName(q.offer.gateway) + if (q.mode == "sandbox") " · Sandbox" else "")
                    HorizontalDivider(color = Muted.copy(alpha = 0.15f))
                    Detail("Amount due", q.offer.price, accent = true)
                }
                Hint(if (q.mode == "sandbox") "Test only. No real charge or service renewal."
                    else "Your current package will be renewed after verified payment. Your remaining days are preserved.")
                Button(onClick = { confirm(); tab = "Invoice"; invoiceId = null }, enabled = !state.busy && !state.stale,
                    shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp)) {
                    Text(if (state.busy) "Processing…" else "Confirm renewal", fontWeight = FontWeight.Bold)
                }
                Secondary("Back to renewal", dismissQuote)
            } else when (tab) {
                "Home" -> {
                    Heading("Everything,\nin your control.", "Your service. Your way.")
                    LiveServiceCard(account)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Stat("CONNECTIONS IN USE", account.activeConnections?.toString() ?: "—",
                            "${if (account.activeConnections == null) "Usage unavailable · " else ""}Limit: ${account.maxConnections?.toString() ?: "—"}", Modifier.weight(1f))
                        Stat("EXPIRES ON", if (account.unlimited) "Unlimited" else account.expiresAt?.let {
                            DateTimeFormatter.ofPattern("MMM dd").withZone(ZoneId.systemDefault()).format(Instant.ofEpochSecond(it))
                        } ?: "—", if (account.unlimited) "No expiry date" else account.expiryText(), Modifier.weight(1f))
                    }
                    Primary("Renew service") { tab = "Renew" }
                    Text("Recent activity", fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                    if (state.orders.isEmpty()) BoxCard { Hint("Your new renewal payments will appear here.") }
                    state.orders.take(3).forEach { order ->
                        OrderActivity(order) { invoiceId = order.id; tab = "Invoice" }
                    }
                }
                "Services" -> {
                    Heading("Your service", "A plan that fits the way you watch.")
                    LiveServiceCard(account)
                    BoxCard {
                        Label("CURRENT SUBSCRIPTION")
                        Detail("Username", account.username)
                        Detail("Package", account.packageName ?: "Not provided by provider")
                        Detail("Status", account.status.label)
                        Detail("Expires", account.expiryText())
                        Detail("Connections", account.maxConnections?.toString() ?: "Not provided")
                        Detail("Connections in use", account.activeConnections?.toString() ?: "Unavailable")
                        Detail("Channel selections", account.bouquets.joinToString(" + ").ifBlank { "None selected" })
                    }
                    Primary("Modify service") { tab = "Modify" }
                    Secondary("Renew / add time") { tab = "Renew" }
                    Hint("Bouquet changes are free. Contact your reseller for package or connection changes.")
                }
                "Modify" -> {
                    Heading("Make it yours.", "Choose the channels you want to watch.")
                    BouquetEditor(account, state.busy || state.stale, state.bouquetMessage, saveBouquets)
                    Secondary("Back to service") { tab = "Services" }
                }
                "Renew" -> {
                    Heading("More time.\nSame great service.", "Add time without losing your remaining days.")
                    LiveServiceCard(account)
                    RenewalOptions(account, state.busy || state.stale, quote)
                    Secondary("Back to service") { tab = "Services" }
                }
                "Deals" -> {
                    Heading("A little less.\nThe same great service.", "Limited-time offers from your reseller.")
                    val now by produceState(account.serverTime, account.loadedNanos) {
                        while (true) {
                            value = account.serverTime + (System.nanoTime() - account.loadedNanos) / 1_000_000_000L
                            delay(1000)
                        }
                    }
                    val deals = account.deals.filter { it.endsAt > now }
                    if (deals.isEmpty()) BoxCard { Hint("No active deals for your current package. Check back for your reseller's next offer.") }
                    deals.forEach { deal -> key(deal.offer.promoId) {
                        BoxCard {
                            DealBanner(deal.bannerUrl, deal.title)
                            Text(deal.title, fontSize = 22.sp, fontWeight = FontWeight.Bold)
                            if (deal.description.isNotBlank()) Hint(deal.description)
                            Detail("Package", deal.offer.name)
                            Detail("Renewal", "${deal.offer.duration} ${deal.offer.period} · ${deal.offer.connections} connections")
                            Detail("Normal price", deal.normalPrice)
                            Detail("Deal price", deal.offer.price, accent = true)
                            Hint("Ends ${dateTimeText(deal.endsAt * 1000)} · ${ZoneId.systemDefault().id}. Future renewals use normal pricing.")
                            Hint("Choose an available payment method. Test modes never renew service.")
                            Button(onClick = { quote(deal.offer) }, enabled = !state.busy && !state.stale,
                                shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp)) {
                                Text("Review deal · ${deal.offer.price}")
                            }
                        }
                    } }
                }
                "Bills" -> {
                    Heading("Your bills,\nall in one place.", "A clear record of payments and renewals.")
                    Choices(listOf("All", "Complete", "Pending"), billFilter) { billFilter = it }
                    val orders = state.orders.filter { billFilter == "All" || (it.complete == (billFilter == "Complete")) }
                    if (orders.isEmpty()) BoxCard { Hint("No ${if (billFilter == "All") "" else billFilter.lowercase() + " "}My TV orders yet. New renewal orders appear here.") }
                    orders.groupBy { DateTimeFormatter.ofPattern("MMMM yyyy").withZone(ZoneId.systemDefault()).format(Instant.ofEpochSecond(it.createdAt)) }.forEach { (month, entries) ->
                        Text(month, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
                        entries.forEach { order -> OrderActivity(order) { invoiceId = order.id; tab = "Invoice" } }
                    }
                }
                "Invoice" -> {
                    Heading("Invoice details", "Your payment and service renewal.")
                    val order = if (invoiceId == null) state.selectedOrder else
                        state.selectedOrder?.takeIf { it.id == invoiceId } ?: state.orders.find { it.id == invoiceId }
                    if (order != null) RenewalOrderCard(order, state.busy, checkOrder)
                    else Hint(if (state.busy) "Preparing your order…" else "No order is selected. Open a renewal from Bills.")
                    Secondary("Back to bills") { tab = "Bills" }
                }
                "Account" -> {
                    Heading("Your account", "The details that keep you connected.")
                    BoxCard {
                        Label("SIGNED IN AS")
                        Text(account.username, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                        Detail("Account status", account.status.label)
                        Detail("Provider", account.providerName.ifBlank { "Your reseller" })
                        if (account.supportEmail.isNotBlank()) Detail("Reseller support", account.supportEmail)
                        Detail("Login", if (state.remembered) "Remembered on this device" else "Not saved on this device")
                    }
                    if (account.supportEmail.isNotBlank()) ActivityRow("Help & support", "Get help with your service", "", "View") {
                        linkError = runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.fromParts("mailto", account.supportEmail, null))) }.isFailure
                    }
                    ActivityRow("App updates", "Version ${BuildConfig.VERSION_NAME}", "", "Check") {
                        checkUpdates()
                    }
                    if (linkError) Hint("No compatible app could open this link. Use your browser or email app to contact your reseller.")
                    Secondary("Sign out") { showLogout = true }
                }
            }
            if (state.quote == null) {
                TextButton(onClick = refresh, enabled = !state.busy) { Text(if (state.busy) "Refreshing…" else "Refresh account") }
                Hint("${if (state.stale) "Last verified data" else "Updated"}: ${dateTimeText(account.checkedAtMillis)}")
            }
        }
    }
    if (showLogout) AlertDialog(onDismissRequest = { showLogout = false }, title = { Text("Sign out of My TV?") },
        text = { Text("Your saved login will be removed from this device. Your Hub login is unaffected.") },
        confirmButton = { TextButton(onClick = { showLogout = false; signOut() }) { Text("Sign out") } },
        dismissButton = { TextButton(onClick = { showLogout = false }) { Text("Cancel") } })
}

@Composable
private fun LiveServiceCard(account: ServiceAccount) = BoxCard {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text("YOUR SUBSCRIPTION", color = Muted, fontSize = 10.sp, letterSpacing = 1.4.sp, modifier = Modifier.weight(1f))
        Surface(color = (if (account.status == ServiceStatus.ACTIVE) Lime else Amber).copy(alpha = 0.10f), shape = RoundedCornerShape(8.dp)) {
            Text("● ${account.status.label.uppercase()}", color = if (account.status == ServiceStatus.ACTIVE) Lime else Amber,
                fontSize = 10.sp, modifier = Modifier.padding(8.dp))
        }
    }
    Text(account.serviceDisplayName, fontSize = 36.sp, fontWeight = FontWeight.Bold)
    HorizontalDivider(color = Muted.copy(alpha = 0.15f))
    Detail("${account.maxConnections?.toString() ?: "—"} connections", account.packageName ?: "Current package")
}

@Composable
private fun BouquetEditor(account: ServiceAccount, busy: Boolean, message: String?, save: (Set<Int>, String) -> Unit) = BoxCard {
    var selected by remember(account.bouquetRevision) { mutableStateOf(account.bouquetChoices.filter { it.selected }.map { it.id }.toSet()) }
    var confirm by remember { mutableStateOf(false) }
    Label("BOUQUETS")
    Hint(if (account.unlimited) "Your unlimited service can add or remove any bouquet in the catalogue. Changes are free and reversible."
        else "Turn your package's bouquets on or off whenever you want. Removed package bouquets stay available to add again. No extra charge.")
    if (account.bouquetChoices.isEmpty()) Hint("No bouquets are available for this package.")
    account.bouquetChoices.forEach { bouquet ->
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(bouquet.name)
                if (!bouquet.available) Hint("Not included in your current package. Contact your reseller to change access.")
            }
            Switch(checked = bouquet.id in selected,
                enabled = account.canEditBouquets && !busy && (bouquet.available || bouquet.selected),
                onCheckedChange = { checked -> selected = if (checked) selected + bouquet.id else selected - bouquet.id })
        }
    }
    if (account.canEditBouquets) Button(enabled = !busy, onClick = { confirm = true }, shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp)) { Text("Save bouquets · Free") }
    else Hint("Your reseller has not enabled bouquet editing for this service. Ask them to enable it in My TV customer permissions.")
    message?.let { Hint(it) }
    if (confirm) AlertDialog(onDismissRequest = { confirm = false }, title = { Text("Save bouquet selection?") },
        text = { Text(if (selected.isEmpty()) "Remove all selected bouquets? You will have no bouquet channel access until you add some again. No charge."
            else "Save ${selected.size} selected bouquets? Removed bouquets will no longer be available on your service. Your package, connections and expiry stay the same. No charge.") },
        confirmButton = { TextButton(enabled = !busy, onClick = { confirm = false; save(selected, account.bouquetRevision) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } })
}

@Composable
private fun RenewalOptions(account: ServiceAccount, disabled: Boolean, quote: (RenewalOffer) -> Unit) = BoxCard {
    Label("SUBSCRIPTION LENGTH")
    Hint("Choose your payment method before reviewing the renewal. Sandbox payments never renew service.")
    if (account.offers.isEmpty()) Hint(account.renewalUnavailableMessage ?: "No renewal option is currently available. Refresh your account. Your reseller can check the exact reason on the My TV management page.")
    account.offers.forEach { offer ->
        Text("${offer.duration} ${offer.period} · ${offer.connections} connection(s)")
        Button(onClick = { quote(offer) }, enabled = !disabled, shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp)) { Text("Review renewal · ${offer.price}") }
    }
}

@Composable
private fun OrderActivity(order: RenewalOrder, open: () -> Unit) {
    ActivityRow(order.name, dateTimeText(order.createdAt * 1000), order.price,
        if (order.mode == "sandbox") "Sandbox" else if (order.complete) "Renewed" else "View status", open)
}

@Composable
private fun RenewalOrderCard(order: RenewalOrder, busy: Boolean, check: (String) -> Unit) = BoxCard {
    val context = LocalContext.current
    var browserError by remember { mutableStateOf(false) }
    Label(if (order.mode == "sandbox") "SANDBOX ORDER" else "RENEWAL ORDER")
    Text(order.name, fontWeight = FontWeight.Bold)
    Text(order.price, fontSize = 36.sp, fontWeight = FontWeight.Bold)
    Hint("${dateTimeText(order.createdAt * 1000)} · ${order.id}")
    Text(order.message)
    HorizontalDivider(color = Muted.copy(alpha = 0.15f))
    Detail("Checkout", net.extremeiptv.account.data.gatewayName(order.gateway) + if (order.mode == "sandbox") " · Sandbox" else "")
    Detail("Amount", order.price)
    order.checkoutUrl?.let { url ->
        Button(enabled = !busy, onClick = {
            browserError = runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }.isFailure
        }, modifier = Modifier.fillMaxWidth()) { Text("Open secure ${net.extremeiptv.account.data.gatewayName(order.gateway)} checkout") }
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
