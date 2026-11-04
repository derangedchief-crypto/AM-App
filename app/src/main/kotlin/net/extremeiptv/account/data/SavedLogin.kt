package net.extremeiptv.account.data

import org.json.JSONObject
import java.nio.ByteBuffer
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Store the original login method. Code logins re-resolve the code on restart. */
internal class SavedLogin(val code: String?, val username: String?, val password: String?) {
    init {
        require(if (code != null) code.matches(Regex("[0-9]{6}")) && username == null && password == null
        else !username.isNullOrBlank() && username.length <= 256 && !password.isNullOrBlank() && password.length <= 512)
    }
    override fun toString() = "SavedLogin([redacted])"

    suspend fun resolve(client: AccountClient): ServiceCredentials = if (code != null) client.resolveCode(code)
        else ServiceCredentials(checkNotNull(username), checkNotNull(password))

    fun encode(): ByteArray = JSONObject().put("version", 1).apply {
        if (code != null) put("code", code) else { put("username", username); put("password", password) }
    }.toString().toByteArray(Charsets.UTF_8)

    companion object {
        fun decode(bytes: ByteArray): SavedLogin {
            require(bytes.size <= 8192)
            val json = JSONObject(bytes.toString(Charsets.UTF_8))
            require(json.getInt("version") == 1)
            return if (json.has("code")) SavedLogin(json.getString("code"), null, null)
            else SavedLogin(null, json.getString("username"), json.getString("password"))
        }
    }
}

internal interface LoginStore {
    fun load(): SavedLogin?
    fun save(login: SavedLogin)
    fun clear()
}

/** AES-GCM with a new provider-generated IV on every write, including format binding. */
internal class LoginCipher(private val key: () -> SecretKey) {
    private val aad = "net.extremeiptv.account.saved-login.v1".toByteArray(Charsets.UTF_8)
    fun encrypt(login: SavedLogin): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        cipher.updateAAD(aad)
        require(cipher.iv.size == 12)
        val plain = login.encode()
        val encrypted = try { cipher.doFinal(plain) } finally { plain.fill(0) }
        return ByteBuffer.allocate(1 + 12 + encrypted.size).put(1.toByte()).put(cipher.iv).put(encrypted).array()
    }
    fun decrypt(bytes: ByteArray): SavedLogin {
        require(bytes.size in 30..16384 && bytes[0] == 1.toByte())
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, bytes.copyOfRange(1, 13)))
        cipher.updateAAD(aad)
        val plain = cipher.doFinal(bytes.copyOfRange(13, bytes.size))
        return try { SavedLogin.decode(plain) } finally { plain.fill(0) }
    }
}
