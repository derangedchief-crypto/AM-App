package net.extremeiptv.account.update

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.test.runTest
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.io.ByteArrayInputStream
import java.io.File
import java.security.MessageDigest

class UpdateClientTest {
    private val payload = "synthetic APK bytes".toByteArray()
    private fun json(): JSONObject = JSONObject().put("schema", 1).put("packageName", AppRelease.PACKAGE)
        .put("versionCode", 17).put("versionName", "0.5.1").put("minSdk", 26)
        .put("apkUrl", "https://github.com/derangedchief-crypto/AM-App/releases/download/v0.5.1/My-TV-0.5.1.apk")
        .put("size", payload.size).put("sha256", MessageDigest.getInstance("SHA-256").digest(payload).hex())
    private fun rejected(block: () -> Unit) {
        try { block(); fail("Expected rejection") } catch (_: IllegalArgumentException) { }
    }
    @Test fun validReleaseAndVerifiedDownload() {
        val release = AppRelease.parse(json().toString())
        val file = File.createTempFile("update", ".apk")
        try {
            var progress = 0
            copyVerified(ByteArrayInputStream(payload), file, release, { progress = it })
            assertArrayEquals(payload, file.readBytes()); assertEquals(100, progress)
        } finally { file.delete() }
    }
    @Test fun rejectsOtherAppsAndInvalidMetadata() {
        for ((field, value) in listOf("schema" to 2, "packageName" to "net.extremeiptv.account",
            "versionCode" to 0, "minSdk" to 0, "size" to 0, "size" to AppRelease.MAX_APK + 1,
            "sha256" to "invalid", "versionName" to "../unsafe")) {
            rejected { AppRelease.parse(json().put(field, value).toString()) }
        }
    }
    @Test fun onlyCanonicalReleaseAssetsAreAccepted() {
        for (url in listOf("http://github.com/derangedchief-crypto/AM-App/releases/download/v1/app.apk",
            "https://example.com/app.apk", "https://github.com/other/repo/releases/download/v1/app.apk",
            "https://user:secret@github.com/derangedchief-crypto/AM-App/releases/download/v1/app.apk",
            "https://github.com/derangedchief-crypto/AM-App/releases/download/v1/app.apk?redirect=1",
            "https://github.com/derangedchief-crypto/AM-App/releases/download/v1/app.apk#fragment",
            "https://github.com/derangedchief-crypto/AM-App/releases/download/v1/nested/app.apk")) {
            rejected { AppRelease.parse(json().put("apkUrl", url).toString()) }
        }
    }
    @Test fun truncationTamperingAndOversizedDownloadRemoveFile() {
        val release = AppRelease.parse(json().toString())
        for (bytes in listOf(payload.drop(1).toByteArray(), payload + byteArrayOf(1), ByteArray(payload.size))) {
            val file = File.createTempFile("update", ".part")
            try { rejected { copyVerified(ByteArrayInputStream(bytes), file, release) }; assertFalse(file.exists()) }
            finally { file.delete() }
        }
    }
    @Test fun cancelledDownloadsAreRemoved() {
        val file = File.createTempFile("update", ".part")
        try {
            try {
                copyVerified(ByteArrayInputStream(payload), file, AppRelease.parse(json().toString()), checkCancelled = { throw CancellationException() })
                fail("Expected cancellation")
            } catch (_: CancellationException) { }
            assertFalse(file.exists())
        } finally { file.delete() }
    }
    @Test fun signerPackageAndVersionMustMatchBeforeInstallation() {
        val release = AppRelease.parse(json().toString())
        fun check(pkg: String = AppRelease.PACKAGE, version: Long = 17, signers: Set<String> = setOf("trusted"), installed: Long = 16) =
            verifyUpdateIdentity(pkg, version, signers, AppRelease.PACKAGE, installed, setOf("trusted"), release)
        check()
        rejected { check(pkg = "other.app") }
        rejected { check(version = 18) }
        rejected { check(version = 16) }
        rejected { check(installed = 17) }
        rejected { check(signers = emptySet()) }
        rejected { check(signers = setOf("untrusted")) }
        rejected { check(signers = setOf("trusted", "extra")) }
    }
    @Test fun checkUsesPublicFeedWithoutCustomerCredentials() = runTest {
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setBody(json().toString()))
            val release = UpdateClient(feed = server.url("/feed").toString()).latest()
            assertEquals(17, release.versionCode)
            val request = server.takeRequest()
            assertNull(request.getHeader("Authorization")); assertNull(request.getHeader("Cookie"))
        }
    }
    @Test fun oversizedAndFailedResponsesDoNotYieldAnUpdate() = runTest {
        MockWebServer().use { server ->
            for (response in listOf(MockResponse().setResponseCode(503), MockResponse().setChunkedBody("x".repeat(40000), 1000))) {
                server.enqueue(response)
                try { UpdateClient(feed = server.url("/feed").toString()).latest(); fail("Expected failure") }
                catch (_: IllegalStateException) { } catch (_: IllegalArgumentException) { }
            }
        }
    }
}
