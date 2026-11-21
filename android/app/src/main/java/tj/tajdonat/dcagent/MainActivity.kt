package tj.tajdonat.dcagent

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

class MainActivity : AppCompatActivity() {
    private lateinit var prefs: Prefs
    private lateinit var status: TextView
    private lateinit var recent: TextView
    private lateinit var serverIn: EditText
    private lateinit var codeIn: EditText
    private lateinit var pairBox: LinearLayout
    private val ui = Handler(Looper.getMainLooper())
    private val fmt = SimpleDateFormat("dd.MM HH:mm:ss", Locale.US)
    private val tick = object : Runnable { override fun run() { refresh(); ui.postDelayed(this, 2000) } }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = Prefs(this)
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(18), dp(28), dp(18), dp(28)); setBackgroundColor(Color.parseColor("#F2F4F9")) }
        root.addView(TextView(this).apply { text = "DC Agent"; textSize = 26f; setTypeface(null, Typeface.BOLD); setTextColor(Color.parseColor("#14142B")) })
        root.addView(TextView(this).apply { text = "DC Next bildirishnomalarini TajDonat serveriga yuboradi"; textSize = 13f; setTextColor(Color.parseColor("#6B6F88")); setPadding(0, dp(2), 0, dp(14)) })

        status = TextView(this).apply { textSize = 14f; setTextColor(Color.parseColor("#14142B")); setLineSpacing(0f, 1.25f) }
        root.addView(card(status))

        pairBox = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        serverIn = EditText(this).apply { setText(prefs.server); textSize = 13f; hint = "Server manzili"; inputType = InputType.TYPE_TEXT_VARIATION_URI }
        codeIn = EditText(this).apply { hint = "Paneldagi ulash kodi (8 belgi)"; textSize = 18f; inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS }
        pairBox.addView(label("1. Serverga ulash")); pairBox.addView(serverIn); pairBox.addView(codeIn)
        pairBox.addView(btn("Ulash", "#6D4AFF") { pair() })
        root.addView(card(pairBox))

        val acts = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        acts.addView(label("2. Ruxsatlar"))
        acts.addView(btn("Bildirishnomalarni o'qishga ruxsat", "#2B2B4A") { startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)) })
        acts.addView(btn("Batareya cheklovini o'chirish", "#2B2B4A") { askBattery() })
        acts.addView(btn("DC ilovasini tanlash", "#2B2B4A") { pickDc() })
        acts.addView(label("3. Tekshirish"))
        acts.addView(btn("Hozir yuborish", "#16A34A") { Work.syncNow(this); Work.schedulePeriodic(this); toast("Navbatga qo'yildi") })
        acts.addView(btn("Sinov xabari (pulsiz)", "#F59E0B") { testEvent() })
        root.addView(card(acts))

        recent = TextView(this).apply { textSize = 12.5f; setTextColor(Color.parseColor("#14142B")); typeface = Typeface.MONOSPACE }
        root.addView(card(LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; addView(label("Oxirgi bildirishnomalar")); addView(recent) }))

        setContentView(ScrollView(this).apply { addView(root) })
    }

    override fun onResume() { super.onResume(); ui.post(tick) }
    override fun onPause() { super.onPause(); ui.removeCallbacks(tick) }

    private fun card(v: View): View = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; setPadding(dp(16), dp(14), dp(16), dp(14))
        background = GradientDrawable().apply { setColor(Color.WHITE); cornerRadius = dp(18).toFloat() }
        layoutParams = LinearLayout.LayoutParams(-1, -2).apply { bottomMargin = dp(12) }
        addView(v)
    }

    private fun label(t: String) = TextView(this).apply { text = t; textSize = 13f; setTypeface(null, Typeface.BOLD); setTextColor(Color.parseColor("#6B6F88")); setPadding(0, dp(6), 0, dp(4)) }

    private fun btn(t: String, color: String, on: () -> Unit) = Button(this).apply {
        text = t; isAllCaps = false; textSize = 15f; setTextColor(Color.WHITE)
        background = GradientDrawable().apply { setColor(Color.parseColor(color)); cornerRadius = dp(14).toFloat() }
        layoutParams = LinearLayout.LayoutParams(-1, dp(48)).apply { topMargin = dp(8) }
        setOnClickListener { on() }
    }

    private fun toast(s: String) = Toast.makeText(this, s, Toast.LENGTH_LONG).show()

    private fun ago(t: Long): String = if (t <= 0) "—" else fmt.format(Date(t))

    @SuppressLint("SetTextI18n")
    private fun refresh() {
        val db = Db(this)
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        val batOk = pm.isIgnoringBatteryOptimizations(packageName)
        val lis = Work.listenerEnabled(this)
        pairBox.visibility = if (prefs.registered) View.GONE else View.VISIBLE
        status.text = buildString {
            append(if (prefs.registered) "✅ Serverga ulangan (qurilma #${prefs.deviceId})\n" else "⚪ Serverga ulanmagan\n")
            append(if (lis) "✅ Bildirishnoma ruxsati bor\n" else "❌ Bildirishnoma ruxsati YO'Q\n")
            append(if (batOk) "✅ Batareya cheklovi o'chirilgan\n" else "⚠️ Batareya cheklovi yoqiq\n")
            append(if (prefs.dcPkg.isNotEmpty()) "✅ DC ilovasi: ${prefs.dcPkg}\n" else "⚪ DC ilovasi: birinchi xabardan aniqlanadi\n")
            append("Navbatda: ${db.count(0)} · Yuborilgan: ${db.count(1)}\n")
            append("Oxirgi yuborish: ${ago(prefs.lastSyncAt)}\n")
            append("Oxirgi bildirishnoma: ${ago(prefs.lastEventAt)}")
            if (prefs.lastError.isNotEmpty()) append("\n⚠️ ${prefs.lastError}")
        }
        recent.text = db.recent(15).joinToString("\n") { e ->
            val d = when (e.direction) { "in" -> "+"; "out" -> "−"; else -> "?" }
            "${if (e.status == 1) "✓" else "…"} ${fmt.format(Date(e.postedAt))}  $d${e.amount ?: "?"}${if (e.test) " (sinov)" else ""}"
        }.ifEmpty { "Hozircha yo'q" }
    }

    private fun pair() {
        val code = codeIn.text.toString().trim().uppercase().replace(" ", "")
        if (code.length < 6) { toast("Kodni paneldan oling"); return }
        prefs.server = serverIn.text.toString()
        Thread {
            try {
                val r = Net.post(prefs, "register", JSONObject().put("code", code).put("model", Build.MANUFACTURER + " " + Build.MODEL).put("app_ver", BuildConfig.VERSION_NAME), signed = false)
                if (r.optBoolean("ok")) {
                    prefs.deviceId = r.getLong("device_id"); prefs.secret = r.getString("secret")
                    Work.schedulePeriodic(this); Work.syncNow(this)
                    Thread { try { Work.heartbeat(this) } catch (_: Exception) {} }.start()
                    runOnUiThread { toast("Ulandi ✅"); refresh() }
                } else runOnUiThread { toast(r.optString("message", "Xato")) }
            } catch (e: Exception) { runOnUiThread { toast("Xato: ${e.message}") } }
        }.start()
    }

    @SuppressLint("BatteryLife")
    private fun askBattery() {
        try { startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName"))) }
        catch (_: Exception) { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) }
    }

    private fun pickDc() {
        val pm = packageManager
        val main = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        val apps = (if (Build.VERSION.SDK_INT >= 33) pm.queryIntentActivities(main, android.content.pm.PackageManager.ResolveInfoFlags.of(0L)) else @Suppress("DEPRECATION") pm.queryIntentActivities(main, 0))
            .map { it.loadLabel(pm).toString() to it.activityInfo.packageName }
            .distinctBy { it.second }
            .sortedWith(compareByDescending<Pair<String, String>> { Regex("(?i)\\bDC\\b|dushanbe|душанбе").containsMatchIn(it.first) }.thenBy { it.first.lowercase() })
        AlertDialog.Builder(this).setTitle("DC ilovasini tanlang")
            .setItems(apps.map { "${it.first}\n${it.second}" }.toTypedArray()) { _, i -> prefs.dcPkg = apps[i].second; toast("Tanlandi: ${apps[i].first}"); refresh() }
            .setNegativeButton("Bekor", null).show()
    }

    private fun testEvent() {
        if (!prefs.registered) { toast("Avval serverga ulang"); return }
        val now = System.currentTimeMillis()
        val ev = Ev(UUID.randomUUID().toString(), packageName, "Sinov xabari", "Zachislenie\nSumma 0.01 TJS\nKomis 0.00 TJS\nZachislenie 0.01 TJS", "0.01", "0.00", "in", now, true, 0)
        Db(this).insert(ev, "test-$now")
        Work.syncNow(this)
        toast("Sinov xabari navbatga qo'yildi")
    }
}
