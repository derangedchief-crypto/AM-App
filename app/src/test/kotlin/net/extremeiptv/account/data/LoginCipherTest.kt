package net.extremeiptv.account.data

import org.junit.Assert.*
import org.junit.Test
import javax.crypto.KeyGenerator
import javax.crypto.AEADBadTagException

class LoginCipherTest {
    private fun key() = KeyGenerator.getInstance("AES").apply { init(256) }.generateKey()

    @Test fun `credentials round trip without plaintext and each save uses a new nonce`() {
        val key = key(); val cipher = LoginCipher { key }
        val login = SavedLogin(null, "test-user", " private-password &+ ")
        val first = cipher.encrypt(login); val second = cipher.encrypt(login)
        assertFalse(first.contentEquals(second))
        assertFalse(first.toString(Charsets.UTF_8).contains("test-user"))
        assertFalse(first.toString(Charsets.UTF_8).contains("private-password"))
        val decoded = cipher.decrypt(first)
        assertEquals(login.username, decoded.username); assertEquals(login.password, decoded.password)
        assertEquals("SavedLogin([redacted])", decoded.toString())
    }

    @Test fun `activation code preserves original method without resolved password`() {
        val key = key(); val cipher = LoginCipher { key }
        val decoded = cipher.decrypt(cipher.encrypt(SavedLogin("123456", null, null)))
        assertEquals("123456", decoded.code); assertNull(decoded.username); assertNull(decoded.password)
    }

    @Test fun `tampered ciphertext or a replaced key cannot authenticate saved data`() {
        val key = key(); val cipher = LoginCipher { key }
        val encrypted = cipher.encrypt(SavedLogin("123456", null, null))
        val tampered = encrypted.clone().apply { this[lastIndex] = (this[lastIndex].toInt() xor 1).toByte() }
        try { cipher.decrypt(tampered); fail("Must reject tampering") } catch (_: AEADBadTagException) { }
        val differentKey = key()
        try { LoginCipher { differentKey }.decrypt(encrypted); fail("Must reject changed key") } catch (_: AEADBadTagException) { }
    }

    @Test fun `unknown format and oversized file are rejected`() {
        val key = key(); val cipher = LoginCipher { key }
        for (bytes in listOf(ByteArray(12), ByteArray(16385), ByteArray(40) { 2 })) {
            try { cipher.decrypt(bytes); fail("Must reject format") } catch (_: IllegalArgumentException) { }
        }
    }
}
