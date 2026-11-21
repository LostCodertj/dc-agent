package tj.tajdonat.dcagent

import java.math.BigDecimal
import java.math.RoundingMode

/** DC Next bildirishnomasidan summa va yo'nalishni ajratib olish.
 *  Namuna: «Зачисление средств» / «Zachislenie\nSumma 50.00 TJS\nKomis 0.00 TJS\nZachislenie 50.00 TJS». */
object Parser {
    private const val NUM = "([0-9][0-9 \\u00A0.,]*)"
    private val reCredit = Regex("(?i)(zachislenie|зачисление)\\s*:?\\s*$NUM\\s*(TJS|сом|smn)")
    private val reSumma = Regex("(?i)(summa|сумма)\\s*:?\\s*$NUM\\s*(TJS|сом|smn)")
    private val reKomis = Regex("(?i)(komis|комис)[a-zа-я]*\\s*:?\\s*$NUM\\s*(TJS|сом|smn)")
    private val reIn = Regex("(?i)зачислен|zachislen|пополнен|popolnen|поступлен")
    private val reOut = Regex("(?i)списан|spisan|оплата|oplata|перевод выполнен|снятие")

    data class Result(val amount: String?, val commission: String?, val direction: String)

    fun parse(title: String, text: String): Result {
        val all = "$title\n$text"
        val amount = (reCredit.find(text) ?: reSumma.find(all))?.groupValues?.get(2)?.let { norm(it) }
        val komis = reKomis.find(all)?.groupValues?.get(2)?.let { norm(it) }
        val dir = when {
            reIn.containsMatchIn(title) || reIn.containsMatchIn(text) -> "in"
            reOut.containsMatchIn(title) || reOut.containsMatchIn(text) -> "out"
            else -> "unknown"
        }
        return Result(amount, komis, dir)
    }

    fun looksLikeMoney(title: String, text: String): Boolean = reSumma.containsMatchIn("$title\n$text") || reCredit.containsMatchIn(text)

    /** «1 000,50» / «1,000.50» / «1000.5» → «1000.50» */
    fun norm(raw: String): String? {
        var s = raw.replace(" ", "").replace(" ", "").trimEnd('.', ',')
        if (s.isEmpty()) return null
        s = if (s.contains('.') && s.contains(',')) s.replace(",", "") else s.replace(',', '.')
        val dots = s.count { it == '.' }
        if (dots > 1) { val i = s.lastIndexOf('.'); s = s.substring(0, i).replace(".", "") + s.substring(i) }
        return try { BigDecimal(s).setScale(2, RoundingMode.HALF_UP).toPlainString() } catch (e: Exception) { null }
    }
}
