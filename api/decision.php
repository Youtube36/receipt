<?php
// POST api/decision.php — control panel SAHKAN (approve) atau TOLAK (reject) transaksi.
// Badan JSON: {id, action: "approve"|"reject"} — login panel diperlukan.
declare(strict_types=1);
require __DIR__ . '/config.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_body();
$id = trim((string)($b['id'] ?? ''));
$action = strtolower(trim((string)($b['action'] ?? '')));
if ($id === '' || !in_array($action, ['approve', 'reject'], true)) {
    json_out(['ok' => false, 'error' => 'ID dan action (approve|reject) diperlukan.'], 422);
}

$txns = load_txns();
foreach ($txns as $i => $t) {
    if (($t['id'] ?? '') !== $id) continue;
    if (($t['status'] ?? '') !== 'PENDING') {
        json_out(['ok' => false, 'error' => 'Transaksi sudah diputuskan (' . $t['status'] . ').'], 409);
    }
    $txns[$i]['status'] = $action === 'approve' ? 'APPROVED' : 'REJECTED';
    $txns[$i]['decidedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
    $txns[$i]['decidedBy'] = ADMIN_USER;
    save_txns($txns);
    audit_log($action === 'approve' ? 'APPROVE' : 'REJECT', $id . ' · ' . bank_ref($txns[$i], $txns[$i]['region'] ?? 'generic'));
    json_out(['ok' => true, 'txn' => with_ref($txns[$i])]);
}
json_out(['ok' => false, 'error' => 'Transaksi tidak dijumpai.'], 404);
