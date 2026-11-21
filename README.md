# DC Agent — TajDonat

DC Next bildirishnomalarini ushlab, TajDonat serveriga yuboradigan tizim.

- `android/` — Samsung (Android 8+) uchun «DC Agent» ilovasi. Har `push` da GitHub Actions APK yig'adi va **Releases → apk-latest** ga qo'yadi.
- `server/dcgw/` — server qismi: `api.php` (telefon shu yerga yuboradi), `panel.php` (admin paneli), `cron.php` (telefon jim qolsa ogohlantirish), `install.sql`.

## Xavfsizlik
- Bank paroli, PIN, SMS kod so'ralmaydi va saqlanmaydi.
- Faqat tanlangan DC ilovasining bildirishnomasi o'qiladi.
- Har so'rov HMAC-SHA256 bilan imzolanadi; qurilma kaliti serverdagi `config.local.php` dagi master kalitdan hosil qilinadi (repoda yo'q).
- 1-bosqichda pul avtomatik tushmaydi: bildirishnomalar panelda ko'rinadi va qo'lda tasdiqlanadi. Botlarga ulash — 2-bosqich.
