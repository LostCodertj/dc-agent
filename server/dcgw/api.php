<?php
// DC Gateway API — Android «DC Agent» shu yerga yuboradi.
//   ?act=register  — ulash kodi bilan qurilmani ro'yxatdan o'tkazish (imzosiz)
//   ?act=event     — bildirishnomalar (HMAC imzo)
//   ?act=heartbeat — telefon holati (HMAC imzo)
require __DIR__ . '/lib.php';
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dcgw_json(['ok' => false, 'message' => 'POST kerak'], 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 300000) dcgw_json(['ok' => false, 'message' => 'Juda katta'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) dcgw_json(['ok' => false, 'message' => 'JSON xato'], 400);
$act = (string)($_GET['act'] ?? '');

try { $pdo = dcgw_pdo(); } catch (Throwable $e) { dcgw_json(['ok' => false, 'message' => 'Server band, keyinroq'], 503); }

if ($act === 'register') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // oddiy cheklov: 10 daqiqada bitta IP dan 10 tadan ko'p urinish yo'q
    $c = $pdo->prepare("SELECT COUNT(*) FROM dcgw_log WHERE level = 'pair' AND msg LIKE ? AND ts > NOW() - INTERVAL 10 MINUTE"); $c->execute(['%' . $ip . '%']);
    if ((int)$c->fetchColumn() >= 10) dcgw_json(['ok' => false, 'message' => 'Ko\'p urinish. 10 daqiqadan keyin'], 429);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
    dcgw_log('pair', "urinish $ip " . substr($code, 0, 2) . '***');
    $pdo->beginTransaction();
    $s = $pdo->prepare("SELECT code FROM dcgw_pair WHERE code = ? AND used = 0 AND expires_at > NOW() FOR UPDATE"); $s->execute([$code]);
    if (!$s->fetchColumn()) { $pdo->rollBack(); dcgw_json(['ok' => false, 'message' => 'Kod noto\'g\'ri yoki eskirgan'], 400); }
    $pdo->prepare("UPDATE dcgw_pair SET used = 1 WHERE code = ?")->execute([$code]);
    $pdo->prepare("INSERT INTO dcgw_devices (name, model, app_ver, created_at) VALUES (?, ?, ?, NOW())")
        ->execute(['Telefon', mb_substr((string)($in['model'] ?? ''), 0, 120), mb_substr((string)($in['app_ver'] ?? ''), 0, 20)]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    dcgw_log('info', "Yangi qurilma ulandi #$id", $id);
    dcgw_tg("📱 DC Agent: yangi qurilma ulandi (#$id, " . mb_substr((string)($in['model'] ?? ''), 0, 60) . ")");
    dcgw_json(['ok' => true, 'device_id' => $id, 'secret' => dcgw_dev_secret($id)]);
}

// ---- imzo tekshiruvi ----
$dev = (int)($_SERVER['HTTP_X_DEV'] ?? 0); $ts = (int)($_SERVER['HTTP_X_TS'] ?? 0); $sig = strtolower((string)($_SERVER['HTTP_X_SIG'] ?? ''));
if ($dev <= 0 || abs(time() - $ts) > 900 || !preg_match('/^[0-9a-f]{64}$/', $sig)) dcgw_json(['ok' => false, 'message' => 'Imzo yo\'q yoki vaqt noto\'g\'ri'], 401);
$d = $pdo->prepare("SELECT * FROM dcgw_devices WHERE id = ?"); $d->execute([$dev]); $device = $d->fetch(PDO::FETCH_ASSOC);
if (!$device || (int)$device['revoked'] === 1) dcgw_json(['ok' => false, 'message' => 'Qurilma bloklangan yoki yo\'q'], 403);
if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, dcgw_dev_secret($dev)), $sig)) { dcgw_log('warn', 'Imzo xato', $dev); dcgw_json(['ok' => false, 'message' => 'Imzo xato'], 401); }

$pdo->prepare("UPDATE dcgw_devices SET last_seen = NOW() WHERE id = ?")->execute([$dev]);

