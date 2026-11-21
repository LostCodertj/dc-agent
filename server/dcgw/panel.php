<?php
// DC Gateway — nazorat paneli (faqat admin). Manzil: https://tajdonat.tj/Donat/api/dcgw/panel.php
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// --- kirish: tajdonat.tj sayt sessiyasi (admin raqami) ---
$uid = ''; $authErr = '';
try {
    $pdo0 = dcgw_pdo();                                   // config.php + db.php (pkg_img.php kabi)
    require_once dcgw_api_dir() . '/web_lib.php';
    $sess = function_exists('web_session') ? web_session($pdo0) : null;
    if ($sess) $uid = (string)($sess['telegram_id'] ?? '');
} catch (Throwable $e) { $uid = ''; $authErr = $e->getMessage(); }
$isAdm = $uid !== '' && in_array($uid, dcgw_admins(), true);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!$isAdm) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DC Tushum</title>'
       . '<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f0f4f9;display:grid;place-items:center;min-height:100vh;margin:0">'
       . '<div style="background:#fff;padding:28px;border-radius:22px;max-width:340px;text-align:center"><h2 style="margin:0 0 8px">🔒 Faqat admin</h2>'
       . '<p style="color:#6b6f88">tajdonat.tj saytiga admin raqami bilan kiring, keyin shu sahifani qayta oching.</p>'
       . ($authErr !== '' ? '<p style="color:#e5484d;font-size:12px">' . $h($authErr) . '</p>' : ($uid !== '' ? '<p style="color:#9aa0b4;font-size:12px">ID ' . $h($uid) . ' admin ro\'yxatida yo\'q</p>' : '')) . '<a href="https://tajdonat.tj/?login=1" style="display:inline-block;margin-top:10px;background:#6d4aff;color:#fff;padding:12px 20px;border-radius:14px;text-decoration:none;font-weight:700">Kirish</a></div>';
    exit;
}

$pdo = dcgw_pdo();
$csrf = hash_hmac('sha256', 'csrf:' . $uid . ':' . date('Ymd'), dcgw_master());
$flash = '';

// --- amallar ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('CSRF xato — sahifani yangilang'); }
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'decide') {
        $id = (int)($_POST['id'] ?? 0); $to = (string)($_POST['to'] ?? ''); $why = mb_substr(trim((string)($_POST['why'] ?? '')), 0, 255);
        if ($id > 0 && in_array($to, ['ok', 'rejected', 'review'], true)) {
            $pdo->beginTransaction();
            $s = $pdo->prepare("SELECT status FROM dcgw_events WHERE id = ? FOR UPDATE"); $s->execute([$id]); $old = $s->fetchColumn();
            if ($old !== false && in_array($old, ['new', 'review', 'rejected', 'ok', 'unknown'], true)) {
                $pdo->prepare("UPDATE dcgw_events SET status = ?, decided_by = ?, decided_at = NOW(), note = ? WHERE id = ?")->execute([$to, $uid, $why, $id]);
                $pdo->prepare("INSERT INTO dcgw_audit (ts, admin_id, event_id, action, reason) VALUES (NOW(), ?, ?, ?, ?)")->execute([$uid, $id, "$old>$to", $why]);
                $flash = "#$id: " . DCGW_STATUS[$to][0];
            }
            $pdo->commit();
        }
    } elseif ($a === 'pair') {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
        for ($i = 0; $i < 8; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
        $pdo->prepare("INSERT INTO dcgw_pair (code, expires_at, used, created_by) VALUES (?, NOW() + INTERVAL 15 MINUTE, 0, ?)")->execute([$code, $uid]);
        $flash = 'PAIR:' . $code;
    } elseif ($a === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE dcgw_devices SET revoked = 1 - revoked WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO dcgw_audit (ts, admin_id, event_id, action, reason) VALUES (NOW(), ?, NULL, 'revoke', ?)")->execute([$uid, "device #$id"]);
        $flash = "Qurilma #$id holati o'zgardi";
    }
    if (strpos($flash, 'PAIR:') !== 0) { header('Location: panel.php?f=' . urlencode((string)($_GET['f'] ?? 'all')) . '&m=' . urlencode($flash)); exit; }
}
if ($flash === '' && isset($_GET['m'])) $flash = mb_substr((string)$_GET['m'], 0, 80);

