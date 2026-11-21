package tj.tajdonat.dcagent

import android.app.Notification
import android.content.pm.PackageManager
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import java.security.MessageDigest
import java.util.UUID

/** Faqat tanlangan DC ilovasining bildirishnomalarini o'qiydi. Boshqa ilovalarning matni saqlanmaydi va yuborilmaydi. */
class DcListener : NotificationListenerService() {

    override fun onListenerConnected() {
        Prefs(this).listenerConnectedAt = System.currentTimeMillis()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        val n = sbn ?: return
        try { handle(n) } catch (_: Throwable) { }
    }

    private fun handle(sbn: StatusBarNotification) {
        val pkg = sbn.packageName ?: return
        if (pkg == packageName) return
        val prefs = Prefs(this)
        val ex = sbn.notification?.extras ?: return
        val title = ex.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val big = ex.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString()
        val lines = ex.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.joinToString("\n") { it.toString() }
        val text = when {
            !big.isNullOrBlank() -> big
            !lines.isNullOrBlank() -> lines
            else -> ex.getCharSequence(Notification.EXTRA_TEXT)?.toString() ?: ""
        }

        if (prefs.dcPkg.isEmpty()) {
            // DC ilovasi hali tanlanmagan: nomida «DC» / «Dushanbe City» bo'lgan ilovadan pul xabari kelsa — o'sha ilovani eslab qolamiz
            if (!isDcLabel(pkg) || !Parser.looksLikeMoney(title, text)) return
            prefs.dcPkg = pkg
        } else if (pkg != prefs.dcPkg) return

        if (title.isBlank() && text.isBlank()) return
        if ((sbn.notification.flags and Notification.FLAG_GROUP_SUMMARY) != 0) return

        val r = Parser.parse(title, text)
        val dedupe = sha("$pkg|${sbn.postTime}|$title|$text")
        val ev = Ev(
            UUID.randomUUID().toString(), pkg, title.take(200), text.take(800),
            r.amount, r.commission, r.direction, sbn.postTime, false, 0
        )
        if (Db(this).insert(ev, dedupe)) {
            prefs.lastEventAt = System.currentTimeMillis()
            Work.syncNow(this)
        }
    }

    private fun isDcLabel(pkg: String): Boolean = try {
        val pm = packageManager
        val label = pm.getApplicationLabel(pm.getApplicationInfo(pkg, PackageManager.GET_META_DATA)).toString()
        Regex("(?i)\\bDC\\b|dushanbe ?city|душанбе ?сити").containsMatchIn(label) || Regex("(?i)(^|\\.)dc").containsMatchIn(pkg)
    } catch (_: Exception) { false }

    companion object {
        fun sha(s: String): String = MessageDigest.getInstance("SHA-256").digest(s.toByteArray()).joinToString("") { "%02x".format(it) }
    }
}
