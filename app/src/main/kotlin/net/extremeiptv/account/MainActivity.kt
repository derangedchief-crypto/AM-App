package net.extremeiptv.account

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// Original visual demo remains available to Android Studio Preview only.
internal val Ink = Color(0xFF0B1017)
internal val Panel = Color(0xFF151D28)
internal val Lime = Color(0xFFD5F58A)
internal val Muted = Color(0xFF9AAABD)
internal val White = Color(0xFFF3F6FA)
internal val Amber = Color(0xFFFFD69A)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { LiveAccountApp() }
    }
}

@Composable
internal fun AccountTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(primary = Lime, onPrimary = Ink, background = Ink,
            surface = Panel, onSurface = White, onBackground = White,
            onSurfaceVariant = Muted, secondary = Lime, outline = Color(0xFF384657)),
        content = content
    )
}

@Composable
fun AccountApp() = AccountTheme {
    var signedIn by rememberSaveable { mutableStateOf(false) }
    var tab by rememberSaveable { mutableStateOf("Home") }
    var page by rememberSaveable { mutableStateOf("") }
    var invoice by rememberSaveable { mutableStateOf("AM-1042") }
    var filter by rememberSaveable { mutableStateOf("All") }
    var packageName by rememberSaveable { mutableStateOf("Plus") }
    var connections by rememberSaveable { mutableStateOf("2") }
    var months by rememberSaveable { mutableStateOf("3 months") }
    var sports by rememberSaveable { mutableStateOf(true) }
    var international by rememberSaveable { mutableStateOf(false) }
    var country by rememberSaveable { mutableStateOf("Canada") }
    var useCredit by rememberSaveable { mutableStateOf(true) }
    var renewing by rememberSaveable { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<String?>(null) }
    val screenScroll = key(page, tab) { rememberScrollState() }
    fun navigate(destination: String) { page = destination }
    fun resetSelection() {
        packageName = "Plus"; connections = "2"; months = "3 months"
        sports = true; international = false
    }
    fun back() { page = when (page) { "Review" -> if (renewing) "Renew" else "Change"; else -> "" } }
    BackHandler(enabled = signedIn && (page.isNotEmpty() || tab != "Home")) {
        if (page.isNotEmpty()) back() else tab = "Home"
    }

    if (!signedIn) {
        SignIn { signedIn = true }
    } else {
        Scaffold(
            containerColor = Ink,
            bottomBar = {
                if (page.isEmpty()) NavigationBar(containerColor = Ink) {
                    listOf("Home", "Services", "Bills", "Account").forEach { item ->
                        NavigationBarItem(selected = tab == item, onClick = { tab = item },
                            icon = { Icon(when (item) {
                                "Home" -> Icons.Default.Home
                                "Services" -> Icons.Default.Settings
                                "Bills" -> Icons.AutoMirrored.Filled.List
                                else -> Icons.Default.AccountCircle
                            }, contentDescription = null) },
                            label = { Text(item) },
                            colors = NavigationBarItemDefaults.colors(indicatorColor = Panel,
                                selectedIconColor = Lime, selectedTextColor = Lime))
                    }
                }
            }
        ) { padding ->
            Column(Modifier.fillMaxSize().padding(padding).imePadding()
                .verticalScroll(screenScroll).padding(horizontal = 24.dp)
                .padding(top = 16.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                if (page.isNotEmpty()) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        IconButton(onClick = { back() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Back") }
                        Text("YOUR ACCOUNT", color = Muted, fontSize = 11.sp, letterSpacing = 2.sp)
                    }
                } else Brand()
                DemoLabel()
                when (page.ifEmpty { tab }) {
                    "Home" -> {
                        Heading("Everything,\nin your control.", "Your service. Your way.")
                        ServiceCard(onManage = { tab = "Services" })
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Stat("ACCOUNT CREDIT", "$18.50", "Available balance", Modifier.weight(1f))
                            Stat("RENEWS ON", "Dec 03", "2026 · 30 days left", Modifier.weight(1f))
                        }
                        Primary("Renew service") { renewing = true; resetSelection(); navigate("Renew") }
                        SectionTitle("Recent activity", "View bills") { tab = "Bills" }
                        ActivityRow("Service renewed", "Nov 03, 2026 · AM-1042", "$75.00", "Paid") {
                            invoice = "AM-1042"; navigate("Invoice")
                        }
                        ActivityRow("Connection adjustment", "Nov 01, 2026", "+$18.50", "Credit") { navigate("Credit") }
                    }
                    "Services" -> {
                        Heading("Your service", "A plan that fits the way you watch.")
                        ServiceCard(onManage = null)
                        BoxCard {
                            Label("CURRENT SUBSCRIPTION")
                            Detail("Package", "Plus")
                            Detail("Connections", "2 simultaneous streams")
                            Detail("Subscription", "3 months")
                            Detail("Channel selections", "Entertainment + Sports")
                            Detail("Service username", "demo.customer")
                        }
                        Primary("Modify service") { renewing = false; resetSelection(); navigate("Change") }
                        Secondary("Renew / add time") { renewing = true; resetSelection(); navigate("Renew") }
                        Hint("Changes take effect immediately after confirmation and any required payment. Unused downgrade value becomes account credit.")
                    }
                    "Change", "Renew" -> {
                        Heading(if (renewing) "More time.\nSame great service." else "Make it yours.",
                            if (renewing) "Add time without losing your remaining days." else "Choose your package, connections and channels.")
                        if (!renewing) {
                            BoxCard {
                                Label("01 / PACKAGE")
                                Choices(listOf("Essential", "Plus", "Ultimate"), packageName) { packageName = it }
                                Text("Current package: Plus", color = Muted, fontSize = 13.sp)
                            }
                            BoxCard {
                                Label("02 / CONNECTIONS")
                                Choices(listOf("1", "2", "3", "4"), connections) { connections = it }
                                Text("Devices watching at the same time", color = Muted, fontSize = 13.sp)
                            }
                        }
                        BoxCard {
                            Label(if (renewing) "SUBSCRIPTION LENGTH" else "03 / SUBSCRIPTION LENGTH")
                            Choices(listOf("1 month", "3 months", "6 months", "12 months"), months) { months = it }
                        }
                        if (!renewing) BoxCard {
                            Label("04 / CHANNEL SELECTIONS")
                            Text("Entertainment", fontWeight = FontWeight.SemiBold)
                            Text("Included in your package", color = Muted, fontSize = 12.sp)
                            Toggle("Sports", "Live sports and dedicated channels", sports) { sports = it }
                            Toggle("International", "Channels from around the world", international) { international = it }
                        }
                        Hint("Preview selections only. Available packages, channels and prices will come from your provider.")
                        Primary("Review ${if (renewing) "renewal" else "changes"}") { navigate("Review") }
                    }
                    "Review" -> {
                        Heading("A clear picture.\nBefore you confirm.", "Review your selections and billing country.")
                        BoxCard {
                            Label("${if (renewing) "RENEWAL" else "SERVICE CHANGE"} SUMMARY")
                            Detail("Package", packageName)
                            Detail("Connections", connections)
                            Detail("Subscription", months)
                            Detail("Channels", listOfNotNull("Entertainment", if (sports) "Sports" else null,
                                if (international) "International" else null).joinToString(" + "))
                        }
                        BoxCard {
                            Label("BILLING COUNTRY")
                            Choices(listOf("Canada", "USA"), country) { country = it }
                            Text(if (country == "Canada") "Stripe / Square" else "Stripe / Square / Rampex", color = Muted)
                            Text("Available gateways depend on provider configuration.", color = Muted, fontSize = 12.sp)
                        }
                        BoxCard {
                            Toggle("Use account credit", "$18.50 CAD demo balance", useCredit) { useCredit = it }
                            HorizontalDivider(color = Muted.copy(alpha = 0.15f))
                            Detail("Price adjustment", "Quote pending")
                            Detail("Credit applied", "Calculated at checkout")
                            Detail("Amount due", "Quote pending", accent = true)
                        }
                        Hint("This screen preview does not calculate charges or change your service. The backend will quote unused-time credit and the exact amount due before confirmation.")
                        Primary("Preview confirmation") { navigate("Confirmation") }
                    }
                    "Confirmation" -> {
                        Text("✓", color = Lime, fontSize = 64.sp)
                        Heading("You're in control.", "You've reached the end of the demo flow.")
                        BoxCard {
                            Label("WHAT HAPPENS IN THE CONNECTED APP")
                            Text("1. Review your exact quote and credit.")
                            Text("2. Confirm, and pay any remaining amount securely.")
                            Text("3. See your updated service and invoice once verified.")
                        }
                        Hint("No payment was taken. No service or credit balance was changed.")
                        Primary("Back to my service") { page = ""; tab = "Services" }
                    }
                    "Bills" -> {
                        Heading("Your bills,\nall in one place.", "A clear record of payments and adjustments.")
                        Choices(listOf("All", "Paid", "Credits"), filter) { filter = it }
                        Label("NOVEMBER 2026 · SAMPLE HISTORY")
                        if (filter != "Credits") ActivityRow("Service renewal", "Nov 03 · AM-1042", "$75.00", "Paid") {
                            invoice = "AM-1042"; navigate("Invoice")
                        }
                        if (filter != "Paid") ActivityRow("Connection adjustment", "Nov 01 · CR-0018", "+$18.50", "Credit") { navigate("Credit") }
                        if (filter != "Credits") {
                            Label("AUGUST 2026")
                            ActivityRow("Service renewal", "Aug 03 · AM-0986", "$75.00", "Paid") {
                                invoice = "AM-0986"; navigate("Invoice")
                            }
                        }
                        Hint("These are fictional examples. Your real billing history will start empty and build as you use the connected app.")
                    }
                    "Invoice" -> {
                        Heading("Invoice details", invoice)
                        BoxCard {
                            Badge("PAID · SAMPLE")
                            Text("$75.00", fontSize = 40.sp, fontWeight = FontWeight.Bold)
                            Text("CAD · ${if (invoice == "AM-1042") "November 03" else "August 03"}, 2026", color = Muted)
                            HorizontalDivider(color = Muted.copy(alpha = 0.15f))
                            Detail("Plus · 3 months", "$75.00")
                            Detail("Connections", "2")
                            Detail("Credit used", "$0.00")
                            Detail("Total paid", "$75.00 CAD", accent = true)
                            Detail("Service status", "Renewal complete")
                        }
                        Hint("Sample invoice for visual review. Real invoices will reflect verified payments and service changes.")
                    }
                    "Credit" -> {
                        Heading("A little credit.\nMore possibilities.", "Value from your service adjustments.")
                        BoxCard {
                            Label("AVAILABLE ACCOUNT CREDIT")
                            Text("$18.50", color = Lime, fontSize = 44.sp, fontWeight = FontWeight.Bold)
                            Text("CAD · Demo balance", color = Muted)
                        }
                        Text("Credit activity", fontWeight = FontWeight.SemiBold, fontSize = 20.sp)
                        BoxCard {
                            Detail("Connection adjustment", "+$18.50", accent = true)
                            Text("Nov 01, 2026 · CR-0018", color = Muted, fontSize = 12.sp)
                            Text("Unused value returned as account credit.")
                        }
                        Hint("Downgrades and reduced connections return unused value as account credit. Exact proration and credit-use rules will be configured in the billing backend.")
                        Primary("Explore service options") { renewing = false; resetSelection(); navigate("Change") }
                    }
                    "Account" -> {
                        Heading("Your account", "The details that keep you connected.")
                        BoxCard {
                            Badge("DEMO CUSTOMER")
                            Text("demo.customer", fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
                            Detail("Billing currency", "CAD")
                        }
                        ActivityRow("Account credit", "Balance and adjustments", "$18.50", "View") { navigate("Credit") }
                        ActivityRow("App updates", "Version 0.1.0 · Screen preview", "", "Check") {
                            dialog = "Update delivery is not connected in this preview. Signed customer releases will be delivered through GitHub Releases."
                        }
                        ActivityRow("Help & support", "Get help with your service", "", "View") {
                            dialog = "Your provider's support details will appear here once configured. No support request has been sent."
                        }
                        Secondary("Sign out of demo") {
                            signedIn = false; tab = "Home"; page = ""; resetSelection()
                            country = "Canada"; useCredit = true; filter = "All"
                        }
                    }
                }
            }
        }
    }
    dialog?.let { message -> AlertDialog(onDismissRequest = { dialog = null }, title = { Text("Screen preview") },
        text = { Text(message) }, confirmButton = { TextButton(onClick = { dialog = null }) { Text("Got it") } }) }
}

@Composable
private fun SignIn(onSignIn: () -> Unit) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var message by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().background(Ink).safeDrawingPadding().imePadding()
        .verticalScroll(rememberScrollState()).padding(28.dp), verticalArrangement = Arrangement.spacedBy(24.dp)) {
        Brand()
        Spacer(Modifier.height(28.dp))
        Badge("YOUR SERVICE, SIMPLIFIED")
        Text("Less admin.\nMore living.", fontSize = 42.sp, lineHeight = 46.sp, fontWeight = FontWeight.Bold, color = White)
        Text("Manage your service, renew in a moment, and keep every bill in one place.", color = Muted, fontSize = 16.sp)
        DemoLabel()
        OutlinedTextField(value = username, onValueChange = { username = it }, label = { Text("Service username") },
            singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp))
        OutlinedTextField(value = password, onValueChange = { password = it }, label = { Text("Service password") },
            singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp),
            visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
        Primary("Sign in") { message = true }
        if (message) Hint("Live sign-in is not connected. Use the demo below to explore the screens; no credentials are sent or saved.")
        Secondary("Explore demo account") { username = ""; password = ""; onSignIn() }
        Text("Screen preview only. Please don't enter real credentials.", color = Muted, fontSize = 12.sp)
    }
}

