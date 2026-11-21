package tj.tajdonat.dcagent

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

data class Ev(
    val uuid: String, val pkg: String, val title: String, val text: String,
    val amount: String?, val commission: String?, val direction: String,
    val postedAt: Long, val test: Boolean, val status: Int
)

/** Navbat: internet bo'lmasa ham bildirishnomalar shu yerda saqlanadi va keyin yuboriladi. status: 0 — yuborilmagan, 1 — server qabul qildi. */
class Db(ctx: Context) : SQLiteOpenHelper(ctx, "dcagent.db", null, 1) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            "CREATE TABLE ev (uuid TEXT PRIMARY KEY, dedupe TEXT UNIQUE, pkg TEXT, title TEXT, text TEXT, amount TEXT, commission TEXT, " +
                "direction TEXT, posted_at INTEGER, test INTEGER DEFAULT 0, status INTEGER DEFAULT 0, tries INTEGER DEFAULT 0, created_at INTEGER)"
        )
        db.execSQL("CREATE INDEX ev_status ON ev(status)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {}

    /** true — yangi yozildi, false — avval bor edi (takror). */
    fun insert(e: Ev, dedupe: String): Boolean {
        val v = ContentValues().apply {
            put("uuid", e.uuid); put("dedupe", dedupe); put("pkg", e.pkg); put("title", e.title); put("text", e.text)
            put("amount", e.amount); put("commission", e.commission); put("direction", e.direction)
            put("posted_at", e.postedAt); put("test", if (e.test) 1 else 0); put("status", 0); put("created_at", System.currentTimeMillis())
        }
        return writableDatabase.insertWithOnConflict("ev", null, v, SQLiteDatabase.CONFLICT_IGNORE) != -1L
    }

    fun pending(limit: Int = 50): List<Ev> = query("status = 0", "posted_at ASC", limit)
    fun recent(limit: Int = 15): List<Ev> = query(null, "posted_at DESC", limit)

    fun markSent(uuids: Collection<String>) {
        if (uuids.isEmpty()) return
        val db = writableDatabase
        db.beginTransaction()
        try {
            for (u in uuids) db.execSQL("UPDATE ev SET status = 1 WHERE uuid = ?", arrayOf(u))
            db.setTransactionSuccessful()
        } finally { db.endTransaction() }
    }

    fun count(status: Int): Int =
        readableDatabase.rawQuery("SELECT COUNT(*) FROM ev WHERE status = ?", arrayOf(status.toString())).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    /** 30 kundan eski, yuborilgan yozuvlarni tozalash. */
    fun cleanup() {
        writableDatabase.execSQL("DELETE FROM ev WHERE status = 1 AND posted_at < ?", arrayOf(System.currentTimeMillis() - 30L * 86400000L))
    }

    private fun query(where: String?, order: String, limit: Int): List<Ev> {
        val out = ArrayList<Ev>()
        readableDatabase.query("ev", null, where, null, null, null, order, limit.toString()).use { c ->
            while (c.moveToNext()) {
                out.add(
                    Ev(
                        c.getString(c.getColumnIndexOrThrow("uuid")), c.getString(c.getColumnIndexOrThrow("pkg")) ?: "",
                        c.getString(c.getColumnIndexOrThrow("title")) ?: "", c.getString(c.getColumnIndexOrThrow("text")) ?: "",
                        c.getString(c.getColumnIndexOrThrow("amount")), c.getString(c.getColumnIndexOrThrow("commission")),
                        c.getString(c.getColumnIndexOrThrow("direction")) ?: "unknown", c.getLong(c.getColumnIndexOrThrow("posted_at")),
                        c.getInt(c.getColumnIndexOrThrow("test")) == 1, c.getInt(c.getColumnIndexOrThrow("status"))
                    )
                )
            }
        }
        return out
    }
}
