<?php
// SecurePay — konfigurasi + fungsi kongsi untuk API dan control panel.
// PENTING (demo sahaja): tukar ADMIN_USER / kata laluan panel sebelum dedah ke internet.
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/../data/transactions.json';
const SETTINGS_FILE = __DIR__ . '/../data/settings.json';
const AUDIT_FILE = __DIR__ . '/../data/audit.json';

// Login control panel (demo): username `admin`, kata laluan `admin123`.
// Hash di bawah adalah untuk 'admin123' — jana hash baharu jika tukar kata laluan:
//   php -r "echo password_hash('KATA_LALUAN_BAHARU', PASSWORD_DEFAULT), PHP_EOL;"
const ADMIN_USER = 'admin';
const ADMIN_PASS_HASH = '$2y$10$/E.eDL.ZZ7Rwf/OjY9pecOzTVEbZObkdYVXgLiLnwcsW3iyFUckSO';

const VALID_TYPES = ['send', 'request', 'topup', 'bill'];
const VALID_CURRENCIES = ['MYR', 'JPY', 'USD', 'EUR'];
const VALID_REGIONS = ['maybank', 'japan', 'us', 'eu', 'generic'];

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_body(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?? '', true);
    return is_array($d) ? $d : [];
}

/** @return array<int, array> */
function load_txns(): array
{
    if (!is_file(DATA_FILE)) return [];
    $d = json_decode(file_get_contents(DATA_FILE) ?: '[]', true);
    return is_array($d) ? array_values($d) : [];
}

/** @param array<int, array> $txns */
function save_txns(array $txns): void
{
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents(
        DATA_FILE,
        json_encode(array_values($txns), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function gen_seed(int $n = 13): string
{
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= (string) random_int(0, 9);
    return $s;
}

function new_txn_id(): string
{
    return 'TXN-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

// Kod rujukan unik ikut bank — mesti sepadan dengan bankRef() dalam receipts.js.
// Cth Maybank: MYBK-1234121324124
function bank_ref(array $t, string $region): string
{
    $seed = $t['refSeed'] ?? '';
    switch ($region) {
        case 'maybank': return 'MYBK-' . $seed;
        case 'japan':   return 'JPBK-' . substr($seed, 0, 10);
        case 'us':      return 'BOFA-' . substr($seed, 0, 10);
        case 'eu':
            $b36 = strtoupper(base_convert($seed, 10, 36));
            return 'EURO-' . str_pad($b36, 12, '0', STR_PAD_LEFT);
        default:        return 'SPAY-' . substr($seed, 0, 10);
    }
}

function with_ref(array $t): array
{
    $t['ref'] = bank_ref($t, $t['region'] ?? 'generic');
    return $t;
}

// Halang akses API sensitif tanpa login control panel.
function admin_required(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['panel_admin'])) json_out(['ok' => false, 'error' => 'Unauthorized — sila login control panel.'], 403);
    session_write_close(); // lepas lock supaya polling tidak tersekat
}

// ---------- Tetapan panel (data/settings.json) ----------
function load_settings(): array
{
    $defaults = ['maintenance' => false, 'autoApprove' => false, 'passHash' => null];
    if (!is_file(SETTINGS_FILE)) return $defaults;
    $d = json_decode(file_get_contents(SETTINGS_FILE) ?: '{}', true);
    return array_merge($defaults, is_array($d) ? $d : []);
}

function save_settings(array $s): void
{
    $dir = dirname(SETTINGS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents(SETTINGS_FILE, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Hash kata laluan panel — tetapan mengatasi nilai lalai dalam fail ini.
function admin_pass_hash(): string
{
    $s = load_settings();
    return is_string($s['passHash']) && $s['passHash'] !== '' ? $s['passHash'] : ADMIN_PASS_HASH;
}

// ---------- Log audit (data/audit.json, maksimum 500 entri) ----------
function audit_log(string $event, string $detail = ''): void
{
    $rows = [];
    if (is_file(AUDIT_FILE)) {
        $d = json_decode(file_get_contents(AUDIT_FILE) ?: '[]', true);
        if (is_array($d)) $rows = $d;
    }
    $rows[] = [
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
        'by' => ADMIN_USER,
        'event' => mb_substr($event, 0, 40),
        'detail' => mb_substr($detail, 0, 300),
    ];
    if (count($rows) > 500) $rows = array_slice($rows, -500);
    $dir = dirname(AUDIT_FILE);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents(AUDIT_FILE, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** @return array<int, array> */
function load_audit(): array
{
    if (!is_file(AUDIT_FILE)) return [];
    $d = json_decode(file_get_contents(AUDIT_FILE) ?: '[]', true);
    return is_array($d) ? array_values($d) : [];
}
