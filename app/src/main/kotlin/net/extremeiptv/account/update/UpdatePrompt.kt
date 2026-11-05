package net.extremeiptv.account.update

import android.Manifest
import android.content.Intent
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.app.NotificationManagerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import net.extremeiptv.account.BuildConfig

@Composable
internal fun UpdatePrompt(model: AppUpdateViewModel) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current
    val state by model.state.collectAsStateWithLifecycle()
    var alertsEnabled by remember { mutableStateOf(NotificationManagerCompat.from(context).areNotificationsEnabled()) }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        alertsEnabled = NotificationManagerCompat.from(context).areNotificationsEnabled()
    }
    DisposableEffect(lifecycle, model) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_START) {
                alertsEnabled = NotificationManagerCompat.from(context).areNotificationsEnabled()
                model.check()
            }
        }
        lifecycle.lifecycle.addObserver(observer)
        onDispose { lifecycle.lifecycle.removeObserver(observer) }
    }
    if (!state.visible) return
    AlertDialog(onDismissRequest = model::dismiss,
        title = { Text(if (state.release != null) "My TV ${state.release!!.versionName}" else "App updates") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Installed: ${BuildConfig.VERSION_NAME}")
                when {
                    state.checking -> { Text("Checking GitHub for updates…"); LinearProgressIndicator(Modifier.fillMaxWidth()) }
                    state.downloading -> { Text("Downloading… ${state.progress}%"); LinearProgressIndicator(progress = { state.progress / 100f }, modifier = Modifier.fillMaxWidth()) }
                    state.release != null && state.apk == null -> Text("A new version is available. Download it here, then approve installation in Android.")
                }
                state.message?.let { Text(it) }
                Text("My TV checks on launch and periodically in the background.")
                if (!alertsEnabled) {
                    TextButton(onClick = {
                        if (Build.VERSION.SDK_INT >= 33) permission.launch(Manifest.permission.POST_NOTIFICATIONS)
                        else runCatching { context.startActivity(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)) }
                    }) { Text("Enable update notifications") }
                }
                if (state.apk != null && !state.downloading) TextButton(onClick = model::download) { Text("Download again") }
            }
        },
        confirmButton = {
            TextButton(enabled = !state.checking && !state.downloading, onClick = {
                when { state.apk != null -> model.install(context); state.release != null -> model.download(); else -> model.check(manual = true) }
            }) { Text(when { state.apk != null -> "Install update"; state.release != null -> "Download update"; else -> "Check again" }) }
        },
        dismissButton = { TextButton(onClick = model::dismiss) { Text("Later") } })
}
