<?php
// POST api/bulk.php — sahkan/tolak banyak transaksi serentak.
// Badan JSON: {ids: [...], action: "approve"|"reject"} — login panel diperlukan.
declare(strict_types=1);
require __DIR__ . '/config.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_body();
$ids = $b['ids'] ?? [];
$action = strtolower(trim((string)($b['action'] ?? '')));
if (!is_array($ids) || $ids === [] || count($ids) > 100 || !in_array($action, ['approve', 'reject'], true)) {
    json_out(['ok' => false, 'error' => 'Senarai ids (maks 100) dan action (approve|reject) diperlukan.'], 422);
}
$ids = array_values(array_unique(array_map('strval', $ids)));

$txns = load_txns();
$byId = [];
foreach ($txns as $i => $t) $byId[$t['id'] ?? ''] = $i;

$done = [];
$failed = [];
foreach ($ids as $id) {
    if (!isset($byId[$id]) || ($txns[$byId[$id]]['status'] ?? '') !== 'PENDING') {
        $failed[] = $id;
        continue;
    }
    $i = $byId[$id];
    $txns[$i]['status'] = $action === 'approve' ? 'APPROVED' : 'REJECTED';
    $txns[$i]['decidedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
    $txns[$i]['decidedBy'] = ADMIN_USER;
    $done[] = $id;
}
save_txns($txns);
audit_log($action === 'approve' ? 'BULK_APPROVE' : 'BULK_REJECT', count($done) . ' transaksi: ' . implode(', ', array_slice($done, 0, 10)));
json_out(['ok' => true, 'done' => $done, 'failed' => $failed]);
