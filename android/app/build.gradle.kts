plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "tj.tajdonat.dcagent"
    compileSdk = 34

    defaultConfig {
        applicationId = "tj.tajdonat.dcagent"
        minSdk = 26
        targetSdk = 34
        versionCode = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()
        versionName = "1.0." + (System.getenv("GITHUB_RUN_NUMBER") ?: "0")
        buildConfigField("String", "DEFAULT_SERVER", "\"https://tajdonat.tj/Donat/api/dcgw/api.php\"")
    }

    signingConfigs {
        create("agent") {
            storeFile = file("dcagent.jks")
            storePassword = "dcagent123"
            keyAlias = "dcagent"
            keyPassword = "dcagent123"
        }
    }

    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("agent")
        }
        getByName("debug") {
            signingConfig = signingConfigs.getByName("agent")
        }
    }

    buildFeatures { buildConfig = true }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
}
