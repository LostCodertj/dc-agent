<?php
// DC Gateway — umumiy funksiyalar (TajDonat). Mustaqil modul: o'z jadvallari (dcgw_*), mavjud botlarga tegmaydi.
if (defined('DCGW_LIB')) return;
define('DCGW_LIB', 1);
date_default_timezone_set('Asia/Dushanbe');

function dcgw_api_dir(): string { return dirname(__DIR__); }

/** MySQL ulanish — mavjud config.php / db.php orqali (sir kalitlar shu fayllarda qoladi). */
function dcgw_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (isset($GLOBALS['DCGW_PDO']) && $GLOBALS['DCGW_PDO'] instanceof PDO) { $pdo = $GLOBALS['DCGW_PDO']; $pdo->exec("SET time_zone = '+05:00'"); return $pdo; }
    $A = dcgw_api_dir();
    $cwd = getcwd(); chdir($A);
    ob_start();
    if (!function_exists('env')) require_once $A . '/config.php';
    require $A . '/db.php';
    ob_end_clean();
    chdir($cwd ?: $A);
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('DB yo\'q');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+05:00'");
    return $pdo;
}

function dcgw_cfg(): array {
    static $c = null;
    if ($c === null) { $f = __DIR__ . '/config.local.php'; $c = is_file($f) ? (array)(require $f) : []; }
    return $c;
}
function dcgw_master(): string { $m = (string)(dcgw_cfg()['master'] ?? ''); if (strlen($m) < 32) throw new RuntimeException('master kalit yo\'q'); return $m; }
function dcgw_dev_secret(int $id): string { return hash_hmac('sha256', 'dcgw-dev:' . $id, dcgw_master()); }

function dcgw_env(string $k, string $d = ''): string { return function_exists('env') ? (string)env($k, $d) : (string)(getenv($k) ?: $d); }

function dcgw_admins(): array {
    $ids = [dcgw_env('ADMIN_ID')];
    foreach (preg_split('/[\s,;]+/', dcgw_env('ADMIN_IDS')) as $x) $ids[] = $x;
    $f = dcgw_api_dir() . '/.site_admins';
    if (is_file($f)) foreach (preg_split('/[\s,;]+/', (string)file_get_contents($f)) as $x) $ids[] = $x;
    return array_values(array_unique(array_filter(array_map('trim', $ids), fn($x) => preg_match('/^\d{5,}$/', $x))));
}

function dcgw_log(string $level, string $msg, ?int $dev = null): void {
    try { dcgw_pdo()->prepare("INSERT INTO dcgw_log (ts, level, device_id, msg) VALUES (NOW(), ?, ?, ?)")->execute([$level, $dev, mb_substr($msg, 0, 500)]); } catch (Throwable $e) {}
}

/** Telegram xabari adminga (asosiy bot orqali). */
function dcgw_tg(string $text): void {
    $tok = dcgw_env('BOT_TOKEN'); $to = dcgw_env('ADMIN_ID');
    if ($tok === '' || $to === '') return;
    $ch = curl_init("https://api.telegram.org/bot$tok/sendMessage");
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => ['chat_id' => $to, 'text' => $text, 'disable_web_page_preview' => 1]]);
    curl_exec($ch); curl_close($ch);
}

/** Server tomonida ham summani ajratish (agar telefon ajrata olmagan bo'lsa). */
function dcgw_parse(string $title, string $text): array {
    $num = '([0-9][0-9 \x{00A0}.,]*)';
    $amt = null; $kom = null;
    if (preg_match('/(zachislenie|зачисление)\s*:?\s*' . $num . '\s*(TJS|сом|smn)/iu', $text, $m)) $amt = dcgw_norm($m[2]);
    elseif (preg_match('/(summa|сумма)\s*:?\s*' . $num . '\s*(TJS|сом|smn)/iu', "$title\n$text", $m)) $amt = dcgw_norm($m[2]);
    if (preg_match('/(komis|комис)[a-zа-я]*\s*:?\s*' . $num . '\s*(TJS|сом|smn)/iu', "$title\n$text", $m)) $kom = dcgw_norm($m[2]);
    $all = "$title\n$text";
    $dir = preg_match('/зачислен|zachislen|пополнен|popolnen|поступлен/iu', $all) ? 'in' : (preg_match('/списан|spisan|оплата|oplata|снятие/iu', $all) ? 'out' : 'unknown');
    return [$amt, $kom, $dir];
}
function dcgw_norm($s): ?string {
    if ($s === null) return null;
    $s = str_replace([' ', "\u{00A0}"], '', trim((string)$s)); $s = rtrim($s, '.,');
    if ($s === '') return null;
    $s = (strpos($s, '.') !== false && strpos($s, ',') !== false) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    if (substr_count($s, '.') > 1) { $i = strrpos($s, '.'); $s = str_replace('.', '', substr($s, 0, $i)) . substr($s, $i); }
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) return null;
    return number_format((float)$s, 2, '.', '');
}

function dcgw_json($a, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

const DCGW_STATUS = [
    'new' => ['Yangi', '#6D4AFF'], 'ok' => ['Tasdiqlandi', '#16A34A'], 'review' => ['Ko\'rikda', '#F59E0B'],
    'rejected' => ['Rad etildi', '#E5484D'], 'out' => ['Chiqim', '#6B6F88'], 'dup' => ['Takror', '#9AA0B4'],
    'test' => ['Sinov', '#0EA5E9'], 'unknown' => ['Noma\'lum', '#9AA0B4'],
];
