<?php
// POST api/delete.php — padam satu transaksi atau reset semua data.
// Badan JSON: {id} atau {all: true} — login panel diperlukan.
declare(strict_types=1);
require __DIR__ . '/config.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_body();
if (!empty($b['all'])) {
    save_txns([]);
    audit_log('RESET_ALL', 'Semua data transaksi dipadam.');
    json_out(['ok' => true, 'deleted' => 'all']);
}

$id = trim((string)($b['id'] ?? ''));
if ($id === '') json_out(['ok' => false, 'error' => 'ID diperlukan.'], 422);

$txns = load_txns();
$kept = array_values(array_filter($txns, fn($t) => ($t['id'] ?? '') !== $id));
if (count($kept) === count($txns)) json_out(['ok' => false, 'error' => 'Transaksi tidak dijumpai.'], 404);
save_txns($kept);
audit_log('DELETE', $id);
json_out(['ok' => true, 'deleted' => $id]);
