package tj.tajdonat.dcagent

import android.content.Context
import android.content.SharedPreferences

/** Oddiy sozlamalar (faqat shu ilova ichida saqlanadi). */
class Prefs(ctx: Context) {
    private val p: SharedPreferences = ctx.getSharedPreferences("dcagent", Context.MODE_PRIVATE)

    var server: String
        get() = p.getString("server", BuildConfig.DEFAULT_SERVER) ?: BuildConfig.DEFAULT_SERVER
        set(v) = p.edit().putString("server", v.trim()).apply()
    var deviceId: Long
        get() = p.getLong("device_id", 0L)
        set(v) = p.edit().putLong("device_id", v).apply()
    var secret: String
        get() = p.getString("secret", "") ?: ""
        set(v) = p.edit().putString("secret", v).apply()
    var dcPkg: String
        get() = p.getString("dc_pkg", "") ?: ""
        set(v) = p.edit().putString("dc_pkg", v).apply()
    var lastSyncAt: Long
        get() = p.getLong("last_sync", 0L)
        set(v) = p.edit().putLong("last_sync", v).apply()
    var lastError: String
        get() = p.getString("last_error", "") ?: ""
        set(v) = p.edit().putString("last_error", v).apply()
    var lastEventAt: Long
        get() = p.getLong("last_event", 0L)
        set(v) = p.edit().putLong("last_event", v).apply()
    var listenerConnectedAt: Long
        get() = p.getLong("listener_at", 0L)
        set(v) = p.edit().putLong("listener_at", v).apply()

    val registered: Boolean get() = deviceId > 0 && secret.isNotEmpty()
}
