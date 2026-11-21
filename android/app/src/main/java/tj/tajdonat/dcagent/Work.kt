package tj.tajdonat.dcagent

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import android.os.Build
import androidx.core.app.NotificationManagerCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

object Work {
    private val net = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun syncNow(ctx: Context) {
        val req = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(net)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(ctx).enqueueUniqueWork("sync", ExistingWorkPolicy.REPLACE, req)
    }

    fun schedulePeriodic(ctx: Context) {
        val req = PeriodicWorkRequestBuilder<HeartbeatWorker>(15, TimeUnit.MINUTES).setConstraints(net).build()
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork("heartbeat", ExistingPeriodicWorkPolicy.KEEP, req)
    }

    fun listenerEnabled(ctx: Context): Boolean = NotificationManagerCompat.getEnabledListenerPackages(ctx).contains(ctx.packageName)

    /** Navbatdagi hamma bildirishnomalarni yuboradi. */
    fun pushPending(ctx: Context): Int {
        val prefs = Prefs(ctx)
        if (!prefs.registered) return 0
        val db = Db(ctx)
        var sent = 0
        while (true) {
            val list = db.pending(50)
            if (list.isEmpty()) break
            val arr = JSONArray()
            for (e in list) arr.put(
                JSONObject().put("uuid", e.uuid).put("pkg", e.pkg).put("title", e.title).put("text", e.text)
                    .put("amount", e.amount ?: JSONObject.NULL).put("commission", e.commission ?: JSONObject.NULL)
                    .put("direction", e.direction).put("posted_at", e.postedAt).put("test", e.test)
            )
            val res = Net.post(prefs, "event", JSONObject().put("events", arr))
            val ack = res.optJSONArray("ack") ?: JSONArray()
            val ids = (0 until ack.length()).map { ack.getString(it) }
            db.markSent(ids)
            sent += ids.size
            if (ids.size < list.size) break
        }
        prefs.lastSyncAt = System.currentTimeMillis()
        prefs.lastError = ""
        return sent
    }

    fun heartbeat(ctx: Context) {
        val prefs = Prefs(ctx)
        if (!prefs.registered) return
        val bat = ctx.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
        val level = bat?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
        val scale = bat?.getIntExtra(BatteryManager.EXTRA_SCALE, 100) ?: 100
        val plugged = (bat?.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0) ?: 0) != 0
        val db = Db(ctx)
        val body = JSONObject()
            .put("battery", if (level >= 0) level * 100 / scale else -1).put("charging", plugged)
            .put("listener", listenerEnabled(ctx)).put("pending", db.count(0))
            .put("dc_pkg", prefs.dcPkg).put("last_event_at", prefs.lastEventAt)
            .put("app_ver", BuildConfig.VERSION_NAME).put("model", Build.MANUFACTURER + " " + Build.MODEL + " / Android " + Build.VERSION.RELEASE)
        Net.post(prefs, "heartbeat", body)
        db.cleanup()
    }
}

class SyncWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result = try {
        Work.pushPending(applicationContext)
        Result.success()
    } catch (e: HttpError) {
        Prefs(applicationContext).lastError = "Server: ${e.code} ${e.message}"
        if (e.code == 401 || e.code == 403) Result.failure() else Result.retry()
    } catch (e: IOException) {
        Prefs(applicationContext).lastError = "Internet: ${e.message}"
        Result.retry()
    } catch (e: Exception) {
        Prefs(applicationContext).lastError = "Xato: ${e.message}"
        Result.retry()
    }
}

class HeartbeatWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result = try {
        Work.heartbeat(applicationContext)
        Work.pushPending(applicationContext)
        Result.success()
    } catch (e: Exception) {
        Prefs(applicationContext).lastError = "Heartbeat: ${e.message}"
        Result.success()
    }
}
