package tj.tajdonat.dcagent

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        Work.schedulePeriodic(context)
        Work.syncNow(context)
    }
}
