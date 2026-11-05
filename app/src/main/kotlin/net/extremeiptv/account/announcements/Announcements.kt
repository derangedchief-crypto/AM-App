package net.extremeiptv.account.announcements

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.core.app.NotificationCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import androidx.work.*
import kotlinx.coroutines.*
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import net.extremeiptv.account.MainActivity
import net.extremeiptv.account.R
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

internal data class Announcement(val id: String, val revision: Int, val title: String, val body: String,
    val severity: String, val expires: Long)

internal fun parseAnnouncements(raw: String, now: Long = System.currentTimeMillis() / 1000): List<Announcement> {
    require(raw.toByteArray().size <= 524288)
    val root = JSONObject(raw)
    require(root.getInt("schema") == 1)
    val rows = root.getJSONArray("announcements")
    require(rows.length() <= 20)
    val result = (0 until rows.length()).map { index ->
        val row = rows.getJSONObject(index)
        Announcement(row.getString("id"), row.getInt("revision"), row.getString("title"), row.getString("body"),
            row.getString("severity"), row.getLong("expires")).also {
            require(it.id.matches(Regex("[a-f0-9]{32}")) && it.revision > 0 && it.expires > 0)
            require(it.title.isNotBlank() && it.title.toByteArray().size <= 160 && it.body.isNotBlank() && it.body.toByteArray().size <= 2000)
            require(it.severity in listOf("info", "warning", "critical"))
        }
    }
    require(result.map { it.id }.distinct().size == result.size)
    return result.filter { it.expires > now }
}

internal fun shouldAlert(notice: Announcement, dismissed: Int, notified: Int): Boolean =
    notice.revision > maxOf(dismissed, notified)

internal object AnnouncementService {
    private val mutex = Mutex()
    private val http = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS).callTimeout(25, TimeUnit.SECONDS)
        .followRedirects(false).build()
    private fun prefs(context: Context) = context.getSharedPreferences("global-announcements", Context.MODE_PRIVATE)
    fun cached(context: Context): List<Announcement> = runCatching {
        parseAnnouncements(prefs(context).getString("feed", "") ?: "")
    }.getOrDefault(emptyList())
    fun unread(context: Context): List<Announcement> = cached(context).filter {
        it.revision > prefs(context).getInt("dismissed-${it.id}", 0)
    }
    fun dismiss(context: Context, notice: Announcement) {
        prefs(context).edit().putInt("dismissed-${notice.id}", notice.revision).apply()
        context.getSystemService(NotificationManager::class.java).cancel(notice.id, 702)
    }
    suspend fun refresh(context: Context, background: Boolean) = mutex.withLock {
        withContext(Dispatchers.IO) {
            val raw = http.newCall(Request.Builder().url("https://fecxbrfzku.net/am-app/announcements.php").build()).execute().use { response ->
                check(response.isSuccessful)
                val body = checkNotNull(response.body)
                require(body.contentLength() <= 524288)
                body.source().apply { request(524289) }.buffer.clone().readByteArray().also { require(it.size <= 524288) }.toString(Charsets.UTF_8)
            }
            val rows = parseAnnouncements(raw)
            val preferences = prefs(context)
            val notifications = context.getSystemService(NotificationManager::class.java)
            notifications.createNotificationChannel(NotificationChannel("service-announcements", "Service announcements", NotificationManager.IMPORTANCE_DEFAULT))
            val ids = rows.map { it.id }.toSet()
            val previous = runCatching { parseAnnouncements(preferences.getString("feed", "") ?: "", 0) }.getOrDefault(emptyList())
            previous.filter { it.id !in ids }.forEach { notifications.cancel(it.id, 702) }
            val edit = preferences.edit().putString("feed", raw)
            preferences.all.keys.filter { (it.startsWith("dismissed-") || it.startsWith("notified-")) && it.substringAfter('-') !in ids }.forEach { edit.remove(it) }
            edit.apply()
            if (background && notifications.areNotificationsEnabled()) rows.forEach { notice ->
                if (shouldAlert(notice, preferences.getInt("dismissed-${notice.id}", 0), preferences.getInt("notified-${notice.id}", 0))) {
                    val remaining = notice.expires * 1000 - System.currentTimeMillis()
                    if (remaining > 0 && notifications.getNotificationChannel("service-announcements").importance != NotificationManager.IMPORTANCE_NONE) {
                        val pending = PendingIntent.getActivity(context, 702, Intent(context, MainActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
                        val notification = NotificationCompat.Builder(context, "service-announcements").setSmallIcon(R.drawable.ic_my_tv_logo)
                            .setContentTitle("My TV · ${notice.title}").setContentText(notice.body)
                            .setStyle(NotificationCompat.BigTextStyle().bigText(notice.body)).setContentIntent(pending)
                            .setTimeoutAfter(remaining).setAutoCancel(true).build()
                        try {
                            notifications.notify(notice.id, 702, notification)
                            preferences.edit().putInt("notified-${notice.id}", notice.revision).apply()
                        } catch (_: SecurityException) { }
                    }
                }
            }
        }
    }
}

class AnnouncementWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result = try {
        AnnouncementService.refresh(applicationContext, true); Result.success()
    } catch (cancel: CancellationException) { throw cancel }
    catch (_: Exception) { Result.retry() }
}

