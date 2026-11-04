package net.extremeiptv.account.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.AtomicFile
import java.io.File
import java.security.KeyStore
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/** App-private, atomic ciphertext excluded from Android backups and device transfer. */
internal class EncryptedLoginStore(context: Context) : LoginStore {
    private val file = AtomicFile(File(context.noBackupFilesDir, "saved-login-v1.bin"))
    private val alias = "am-account-saved-login-v1"
    private val cipher = LoginCipher(::key)
    private fun keyStore() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    private fun key(): SecretKey {
        (keyStore().getKey(alias, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256).setRandomizedEncryptionRequired(true).build())
        }.generateKey()
    }

    @Synchronized override fun load(): SavedLogin? {
        if (!file.baseFile.exists() && !File(file.baseFile.path + ".bak").exists()) return null
        val bytes = file.openRead().use { stream ->
            val data = stream.readBytesBounded(16384)
            require(data.isNotEmpty())
            data
        }
        return cipher.decrypt(bytes)
    }

    @Synchronized override fun save(login: SavedLogin) {
        val encrypted = cipher.encrypt(login)
        val output = file.startWrite()
        try { output.write(encrypted); file.finishWrite(output) }
        catch (error: Exception) { file.failWrite(output); throw error }
    }

    @Synchronized override fun clear() {
        // Removing the key also renders any leftover ciphertext unusable.
        val keyRemoved = runCatching { keyStore().deleteEntry(alias) }.isSuccess
        file.delete()
        val fileRemoved = !file.baseFile.exists() && !File(file.baseFile.path + ".bak").exists()
        check(keyRemoved || fileRemoved) { "Unable to remove saved login" }
    }

    private fun java.io.InputStream.readBytesBounded(limit: Int): ByteArray {
        val output = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(1024)
        while (true) {
            val count = read(buffer)
            if (count == -1) break
            require(output.size() + count <= limit)
            output.write(buffer, 0, count)
        }
        return output.toByteArray()
    }
}
