<?php
// POST api/settings.php — tetapan panel.
// {"action":"toggles","maintenance":bool,"autoApprove":bool} atau
// {"action":"password","old":"...","new":"..."} — login panel diperlukan.
declare(strict_types=1);
require __DIR__ . '/config.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_body();
$action = (string)($b['action'] ?? '');
$settings = load_settings();

if ($action === 'toggles') {
    $settings['maintenance'] = !empty($b['maintenance']);
    $settings['autoApprove'] = !empty($b['autoApprove']);
    save_settings($settings);
    audit_log('SETTINGS', 'maintenance=' . ($settings['maintenance'] ? 'on' : 'off') . ' autoApprove=' . ($settings['autoApprove'] ? 'on' : 'off'));
    json_out(['ok' => true, 'settings' => ['maintenance' => $settings['maintenance'], 'autoApprove' => $settings['autoApprove']]]);
}

if ($action === 'password') {
    $old = (string)($b['old'] ?? '');
    $new = (string)($b['new'] ?? '');
    if (!password_verify($old, admin_pass_hash())) {
        json_out(['ok' => false, 'error' => 'Kata laluan semasa salah.'], 403);
    }
    if (mb_strlen($new) < 6) {
        json_out(['ok' => false, 'error' => 'Kata laluan baharu sekurang-kurangnya 6 aksara.'], 422);
    }
    $settings['passHash'] = password_hash($new, PASSWORD_DEFAULT);
    save_settings($settings);
    audit_log('PASSWORD', 'Kata laluan panel ditukar.');
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Action tidak sah.'], 422);
