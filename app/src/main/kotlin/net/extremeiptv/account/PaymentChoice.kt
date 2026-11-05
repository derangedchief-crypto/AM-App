package net.extremeiptv.account

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import net.extremeiptv.account.data.RenewalOffer
import net.extremeiptv.account.data.gatewayName

@Composable
internal fun PaymentChoice(offer: RenewalOffer, close: () -> Unit, next: (RenewalOffer) -> Unit) {
    var selected by remember(offer) { mutableStateOf(offer.paymentOptions.first()) }
    var email by remember(offer) { mutableStateOf("") }
    AlertDialog(onDismissRequest = close, title = { Text("Payment method") }, text = {
        Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("${offer.name} · ${offer.price}")
            offer.paymentOptions.forEach { option ->
                FilterChip(selected = selected == option, onClick = { selected = option },
                    label = { Text(gatewayName(option.gateway) + if (option.mode == "sandbox") " · Sandbox" else " · Live") }, modifier = Modifier.fillMaxWidth())
            }
            if (selected.mode == "sandbox") Text("Test payment only. Your service will not be renewed.")
            if (selected.gateway == "rampex") {
                OutlinedTextField(value = email, onValueChange = { email = it.take(254) }, label = { Text("Checkout email") },
                    singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
                Text("Rampex requires your email for payment processing. This is a live payment.")
            }
        }
    }, confirmButton = {
        TextButton(enabled = selected.gateway != "rampex" || android.util.Patterns.EMAIL_ADDRESS.matcher(email.trim()).matches(),
            onClick = { next(offer.copy(gateway = selected.gateway, email = if (selected.gateway == "rampex") email.trim() else "")) }) { Text("Review renewal") }
    }, dismissButton = { TextButton(onClick = close) { Text("Cancel") } })
}