@Composable
internal fun AnnouncementPrompt() {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current
    var notices by remember { mutableStateOf(AnnouncementService.unread(context)) }
    var enabled by remember { mutableStateOf(context.getSystemService(NotificationManager::class.java).areNotificationsEnabled()) }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { enabled = it }
    val preferences = remember { context.getSharedPreferences("global-announcements", Context.MODE_PRIVATE) }
    var offerNotifications by remember { mutableStateOf(!enabled && !preferences.getBoolean("permission-offered", false)) }
    val closeOffer = { preferences.edit().putBoolean("permission-offered", true).apply(); offerNotifications = false }
    LaunchedEffect(lifecycle) {
        WorkManager.getInstance(context).enqueueUniquePeriodicWork("global-announcements", ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<AnnouncementWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build())
        lifecycle.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                notices = AnnouncementService.unread(context)
                try { AnnouncementService.refresh(context, false) }
                catch (cancel: CancellationException) { throw cancel }
                catch (_: Exception) { /* Keep unexpired cached notices when offline. */ }
                notices = AnnouncementService.unread(context)
                delay(60_000)
            }
        }
    }
    if (offerNotifications && notices.isEmpty()) {
        AlertDialog(onDismissRequest = closeOffer, title = { Text("Service notifications") },
            text = { Text("Allow My TV to notify you about service issues and announcements. Background checks run roughly every 15 minutes; Android may delay them.") },
            confirmButton = { TextButton(onClick = {
                closeOffer()
                if (Build.VERSION.SDK_INT >= 33) permission.launch(Manifest.permission.POST_NOTIFICATIONS)
                else runCatching { context.startActivity(Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, context.packageName)) }
            }) { Text("Enable notifications") } },
            dismissButton = { TextButton(onClick = closeOffer) { Text("Later") } })
    }
    val notice = notices.firstOrNull() ?: return
    LaunchedEffect(notice.id, notice.revision) {
        delay((notice.expires * 1000 - System.currentTimeMillis()).coerceAtLeast(0))
        notices = AnnouncementService.unread(context)
    }
    val dismiss = { AnnouncementService.dismiss(context, notice); notices = AnnouncementService.unread(context) }
    AlertDialog(onDismissRequest = dismiss, title = { Text(notice.title) }, text = {
        Column(Modifier.verticalScroll(rememberScrollState())) {
            Text("Service announcement · ${notice.severity}")
            Text(notice.body)
            if (!enabled) TextButton(onClick = {
                if (Build.VERSION.SDK_INT >= 33) permission.launch(Manifest.permission.POST_NOTIFICATIONS)
                else runCatching { context.startActivity(Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, context.packageName)) }
            }) { Text("Enable service notifications") }
        }
    }, confirmButton = { TextButton(onClick = dismiss) { Text("Dismiss") } })
}
