package net.extremeiptv.account.announcements

import android.content.Context
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import net.extremeiptv.account.Ink
import net.extremeiptv.account.Panel
import net.extremeiptv.account.White
import net.extremeiptv.account.Muted
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

internal data class AlertAppearance(val label: String, val color: Color, val icon: ImageVector)
private val criticalIcon = ImageVector.Builder("Critical", 24.dp, 24.dp, 24f, 24f).addPath(
    pathData = PathParser().parsePathString("M12,2 A10,10 0,1 1,12,22 A10,10 0,1 1,12,2 M12,6 L12,13 M12,16 L12,18").toNodes(),
    stroke = SolidColor(Color.Black), strokeLineWidth = 2f
).build()
internal fun alertAppearance(severity: String): AlertAppearance = when (severity) {
    "critical" -> AlertAppearance("Critical alert", Color(0xFFFF9B91), criticalIcon)
    "warning" -> AlertAppearance("Service warning", Color(0xFFFFD69A), Icons.Default.Warning)
    else -> AlertAppearance("Information", Color(0xFF91C9FF), Icons.Default.Info)
}

internal class AnnouncementInboxState(private val context: Context) {
    var rows by mutableStateOf(AnnouncementService.cached(context)); private set
    var unread by mutableStateOf(AnnouncementService.unread(context)); private set
    var open by mutableStateOf(false)
    var selectedId by mutableStateOf<String?>(null)
    var offline by mutableStateOf(false)
    fun reload() {
        rows = AnnouncementService.cached(context)
        unread = AnnouncementService.unread(context)
        if (rows.none { it.id == selectedId }) selectedId = null
    }
    fun read(notice: Announcement) { AnnouncementService.dismiss(context, notice); reload() }
    fun select(notice: Announcement) { read(notice); selectedId = notice.id }
}

@Composable
internal fun AnnouncementBell(state: AnnouncementInboxState) {
    IconButton(onClick = { state.reload(); state.open = true }) {
        BadgedBox(badge = { if (state.unread.isNotEmpty()) Badge(containerColor = Color(0xFFFF9B91), contentColor = Ink) { Text(state.unread.size.toString()) } }) {
            Icon(Icons.Default.Notifications, "Notifications, ${state.unread.size} unread", tint = White)
        }
    }
}

@Composable
private fun AlertSymbol(appearance: AlertAppearance, large: Boolean = false) {
    Surface(color = appearance.color.copy(alpha = .12f), shape = RoundedCornerShape(if (large) 18.dp else 12.dp)) {
        Icon(appearance.icon, contentDescription = null, tint = appearance.color,
            modifier = Modifier.padding(if (large) 16.dp else 12.dp).size(if (large) 30.dp else 22.dp))
    }
}

@Composable
internal fun AnnouncementInbox(state: AnnouncementInboxState) {
    Dialog(onDismissRequest = { state.open = false }) {
        Surface(shape = RoundedCornerShape(26.dp), color = Ink, contentColor = White) {
            Column(Modifier.fillMaxWidth().heightIn(max = 620.dp).padding(22.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Notifications", fontSize = 24.sp, fontWeight = FontWeight.Bold)
                        Text("${state.unread.size} unread · Service updates", color = Muted, fontSize = 12.sp)
                    }
                    IconButton(onClick = { state.open = false }) { Icon(Icons.Default.Close, "Close notifications") }
                }
                Spacer(Modifier.height(18.dp))
                Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    if (state.offline) Text("Unable to refresh. Showing saved, unexpired notices.", color = Muted, fontSize = 12.sp)
                    if (state.rows.isEmpty()) {
                        Icon(Icons.Default.Notifications, null, tint = Muted, modifier = Modifier.padding(top = 18.dp).size(32.dp))
                        Text("You're all caught up", fontWeight = FontWeight.SemiBold)
                        Text("Active service announcements will appear here.", color = Muted)
                    }
                    state.rows.forEach { notice ->
                        val appearance = alertAppearance(notice.severity)
                        val unread = state.unread.any { it.id == notice.id }
                        Surface(onClick = { state.select(notice) }, color = Panel, shape = RoundedCornerShape(18.dp),
                            border = BorderStroke(1.dp, if (unread) appearance.color.copy(alpha = .45f) else White.copy(alpha = .08f))) {
                            Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                AlertSymbol(appearance)
                                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                                    Text(appearance.label + if (unread) " · New" else " · Read", color = appearance.color, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                                    Text(notice.title, fontWeight = FontWeight.SemiBold)
                                    Text(notice.body, maxLines = 2, overflow = TextOverflow.Ellipsis, color = Muted, fontSize = 13.sp)
                                }
                            }
                        }
                    }
                    Text("Read notices stay here until they expire or are removed.", color = Muted, fontSize = 12.sp)
                }
            }
        }
    }
}

@Composable
internal fun AnnouncementDetail(notice: Announcement, fromInbox: Boolean, close: () -> Unit) {
    val appearance = alertAppearance(notice.severity)
    Dialog(onDismissRequest = close) {
        Surface(shape = RoundedCornerShape(26.dp), color = Panel, contentColor = White,
            border = BorderStroke(1.dp, appearance.color.copy(alpha = .35f))) {
            Column(Modifier.fillMaxWidth().heightIn(max = 620.dp)) {
                Box(Modifier.fillMaxWidth().height(5.dp).background(appearance.color))
                Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                        AlertSymbol(appearance, large = true)
                        Column(Modifier.weight(1f)) {
                            Text(appearance.label.uppercase(), color = appearance.color, fontWeight = FontWeight.Bold, fontSize = 12.sp, letterSpacing = 1.sp)
                            Text("MY TV · SERVICE UPDATE", color = Muted, fontSize = 10.sp, letterSpacing = .6.sp)
                        }
                    }
                    Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                        Text(notice.title, fontSize = 25.sp, lineHeight = 31.sp, fontWeight = FontWeight.Bold)
                        Text(notice.body, color = White, fontSize = 16.sp, lineHeight = 25.sp)
                        Text("Until " + DateTimeFormatter.ofPattern("d MMM, HH:mm z").withZone(ZoneId.systemDefault()).format(Instant.ofEpochSecond(notice.expires)), color = Muted, fontSize = 12.sp)
                    }
                    Button(onClick = close, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = appearance.color, contentColor = Ink)) {
                        Text(if (fromInbox) "Back to notifications" else "Got it", modifier = Modifier.padding(vertical = 5.dp), fontWeight = FontWeight.Bold)
                    }
                    if (!fromInbox) Text("You can read this again using the notification bell.", color = Muted, fontSize = 12.sp)
                }
            }
        }
    }
}
