package net.extremeiptv.account.announcements

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class AnnouncementsTest {
    private fun row() = JSONObject().put("id", "a".repeat(32)).put("revision", 1).put("title", "Service notice")
        .put("body", "We are investigating.").put("severity", "warning").put("expires", 2000)
    private fun feed(vararg rows: JSONObject) = JSONObject().put("schema", 1).put("announcements", JSONArray(rows.toList())).toString()
    private fun rejects(raw: String) {
        try { parseAnnouncements(raw, 1000); fail("Expected invalid feed rejection") } catch (_: IllegalArgumentException) { }
    }
    @Test fun expiryAndWithdrawalRemoveNotices() {
        assertEquals(1, parseAnnouncements(feed(row()), 1000).size)
        assertTrue(parseAnnouncements(feed(row()), 2000).isEmpty())
        assertTrue(parseAnnouncements(feed(), 1000).isEmpty())
    }
    @Test fun dismissAndNotificationDeduplicateButRevisionsAlertAgain() {
        val notice = parseAnnouncements(feed(row()), 1000).single()
        assertTrue(shouldAlert(notice, 0, 0))
        assertFalse(shouldAlert(notice, 1, 0))
        assertFalse(shouldAlert(notice, 0, 1))
        assertTrue(shouldAlert(notice.copy(revision = 2), 1, 1))
        assertFalse(shouldAlert(notice, 2, 2))
    }
    @Test fun rejectsOversizeInvalidAndDuplicateNotices() {
        rejects(feed(row(), row()))
        rejects(feed(row().put("severity", "unknown")))
        rejects(feed(row().put("body", "x".repeat(2001))))
        rejects(feed(row().put("title", "")))
        rejects(feed(row().put("id", "invalid")))
        rejects(feed(row().put("revision", 0)))
        rejects(" ".repeat(524289))
        rejects(feed(*(1..21).map { row().put("id", it.toString(16).padStart(32, '0')) }.toTypedArray()))
    }
}
