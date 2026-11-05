package net.extremeiptv.account.update

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.HttpUrl.Companion.toHttpUrl
import org.json.JSONObject
import java.io.File
import java.io.InputStream
import java.io.ByteArrayOutputStream
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

internal data class AppRelease(val packageName: String, val versionCode: Long, val versionName: String,
    val minSdk: Int, val apkUrl: String, val sha256: String, val size: Long) {
    companion object {
        const val PACKAGE = "com.extreme.mytv"
        const val MAX_APK = 100L * 1024 * 1024
        fun parse(json: String): AppRelease {
            require(json.length <= 32768) { "Update information is too large." }
            val data = JSONObject(json)
            require(data.getInt("schema") == 1 && data.getString("packageName") == PACKAGE) { "Update is for a different app." }
            val code = data.getLong("versionCode")
            val version = data.getString("versionName")
            val sdk = data.getInt("minSdk")
            val size = data.getLong("size")
            val sha = data.getString("sha256")
            val url = data.getString("apkUrl").toHttpUrl()
            require(code > 0 && version.matches(Regex("[A-Za-z0-9._-]{1,80}")) && sdk in 26..100) { "Invalid update version." }
            require(size in 1..MAX_APK && sha.matches(Regex("[a-f0-9]{64}"))) { "Invalid update integrity details." }
            require(url.scheme == "https" && url.host == "github.com" && url.port == 443 &&
                url.username.isEmpty() && url.password.isEmpty() && url.query == null && url.fragment == null &&
                url.pathSegments.size == 6 && url.pathSegments.take(4) == listOf("derangedchief-crypto", "AM-App", "releases", "download") &&
                url.pathSegments[4].matches(Regex("[A-Za-z0-9._-]+")) &&
                url.pathSegments[5].matches(Regex("[A-Za-z0-9._-]+\\.apk"))) { "Untrusted update location." }
            return AppRelease(PACKAGE, code, version, sdk, url.toString(), sha, size)
        }
    }
}

internal class UpdateClient(
    private val http: OkHttpClient = OkHttpClient.Builder().connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS).callTimeout(10, TimeUnit.MINUTES).build(),
    private val feed: String = FEED
) {
    companion object {
        const val FEED = "https://github.com/derangedchief-crypto/AM-App/releases/latest/download/my-tv-update.json"
    }
    suspend fun latest(): AppRelease = withContext(Dispatchers.IO) {
        http.newCall(Request.Builder().url(feed).header("Cache-Control", "no-cache").build()).execute().use { response ->
            check(response.isSuccessful) { "Update service is unavailable. Try again later." }
            val body = checkNotNull(response.body)
            require(body.contentLength() <= 32768) { "Update information is too large." }
            body.byteStream().use { stream ->
                val collected = ByteArrayOutputStream()
                val buffer = ByteArray(4096)
                while (collected.size() <= 32768) {
                    val count = stream.read(buffer, 0, minOf(buffer.size, 32769 - collected.size()))
                    if (count == -1) break
                    collected.write(buffer, 0, count)
                }
                val bytes = collected.toByteArray()
                require(bytes.size <= 32768) { "Update information is too large." }
                AppRelease.parse(bytes.toString(Charsets.UTF_8))
            }
        }
    }
    suspend fun download(release: AppRelease, directory: File, progress: (Int) -> Unit): File = withContext(Dispatchers.IO) {
        check(directory.isDirectory || directory.mkdirs()) { "Cannot create update storage." }
        val partial = File(directory, "download.part")
        val target = File(directory, "my-tv-${release.versionCode}.apk")
        val coroutine = currentCoroutineContext()
        try {
            directory.listFiles()?.filter { it != partial }?.forEach { it.delete() }
            http.newCall(Request.Builder().url(release.apkUrl).build()).execute().use { response ->
                check(response.isSuccessful) { "Download failed. Try again later." }
                val body = checkNotNull(response.body)
                require(body.contentLength() == -1L || body.contentLength() == release.size) { "Update size does not match." }
                body.byteStream().use { stream ->
                    copyVerified(stream, partial, release, progress) { coroutine.ensureActive() }
                }
            }
            check(partial.renameTo(target)) { "Cannot save downloaded update." }
            target
        } finally { partial.delete() }
    }
}

internal fun copyVerified(input: InputStream, target: File, release: AppRelease,
    progress: (Int) -> Unit = {}, checkCancelled: () -> Unit = {}) {
    val digest = MessageDigest.getInstance("SHA-256")
    var received = 0L
    try {
        target.outputStream().use { output ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                checkCancelled()
                val count = input.read(buffer)
                if (count == -1) break
                received += count
                require(received <= release.size) { "Update size does not match." }
                digest.update(buffer, 0, count)
                output.write(buffer, 0, count)
                progress((received * 100 / release.size).toInt())
            }
        }
        require(received == release.size && digest.digest().hex() == release.sha256) { "Update verification failed. Download again." }
    } catch (error: Exception) { target.delete(); throw error }
}

internal fun ByteArray.hex(): String = joinToString("") { "%02x".format(it) }

internal fun verifyUpdateIdentity(packageName: String?, version: Long, signers: Set<String>, installedPackage: String,
    installedVersion: Long, installedSigners: Set<String>, release: AppRelease) {
    require(packageName == installedPackage && packageName == release.packageName) { "Update is for a different app." }
    require(version == release.versionCode && version > installedVersion) { "Update version does not match." }
    require(signers.isNotEmpty() && signers == installedSigners) { "Update signing certificate does not match My TV." }
}
