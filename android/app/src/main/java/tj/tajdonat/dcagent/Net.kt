package tj.tajdonat.dcagent

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

class HttpError(val code: Int, msg: String) : Exception(msg)

/** Serverga so'rov. Har bir so'rov HMAC-SHA256 bilan imzolanadi: sig = HMAC(secret, ts + "." + body). */
object Net {
    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).writeTimeout(30, TimeUnit.SECONDS).build()
    private val JSON = "application/json; charset=utf-8".toMediaType()

    fun post(prefs: Prefs, act: String, body: JSONObject, signed: Boolean = true): JSONObject {
        val raw = body.toString()
        val url = prefs.server + (if (prefs.server.contains("?")) "&" else "?") + "act=" + act
        val b = Request.Builder().url(url).post(raw.toRequestBody(JSON)).header("User-Agent", "DcAgent/" + BuildConfig.VERSION_NAME)
        if (signed) {
            val ts = (System.currentTimeMillis() / 1000).toString()
            b.header("X-Dev", prefs.deviceId.toString()).header("X-Ts", ts).header("X-Sig", hmac(prefs.secret, "$ts.$raw"))
        }
        client.newCall(b.build()).execute().use { r ->
            val txt = r.body?.string() ?: ""
            val j = try { JSONObject(txt) } catch (_: Exception) { JSONObject().put("ok", false).put("message", "HTTP ${r.code}: " + txt.take(120)) }
            if (!r.isSuccessful) throw HttpError(r.code, j.optString("message", "HTTP ${r.code}"))
            return j
        }
    }

    fun hmac(key: String, data: String): String {
        val m = Mac.getInstance("HmacSHA256")
        m.init(SecretKeySpec(key.toByteArray(), "HmacSHA256"))
        return m.doFinal(data.toByteArray()).joinToString("") { "%02x".format(it) }
    }
}
