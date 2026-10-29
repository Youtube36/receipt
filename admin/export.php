<?php
// GET admin/export.php — muat turun CSV transaksi (ikut penapis semasa).
// Login panel diperlukan.
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['panel_admin'])) {
    session_write_close();
    header('Location: index.php');
    exit;
}
session_write_close();

$q = strtolower(trim((string)($_GET['q'] ?? '')));
$ftype = (string)($_GET['ftype'] ?? '');
$fregion = (string)($_GET['fregion'] ?? '');
$fstatus = strtoupper(trim((string)($_GET['fstatus'] ?? '')));

$rows = [];
foreach (load_txns() as $t) {
    if ($ftype !== '' && ($t['type'] ?? '') !== $ftype) continue;
    if ($fregion !== '' && ($t['region'] ?? '') !== $fregion) continue;
    if ($fstatus !== '' && ($t['status'] ?? '') !== $fstatus) continue;
    $ref = bank_ref($t, $t['region'] ?? 'generic');
    if ($q !== '') {
        $hay = strtolower(implode(' ', [$t['id'] ?? '', $ref, $t['refSeed'] ?? '', $t['recipient'] ?? '', $t['note'] ?? '', $t['account'] ?? '', $t['billType'] ?? '']));
        if (!str_contains($hay, $q)) continue;
    }
    $rows[] = $t + ['_ref' => $ref];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="securepay-transactions-' . date('Ymd-His') . '.csv"');
echo "\xEF\xBB\xBF"; // BOM supaya Excel baca UTF-8
$out = fopen('php://output', 'w');
fputcsv($out, ['id', 'ref', 'type', 'amount', 'currency', 'region', 'recipient', 'note', 'method', 'billType', 'account', 'status', 'dateISO', 'decidedAt', 'decidedBy']);
foreach ($rows as $t) {
    fputcsv($out, [
        $t['id'] ?? '', $t['_ref'] ?? '', $t['type'] ?? '',
        number_format((($t['amountCents'] ?? 0) / 100), 2, '.', ''),
        $t['currency'] ?? '', $t['region'] ?? '', $t['recipient'] ?? '',
        $t['note'] ?? '', $t['method'] ?? '', $t['billType'] ?? '',
        $t['account'] ?? '', $t['status'] ?? '', $t['dateISO'] ?? '',
        $t['decidedAt'] ?? '', $t['decidedBy'] ?? '',
    ]);
}
fclose($out);