if ($act === 'heartbeat') {
    $lis = !empty($in['listener']) ? 1 : 0;
    $pdo->prepare("UPDATE dcgw_devices SET battery = ?, charging = ?, listener = ?, pending = ?, app_ver = ?, dc_pkg = ?, model = IF(? <> '', ?, model) WHERE id = ?")
        ->execute([max(-1, min(100, (int)($in['battery'] ?? -1))), !empty($in['charging']) ? 1 : 0, $lis, max(0, (int)($in['pending'] ?? 0)),
            mb_substr((string)($in['app_ver'] ?? ''), 0, 20), mb_substr((string)($in['dc_pkg'] ?? ''), 0, 120),
            (string)($in['model'] ?? ''), mb_substr((string)($in['model'] ?? ''), 0, 120), $dev]);
    if ((int)$device['alerted'] === 1) { $pdo->prepare("UPDATE dcgw_devices SET alerted = 0 WHERE id = ?")->execute([$dev]); dcgw_tg("✅ DC Agent #$dev yana onlayn."); }
    if (!$lis && (int)$device['listener'] === 1) dcgw_tg("⚠️ DC Agent #$dev: bildirishnoma ruxsati o'chib qolgan. Telefonda qayta yoqing.");
    dcgw_json(['ok' => true, 'server_time' => time()]);
}

if ($act === 'event') {
    $evs = $in['events'] ?? null;
    if (!is_array($evs) || count($evs) > 100) dcgw_json(['ok' => false, 'message' => 'events xato'], 400);
    $ack = []; $new = [];
    $ins = $pdo->prepare("INSERT IGNORE INTO dcgw_events (uuid, device_id, pkg, title, text, amount, commission, direction, is_test, posted_at, received_at, status, dup_of)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
    $dupQ = $pdo->prepare("SELECT id FROM dcgw_events WHERE device_id = ? AND is_test = 0 AND amount <=> ? AND text = ? AND posted_at BETWEEN ? - INTERVAL 90 SECOND AND ? + INTERVAL 90 SECOND AND dup_of IS NULL LIMIT 1");
    foreach ($evs as $e) {
        if (!is_array($e)) continue;
        $uuid = strtolower((string)($e['uuid'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) continue;
        $title = mb_substr((string)($e['title'] ?? ''), 0, 200); $text = mb_substr((string)($e['text'] ?? ''), 0, 800);
        [$pa, $pk, $pd] = dcgw_parse($title, $text);
        $amt = dcgw_norm($e['amount'] ?? null) ?? $pa; $kom = dcgw_norm($e['commission'] ?? null) ?? $pk;
        $dir = in_array($e['direction'] ?? '', ['in', 'out', 'unknown'], true) ? $e['direction'] : $pd;
        if ($dir === 'unknown') $dir = $pd;
        $test = !empty($e['test']) ? 1 : 0;
        $ms = (int)($e['posted_at'] ?? 0); $posted = ($ms > 1500000000000 && $ms < (time() + 86400) * 1000) ? date('Y-m-d H:i:s', intdiv($ms, 1000)) : date('Y-m-d H:i:s');
        $dupOf = null;
        if (!$test) { $dupQ->execute([$dev, $amt, $text, $posted, $posted]); $dupOf = $dupQ->fetchColumn() ?: null; }
        $status = $test ? 'test' : ($dupOf ? 'dup' : ($dir === 'out' ? 'out' : ($amt === null ? 'review' : 'new')));
        $ins->execute([$uuid, $dev, mb_substr((string)($e['pkg'] ?? ''), 0, 120), $title, $text, $amt, $kom, $dir, $test, $posted, $status, $dupOf]);
        if ($ins->rowCount() > 0) $new[] = [$status, $amt, $posted, $test];
        $ack[] = $uuid;
    }
    foreach ($new as [$st, $amt, $posted, $test]) {
        if ($st === 'new') dcgw_tg("💰 DC: +" . ($amt ?? '?') . " TJS · " . substr($posted, 11, 5));
        elseif ($st === 'review') dcgw_tg("🟡 DC: summa aniqlanmadi — panelda ko'ring.");
        elseif ($st === 'test') dcgw_tg("🧪 DC Agent sinov xabari keldi — ulanish ishlayapti.");
    }
    dcgw_json(['ok' => true, 'ack' => $ack]);
}

dcgw_json(['ok' => false, 'message' => 'act noma\'lum'], 400);
