<?php
// DC Gateway — har 5 daqiqada: telefon jim qolsa adminga ogohlantirish. Faqat CLI.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib.php';
$pdo = dcgw_pdo();
$q = $pdo->query("SELECT id, model, last_seen FROM dcgw_devices WHERE revoked = 0 AND alerted = 0 AND last_seen IS NOT NULL AND last_seen < NOW() - INTERVAL 25 MINUTE");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $pdo->prepare("UPDATE dcgw_devices SET alerted = 1 WHERE id = ?")->execute([$d['id']]);
    dcgw_tg("🔴 DC Agent #{$d['id']} jim: oxirgi signal {$d['last_seen']}. Telefon o'chgan, internet yo'q yoki ilova to'xtagan bo'lishi mumkin. Shu paytdagi to'lovlarni qo'lda tekshiring.");
    dcgw_log('warn', 'Qurilma jim', (int)$d['id']);
}
$pdo->exec("DELETE FROM dcgw_pair WHERE expires_at < NOW() - INTERVAL 1 DAY");
$pdo->exec("DELETE FROM dcgw_log WHERE ts < NOW() - INTERVAL 60 DAY");
