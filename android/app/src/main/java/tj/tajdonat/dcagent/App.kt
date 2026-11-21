package tj.tajdonat.dcagent

import android.app.Application

class App : Application() {
    override fun onCreate() {
        super.onCreate()
        Work.schedulePeriodic(this)
        Work.syncNow(this)
    }
}
