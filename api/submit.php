<?php
// POST api/submit.php — wallet menghantar transaksi baharu sebagai PENDING.
// Badan JSON: {type, amountCents, currency, region, recipient?, note?, method?, billType?, account?, senderEmail?}
declare(strict_types=1);
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_body();
$type = (string)($b['type'] ?? '');
$region = (string)($b['region'] ?? '');
$currency = (string)($b['currency'] ?? '');
$amount = $b['amountCents'] ?? 0;

// Mod penyelenggaraan — Wallet akan papar mesej, bukan fallback demo.
$settings = load_settings();
if (!empty($settings['maintenance'])) {
    json_out(['ok' => false, 'maintenance' => true, 'error' => 'Sistem dalam penyelenggaraan. Sila cuba sebentar lagi.'], 503);
}

if (!in_array($type, VALID_TYPES, true))      json_out(['ok' => false, 'error' => 'Jenis transaksi tidak sah.'], 422);
if (!in_array($region, VALID_REGIONS, true))  json_out(['ok' => false, 'error' => 'Region tidak sah.'], 422);
if (!in_array($currency, VALID_CURRENCIES, true)) json_out(['ok' => false, 'error' => 'Mata wang tidak sah.'], 422);
if (!is_int($amount) && !ctype_digit((string)$amount)) json_out(['ok' => false, 'error' => 'Jumlah tidak sah.'], 422);
$amount = (int)$amount;
if ($amount < 1 || $amount > 100000000) json_out(['ok' => false, 'error' => 'Jumlah mesti antara 1 dan 100,000,000 cents.'], 422);

$recipient = mb_substr(trim((string)($b['recipient'] ?? '')), 0, 120);
if (in_array($type, ['send', 'request'], true) && $recipient === '') {
    json_out(['ok' => false, 'error' => 'Penerima diperlukan.'], 422);
}

$txn = [
    'id'          => new_txn_id(),
    'refSeed'     => gen_seed(13),
    'type'        => $type,
    'amountCents' => $amount,
    'currency'    => $currency,
    'region'      => $region,
    'recipient'   => $recipient,
    'note'        => mb_substr(trim((string)($b['note'] ?? '')), 0, 500),
    'method'      => mb_substr(trim((string)($b['method'] ?? '')), 0, 20),
    'billType'    => mb_substr(trim((string)($b['billType'] ?? '')), 0, 30),
    'account'     => mb_substr(trim((string)($b['account'] ?? '')), 0, 40),
    'senderEmail' => mb_substr(trim((string)($b['senderEmail'] ?? '')), 0, 120),
    'status'      => 'PENDING', // menunggu pengesahan control panel
    'dateISO'     => gmdate('Y-m-d\TH:i:s.v\Z'),
    'decidedAt'   => null,
    'decidedBy'   => null,
];

// Auto-lulus (tetapan panel) — resit terus tersedia tanpa pengesahan manual.
if (!empty($settings['autoApprove'])) {
    $txn['status'] = 'APPROVED';
    $txn['decidedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
    $txn['decidedBy'] = 'auto';
}

$txns = load_txns();
array_unshift($txns, $txn);
save_txns($txns);

if ($txn['status'] === 'APPROVED') {
    json_out(['ok' => true, 'id' => $txn['id'], 'ref' => bank_ref($txn, $region), 'status' => 'APPROVED'], 201);
}
json_out(['ok' => true, 'id' => $txn['id'], 'ref' => bank_ref($txn, $region), 'status' => 'PENDING'], 201);