// --- ma'lumotlar ---
$sum = function (string $since) use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) s FROM dcgw_events WHERE direction = 'in' AND is_test = 0 AND status NOT IN ('dup','rejected') AND posted_at >= $since");
    $s->execute(); return $s->fetch(PDO::FETCH_ASSOC);
};
$today = $sum('CURDATE()'); $week = $sum('CURDATE() - INTERVAL 6 DAY'); $month = $sum('CURDATE() - INTERVAL 29 DAY');
$cnt = []; foreach ($pdo->query("SELECT status, COUNT(*) n FROM dcgw_events GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[$r['status']] = (int)$r['n'];
$devs = $pdo->query("SELECT *, TIMESTAMPDIFF(MINUTE, last_seen, NOW()) ago FROM dcgw_devices ORDER BY revoked, id")->fetchAll(PDO::FETCH_ASSOC);

$F = ['all' => 'Hammasi', 'in' => 'Kirim', 'review' => 'Ko\'rik', 'ok' => 'Tasdiqlangan', 'rejected' => 'Rad', 'out' => 'Chiqim', 'dup' => 'Takror', 'test' => 'Sinov'];
$f = isset($F[$_GET['f'] ?? '']) ? $_GET['f'] : 'all';
$where = ['all' => "status <> 'test'", 'in' => "direction = 'in' AND status IN ('new','ok')", 'review' => "status = 'review'", 'ok' => "status = 'ok'",
          'rejected' => "status = 'rejected'", 'out' => "status = 'out'", 'dup' => "status = 'dup'", 'test' => "status = 'test'"][$f];
$pg = max(0, (int)($_GET['p'] ?? 0));
$list = $pdo->query("SELECT * FROM dcgw_events WHERE $where ORDER BY posted_at DESC, id DESC LIMIT 60 OFFSET " . ($pg * 60))->fetchAll(PDO::FETCH_ASSOC);
$money = fn($v) => number_format((float)$v, 2, '.', ' ');
?><!doctype html>
<html lang="uz"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f0f4f9"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="DC Tushum">
<title>DC Tushum</title>
<style>
:root{--bg:#f0f4f9;--card:#fff;--tx:#14142b;--mu:#6b6f88;--line:#e6e8f0;--acc:#6d4aff;--ok:#16a34a;--bad:#e5484d;--warn:#f59e0b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.4 -apple-system,"SF Pro Text",Segoe UI,Roboto,sans-serif;padding:calc(env(safe-area-inset-top) + 14px) max(14px,env(safe-area-inset-right)) calc(env(safe-area-inset-bottom) + 30px) max(14px,env(safe-area-inset-left))}
.wrap{max-width:720px;margin:0 auto;display:grid;gap:14px}
h1{margin:0;font-size:24px;font-weight:800;letter-spacing:-.02em;display:flex;align-items:center;gap:10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:14px 16px}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.st small{display:block;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--mu)}
.st b{display:block;font-size:19px;margin-top:4px;font-variant-numeric:tabular-nums}.st span{font-size:12px;color:var(--mu)}
.dev{display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--line)}.dev:first-of-type{border-top:0}
.dot{width:10px;height:10px;border-radius:50%;flex:none}
.chips{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none}.chips::-webkit-scrollbar{display:none}
.chip{flex:none;padding:7px 12px;border-radius:11px;background:var(--card);border:1px solid var(--line);color:var(--tx);text-decoration:none;font-weight:700;font-size:13px}
.chip.on{background:var(--tx);color:#fff;border-color:var(--tx)}.chip i{font-style:normal;color:var(--mu);margin-left:4px;font-weight:600}.chip.on i{color:#cfd2e6}
.ev{display:grid;grid-template-columns:1fr auto;gap:4px 10px;padding:12px 0;border-top:1px solid var(--line)}.ev:first-child{border-top:0}
.amt{font-size:19px;font-weight:800;font-variant-numeric:tabular-nums}.amt.in{color:var(--ok)}.amt.out{color:var(--mu)}
.meta{font-size:12.5px;color:var(--mu)}.raw{grid-column:1/-1;font-size:12px;color:var(--mu);white-space:pre-line;max-height:3.2em;overflow:hidden}
.badge{font-size:11.5px;font-weight:800;padding:3px 8px;border-radius:8px;color:#fff;align-self:start;justify-self:end}
.acts{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.acts input{flex:1 1 140px;min-width:0;border:1px solid var(--line);border-radius:11px;padding:8px 10px;font:inherit;font-size:13px}
button{font:inherit;font-weight:700;border:0;border-radius:11px;padding:8px 12px;cursor:pointer}
.b-ok{background:var(--ok);color:#fff}.b-bad{background:#fde8e9;color:var(--bad)}.b-acc{background:var(--acc);color:#fff;width:100%;padding:12px}.b-ghost{background:var(--bg);color:var(--tx)}
.flash{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534;border-radius:14px;padding:10px 14px;font-weight:600}
.code{font:800 32px ui-monospace,Menlo,monospace;letter-spacing:.18em;text-align:center;background:var(--bg);border-radius:14px;padding:14px;margin:8px 0}
.empty{color:var(--mu);text-align:center;padding:24px 0}
.pager{display:flex;justify-content:space-between}
</style></head><body><div class="wrap">
<h1>💰 DC Tushum <span style="margin-left:auto;font-size:12px;color:var(--mu);font-weight:600"><?= date('d.m H:i') ?></span></h1>
<?php if ($flash !== '' && strpos($flash, 'PAIR:') === 0): ?>
  <div class="card"><b>Yangi qurilmani ulash kodi</b><div class="code"><?= $h(substr($flash, 5)) ?></div>
  <div class="meta">15 daqiqa amal qiladi. Samsung'dagi DC Agent → «Ulash» maydoniga yozing.</div></div>
<?php elseif ($flash !== ''): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

<div class="stats">
  <div class="card st"><small>Bugun</small><b><?= $money($today['s']) ?></b><span><?= (int)$today['n'] ?> ta to'lov</span></div>
  <div class="card st"><small>7 kun</small><b><?= $money($week['s']) ?></b><span><?= (int)$week['n'] ?> ta</span></div>
  <div class="card st"><small>30 kun</small><b><?= $money($month['s']) ?></b><span><?= (int)$month['n'] ?> ta</span></div>
</div>

<div class="card">
  <b>📱 Telefon</b>
  <?php if (!$devs): ?><div class="empty">Hali ulanmagan. Pastdagi tugma bilan kod oling.</div><?php endif; ?>
  <?php foreach ($devs as $d): $on = !$d['revoked'] && $d['last_seen'] !== null && (int)$d['ago'] <= 20; ?>
    <div class="dev">
      <span class="dot" style="background:<?= $d['revoked'] ? '#9aa0b4' : ($on ? 'var(--ok)' : 'var(--bad)') ?>"></span>
      <div style="flex:1;min-width:0">
        <b>#<?= (int)$d['id'] ?> <?= $h($d['model'] ?: $d['name']) ?></b>
        <div class="meta"><?= $d['revoked'] ? 'Bloklangan' : ($d['last_seen'] === null ? 'Signal kelmagan' : ($on ? 'Onlayn' : 'Jim') . ' · ' . $h(substr((string)$d['last_seen'], 5, 11))) ?>
          · 🔋<?= (int)$d['battery'] >= 0 ? (int)$d['battery'] . '%' : '?' ?><?= $d['charging'] ? '⚡' : '' ?>
          · <?= $d['listener'] ? 'ruxsat ✅' : 'ruxsat ❌' ?> · navbat <?= (int)$d['pending'] ?> · v<?= $h($d['app_ver']) ?></div>
      </div>
      <form method="post" onsubmit="return confirm('Qurilma holatini o\'zgartirasizmi?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="a" value="revoke"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <button class="b-ghost" type="submit"><?= $d['revoked'] ? 'Yoqish' : 'Bloklash' ?></button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="a" value="pair"><button class="b-acc" type="submit">+ Yangi telefonni ulash kodi</button></form>
</div>

<div class="chips">
  <?php foreach ($F as $k => $t): $n = $k === 'all' ? null : ($k === 'in' ? (($cnt['new'] ?? 0) + ($cnt['ok'] ?? 0)) : ($cnt[$k] ?? 0)); ?>
    <a class="chip<?= $f === $k ? ' on' : '' ?>" href="?f=<?= $k ?>"><?= $h($t) ?><?php if ($n !== null): ?><i><?= $n ?></i><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$list): ?><div class="empty">Hozircha yozuv yo'q</div><?php endif; ?>
  <?php foreach ($list as $e): [$sl, $sc] = DCGW_STATUS[$e['status']] ?? [$e['status'], '#9aa0b4']; ?>
    <div class="ev">
      <div><div class="amt <?= $h($e['direction']) ?>"><?= $e['direction'] === 'out' ? '−' : '+' ?><?= $e['amount'] !== null ? $money($e['amount']) : '?' ?> <span style="font-size:13px;color:var(--mu)">TJS</span></div>
        <div class="meta">#<?= (int)$e['id'] ?> · <?= $h(substr((string)$e['posted_at'], 5, 11)) ?><?= $e['commission'] !== null && (float)$e['commission'] > 0 ? ' · komissiya ' . $money($e['commission']) : '' ?><?= $e['note'] ? ' · ' . $h($e['note']) : '' ?></div></div>
      <span class="badge" style="background:<?= $sc ?>"><?= $h($sl) ?></span>
      <div class="raw"><?= $h($e['title'] . "\n" . $e['text']) ?></div>
      <?php if (in_array($e['status'], ['new', 'review'], true)): ?>
        <form class="acts" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          <input name="why" placeholder="Izoh (ixtiyoriy)" maxlength="255">
          <button class="b-ok" name="to" value="ok">✅ Tasdiqlash</button><button class="b-bad" name="to" value="rejected">❌ Rad</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="pager"><?php if ($pg > 0): ?><a class="chip" href="?f=<?= $f ?>&p=<?= $pg - 1 ?>">‹ Yangiroq</a><?php else: ?><span></span><?php endif; ?>
  <?php if (count($list) === 60): ?><a class="chip" href="?f=<?= $f ?>&p=<?= $pg + 1 ?>">Eskiroq ›</a><?php endif; ?></div>
</div>
<div class="meta" style="text-align:center">Sahifa har 30 soniyada yangilanadi · Faqat admin ko'radi</div>
</div>
<script>
setInterval(function(){var a=document.activeElement;if(document.visibilityState==='visible'&&!(a&&(a.tagName==='INPUT'||a.tagName==='TEXTAREA'))&&!document.querySelector('.code'))location.reload()},30000);
</script>
</body></html>
