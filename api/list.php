<?php
// GET api/list.php?status=pending|approved|rejected|all — senarai untuk control panel (login diperlukan).
declare(strict_types=1);
require __DIR__ . '/config.php';
admin_required();

$filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$out = [];
foreach (load_txns() as $t) {
    if ($filter !== 'all' && strtoupper($filter) !== ($t['status'] ?? '')) continue;
    $out[] = with_ref($t);
}
json_out(['ok' => true, 'count' => count($out), 'txns' => $out]);
