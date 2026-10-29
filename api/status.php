<?php
// GET api/status.php?id=TXN-... — wallet polling status pengesahan (awam, demo).
declare(strict_types=1);
require __DIR__ . '/config.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') json_out(['ok' => false, 'error' => 'ID diperlukan.'], 422);

foreach (load_txns() as $t) {
    if (($t['id'] ?? '') === $id) {
        json_out(['ok' => true, 'txn' => with_ref($t)]);
    }
}
json_out(['ok' => false, 'error' => 'Transaksi tidak dijumpai.'], 404);