@Composable internal fun Brand() {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Surface(color = Ink, shape = RoundedCornerShape(12.dp)) {
            Image(painterResource(R.drawable.ic_launcher), contentDescription = "AM", modifier = Modifier.size(48.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text("ACCOUNT MANAGER", color = White, fontSize = 11.sp, fontWeight = FontWeight.Bold, letterSpacing = 1.8.sp)
    }
}
@Composable private fun DemoLabel() { Text("SCREEN PREVIEW  /  DEMO DATA", color = Amber, fontSize = 10.sp, letterSpacing = 1.5.sp) }
@Composable internal fun Heading(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(title, fontSize = 30.sp, lineHeight = 35.sp, fontWeight = FontWeight.Bold)
        Text(subtitle, color = Muted, fontSize = 14.sp)
    }
}
@Composable internal fun BoxCard(content: @Composable ColumnScope.() -> Unit) {
    Surface(color = Panel, shape = RoundedCornerShape(22.dp), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(16.dp), content = content)
    }
}
@Composable private fun ServiceCard(onManage: (() -> Unit)?) {
    BoxCard {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Label("YOUR SUBSCRIPTION"); Badge("● ACTIVE")
        }
        Text("Plus", fontSize = 36.sp, fontWeight = FontWeight.Bold)
        Text("Entertainment + Sports", color = Muted)
        HorizontalDivider(color = Muted.copy(alpha = 0.15f))
        Detail("2 connections", "3-month plan")
        if (onManage != null) TextButton(onClick = onManage, contentPadding = PaddingValues(0.dp)) { Text("Manage service  →", color = Lime) }
    }
}
@Composable internal fun Stat(label: String, value: String, caption: String, modifier: Modifier) {
    Surface(modifier = modifier, shape = RoundedCornerShape(18.dp), color = Panel) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Text(label, color = Muted, fontSize = 9.sp, letterSpacing = 1.sp)
            Text(value, color = Lime, fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(caption, color = Muted, fontSize = 11.sp)
        }
    }
}
@Composable internal fun Label(text: String) { Text(text, color = Muted, fontSize = 10.sp, letterSpacing = 1.4.sp) }
@Composable internal fun Badge(text: String) {
    Surface(color = Lime.copy(alpha = 0.10f), shape = RoundedCornerShape(8.dp)) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = Lime, fontSize = 10.sp, fontWeight = FontWeight.Bold)
    }
}
@Composable internal fun Detail(label: String, value: String, accent: Boolean = false) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(label, color = Muted, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = if (accent) Lime else White, fontSize = 13.sp, fontWeight = FontWeight.Medium,
            modifier = Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.End)
    }
}
@Composable internal fun Primary(text: String, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 54.dp), shape = RoundedCornerShape(14.dp)) {
        Text(text, fontWeight = FontWeight.Bold, fontSize = 15.sp)
    }
}
@Composable internal fun Secondary(text: String, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp), shape = RoundedCornerShape(14.dp)) { Text(text) }
}
@Composable internal fun Hint(text: String) { Text(text, color = Muted, fontSize = 12.sp, lineHeight = 18.sp) }
@Composable private fun SectionTitle(title: String, action: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text(title, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
        TextButton(onClick = onClick) { Text(action, fontSize = 12.sp) }
    }
}
@Composable internal fun ActivityRow(title: String, subtitle: String, amount: String, status: String, onClick: () -> Unit) {
    Surface(onClick = onClick, color = Panel, shape = RoundedCornerShape(18.dp), modifier = Modifier.fillMaxWidth()) {
        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(title, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                Text(subtitle, color = Muted, fontSize = 11.sp)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (amount.isNotEmpty()) Text(amount, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                Text(status, color = Lime, fontSize = 11.sp)
            }
        }
    }
}
@OptIn(ExperimentalLayoutApi::class)
@Composable internal fun Choices(items: List<String>, selected: String, onSelect: (String) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items.forEach { item -> FilterChip(selected = item == selected, onClick = { onSelect(item) }, label = { Text(item) },
            colors = FilterChipDefaults.filterChipColors(selectedContainerColor = Lime, selectedLabelColor = Ink)) }
    }
}
@Composable private fun Toggle(title: String, subtitle: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f).padding(end = 12.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, fontSize = 14.sp, fontWeight = FontWeight.Medium)
            Text(subtitle, color = Muted, fontSize = 12.sp)
        }
        Switch(checked = checked, onCheckedChange = onChange)
    }
}

@Preview(showBackground = true, widthDp = 390, heightDp = 844)
@Composable private fun LoginPreview() { AccountApp() }
