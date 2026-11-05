package net.extremeiptv.account.update

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.SystemClock
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import net.extremeiptv.account.BuildConfig
import net.extremeiptv.account.MainActivity
import net.extremeiptv.account.R
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

internal data class UpdateState(val visible: Boolean = false, val checking: Boolean = false,
    val downloading: Boolean = false, val progress: Int = 0, val release: AppRelease? = null,
    val apk: File? = null, val message: String? = null)

internal class AppUpdateViewModel(application: Application) : AndroidViewModel(application) {
    private val client = UpdateClient()
    private val mutable = MutableStateFlow(UpdateState())
    val state = mutable.asStateFlow()
    private var lastAttempt = 0L
    private var dismissedVersion = 0L
    init {
        val work = PeriodicWorkRequestBuilder<UpdateCheckWorker>(12, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
        WorkManager.getInstance(application).enqueueUniquePeriodicWork("my-tv-update-check", ExistingPeriodicWorkPolicy.KEEP, work)
    }
    fun check(manual: Boolean = false) {
        val current = mutable.value
        if (current.checking || current.downloading) {
            if (manual) mutable.update { it.copy(visible = true) }
            return
        }
        if (current.apk != null) {
            if (manual) mutable.update { it.copy(visible = true) }
            return
        }
        val now = SystemClock.elapsedRealtime()
        if (!manual && lastAttempt > 0 && now - lastAttempt < TimeUnit.HOURS.toMillis(6)) return
        lastAttempt = now
        mutable.value = UpdateState(visible = manual, checking = true)
        viewModelScope.launch {
            try {
                val release = client.latest()
                val newer = release.versionCode > BuildConfig.VERSION_CODE
                val compatible = release.minSdk <= Build.VERSION.SDK_INT
                val visible = mutable.value.visible || (newer && compatible && release.versionCode != dismissedVersion)
                mutable.value = UpdateState(visible = visible, release = release.takeIf { newer && compatible },
                    message = if (newer && !compatible) "A newer version requires Android API ${release.minSdk} or later." else if (!newer) "You're up to date." else null)
                if (!newer) getApplication<Application>().getSystemService(NotificationManager::class.java).cancel(UPDATE_NOTIFICATION)
            } catch (cancel: CancellationException) { throw cancel }
            catch (_: Exception) { mutable.update { it.copy(checking = false, message = "Couldn't check for updates. Try again when you're connected.") } }
        }
    }
    fun dismiss() {
        dismissedVersion = mutable.value.release?.versionCode ?: 0L
        mutable.update { it.copy(visible = false) }
    }
    fun download() {
        val release = mutable.value.release ?: return
        if (mutable.value.downloading) return
        mutable.update { it.copy(downloading = true, progress = 0, message = null) }
        viewModelScope.launch {
            var file: File? = null
            try {
                val downloaded = client.download(release, File(getApplication<Application>().cacheDir, "updates")) { progress ->
                    mutable.update { it.copy(progress = progress) }
                }
                file = downloaded
                withContext(Dispatchers.IO) { verifyArchive(getApplication(), downloaded, release) }
                mutable.update { it.copy(downloading = false, apk = downloaded, message = "Download verified. Ready to install.") }
            } catch (cancel: CancellationException) { file?.delete(); throw cancel }
            catch (_: Exception) {
                file?.delete()
                mutable.update { it.copy(downloading = false, apk = null, message = "Couldn't download or verify this update. Please try again.") }
            }
        }
    }
    fun install(context: Context) {
        val release = mutable.value.release ?: return
        val apk = mutable.value.apk ?: return
        if (!context.packageManager.canRequestPackageInstalls()) {
            try {
                context.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}")))
                mutable.update { it.copy(message = "Allow My TV to install updates, then return and tap Install update.") }
            } catch (_: Exception) { mutable.update { it.copy(message = "Open Android Settings and allow My TV to install unknown apps, then try again.") } }
            return
        }
        viewModelScope.launch {
            try {
                // Recheck disk contents and installed identity immediately before granting access.
                withContext(Dispatchers.IO) { verifyArchive(context, apk, release) }
                val uri = FileProvider.getUriForFile(context, "${context.packageName}.updates", apk)
                context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION))
                mutable.update { it.copy(message = "Finish installation in Android. If you cancel, you can try again here.") }
            } catch (_: Exception) {
                mutable.update { it.copy(message = "Android couldn't open this update. Check install permissions or download it again.") }
            }
        }
    }
}

@Suppress("DEPRECATION")
private fun PackageInfo.signerDigests(): Set<String> {
    val certificates = if (Build.VERSION.SDK_INT >= 28) signingInfo?.apkContentsSigners else signatures
    return certificates.orEmpty().map { MessageDigest.getInstance("SHA-256").digest(it.toByteArray()).hex() }.toSet()
}

@Suppress("DEPRECATION")
internal fun verifyArchive(context: Context, file: File, release: AppRelease) {
    require(file.length() == release.size) { "Update size changed." }
    val digest = MessageDigest.getInstance("SHA-256")
    file.inputStream().use { input ->
        val buffer = ByteArray(65536)
        while (true) { val count = input.read(buffer); if (count == -1) break; digest.update(buffer, 0, count) }
    }
    require(digest.digest().hex() == release.sha256) { "Update contents changed." }
    val flags = if (Build.VERSION.SDK_INT >= 28) PackageManager.GET_SIGNING_CERTIFICATES else PackageManager.GET_SIGNATURES
    val installed = context.packageManager.getPackageInfo(context.packageName, flags)
    val archive = requireNotNull(context.packageManager.getPackageArchiveInfo(file.absolutePath, flags))
    val version = if (Build.VERSION.SDK_INT >= 28) archive.longVersionCode else archive.versionCode.toLong()
    val installedVersion = if (Build.VERSION.SDK_INT >= 28) installed.longVersionCode else installed.versionCode.toLong()
    verifyUpdateIdentity(archive.packageName, version, archive.signerDigests(), context.packageName,
        installedVersion, installed.signerDigests(), release)
}

private const val UPDATE_NOTIFICATION = 701
class UpdateCheckWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result {
        return try {
            val release = UpdateClient().latest()
            if (release.versionCode <= BuildConfig.VERSION_CODE || release.minSdk > Build.VERSION.SDK_INT) return Result.success()
            val notifications = applicationContext.getSystemService(NotificationManager::class.java)
            notifications.createNotificationChannel(NotificationChannel("app-updates", "App updates", NotificationManager.IMPORTANCE_DEFAULT))
            if (!notifications.areNotificationsEnabled()) return Result.success()
            val preferences = applicationContext.getSharedPreferences("app-updates", Context.MODE_PRIVATE)
            if (preferences.getLong("notified", 0) == release.versionCode) return Result.success()
            val pending = PendingIntent.getActivity(applicationContext, 701, Intent(applicationContext, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val notification = NotificationCompat.Builder(applicationContext, "app-updates")
                .setSmallIcon(R.drawable.ic_my_tv_logo).setContentTitle("My TV ${release.versionName} is available")
                .setContentText("Tap to download and install the update.").setContentIntent(pending).setAutoCancel(true).build()
            try {
                notifications.notify(UPDATE_NOTIFICATION, notification)
                preferences.edit().putLong("notified", release.versionCode).apply()
            } catch (_: SecurityException) { /* Foreground checks still work when notifications are denied. */ }
            Result.success()
        } catch (cancel: CancellationException) { throw cancel }
        catch (_: Exception) { Result.retry() }
    }
}
