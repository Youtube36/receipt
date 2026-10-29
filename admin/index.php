<?php
// SecurePay — Central Command: sahkan transaksi, kemudian jana resit.
// Tab: Transaksi (carian/penapis/pukal/manual) | Analitik | Log Audit | Tetapan.
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
session_start();

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    if ($u === ADMIN_USER && password_verify($p, admin_pass_hash())) {
        $_SESSION['panel_admin'] = true;
        audit_log('LOGIN', 'Log masuk panel berjaya.');
        session_write_close();
        header('Location: index.php');
        exit;
    }
    $loginError = 'Username atau kata laluan salah.';
    session_write_close();
}

$loggedIn = !empty($_SESSION['panel_admin']);
if ($loggedIn) session_write_close();

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

if (!$loggedIn) {
    // ---------------- LOGIN ----------------
    ?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Central Command — Log Masuk</title>
<link rel="stylesheet" href="../style.css">
<style>
.login-wrap { max-width: 400px; margin: 9vh auto; background: rgba(7,14,25,.95); border: 1px solid rgba(0,240,255,.35); border-radius: 10px; padding: 26px; box-shadow: 0 0 28px rgba(0,240,255,.15), inset 0 0 60px rgba(0,240,255,.03); }
.login-wrap h2 { color: #00f0ff; margin: 8px 0 4px; font-family: Consolas, 'Courier New', monospace; letter-spacing: 3px; text-shadow: 0 0 12px rgba(0,240,255,.8); }
.login-sub { color: #5f7a99; font-size: .8rem; margin: 0 0 6px; }
.login-err { background: rgba(255,45,85,.1); border: 1px solid rgba(255,45,85,.5); color: #ff8095; border-radius: 6px; padding: 8px 10px; margin: 10px 0; font-size: .82rem; font-family: Consolas, monospace; }
.hint { font-size: .75rem; color: #5f7a99; margin-top: 10px; font-family: Consolas, monospace; }
.login-stamp { display: inline-block; font-family: Consolas, monospace; font-size: .62rem; letter-spacing: 3px; color: #ff2d55; border: 1px solid rgba(255,45,85,.6); padding: 3px 10px; transform: rotate(-1.5deg); text-shadow: 0 0 8px rgba(255,45,85,.7); }
</style>
</head>
<body>
<div class="sys-ticker" aria-hidden="true"><span>/// CENTRAL COMMAND /// ALL TRANSACTIONS MONITORED /// CLEARANCE LEVEL 5 REQUIRED /// UNAUTHORIZED ACCESS WILL BE TRACED ///</span></div>
<div class="login-wrap">
    <span class="login-stamp">RESTRICTED // EYES ONLY</span>
    <h2>CENTRAL COMMAND</h2>
    <p class="login-sub">Pengesahan pegawai diperlukan // Authorized personnel only.</p>
    <?php if ($loginError): ?><div class="login-err"><?= $e($loginError) ?></div><?php endif; ?>
    <form method="post">
        <div class="form-group"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
        <div class="form-group"><label>Kata laluan</label><input type="password" name="password" required autocomplete="current-password"></div>
        <button class="btn btn-primary" type="submit">Log Masuk</button>
    </form>
    <p class="hint">Demo: admin / admin123 — tukar dalam tab Tetapan untuk production.</p>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------------- DASHBOARD ----------------
$txns = load_txns();
$settings = load_settings();

// Penapis (GET) — dikekalkan dalam URL supaya reload tidak hilang carian.
$q = strtolower(trim((string)($_GET['q'] ?? '')));
$ftype = (string)($_GET['ftype'] ?? '');
$fregion = (string)($_GET['fregion'] ?? '');
$fstatus = strtoupper(trim((string)($_GET['fstatus'] ?? '')));
$hpage = max(1, (int)($_GET['hpage'] ?? 1));

$refOf = fn($t) => bank_ref($t, $t['region'] ?? 'generic');
$matchQ = function ($t) use ($q, $refOf) {
    if ($q === '') return true;
    $hay = strtolower(implode(' ', [
        $t['id'] ?? '', $refOf($t), $t['refSeed'] ?? '', $t['recipient'] ?? '',
        $t['note'] ?? '', $t['account'] ?? '', $t['billType'] ?? '', $t['senderEmail'] ?? '',
    ]));
    return str_contains($hay, $q);
};
$matchTF = fn($t) => ($ftype === '' || ($t['type'] ?? '') === $ftype)
    && ($fregion === '' || ($t['region'] ?? '') === $fregion);

$pendingAll = array_values(array_filter($txns, fn($t) => ($t['status'] ?? '') === 'PENDING'));
$pending = array_values(array_filter($pendingAll, fn($t) => $matchQ($t) && $matchTF($t)));
$historyAll = array_values(array_filter($txns, fn($t) =>
    ($t['status'] ?? '') !== 'PENDING' && $matchQ($t) && $matchTF($t)
    && ($fstatus === '' || ($t['status'] ?? '') === $fstatus)));
$perPage = 15;
$hpages = max(1, (int)ceil(count($historyAll) / $perPage));
$hpage = min($hpage, $hpages);
$history = array_slice($historyAll, ($hpage - 1) * $perPage, $perPage);
$qstr = fn($extra = []) => http_build_query(array_merge(
    ['q' => $_GET['q'] ?? '', 'ftype' => $ftype, 'fregion' => $fregion, 'fstatus' => $_GET['fstatus'] ?? ''],
    $extra
));

// Analitik (dari semua data)
$byStatus = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
$sums = [];
$byType = [];
$byRegion = [];
$last7 = [];
for ($i = 6; $i >= 0; $i--) $last7[date('Y-m-d', strtotime("-$i days"))] = 0;
foreach ($txns as $t) {
    $st = $t['status'] ?? 'PENDING';
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
    if ($st === 'APPROVED') {
        $cur = $t['currency'] ?? '?';
        $sums[$cur] = ($sums[$cur] ?? 0) + (int)($t['amountCents'] ?? 0);
    }
    $byType[$t['type'] ?? '?'] = ($byType[$t['type'] ?? '?'] ?? 0) + 1;
    $byRegion[$t['region'] ?? '?'] = ($byRegion[$t['region'] ?? '?'] ?? 0) + 1;
    $day = substr((string)($t['dateISO'] ?? ''), 0, 10);
    if (isset($last7[$day])) $last7[$day]++;
}
$maxDay = max(1, max($last7));
$approvedToday = 0;
$today = date('Y-m-d');
foreach ($txns as $t) {
    if (($t['status'] ?? '') === 'APPROVED' && substr((string)($t['decidedAt'] ?? ''), 0, 10) === $today) $approvedToday++;
}

$audit = array_reverse(load_audit());
$audit = array_slice($audit, 0, 100);

$typeLabel = ['send' => 'Hantar', 'request' => 'Minta', 'topup' => 'Top Up', 'bill' => 'Bil'];
$typeOpts = ['' => 'Semua jenis', 'send' => 'Hantar', 'request' => 'Minta', 'topup' => 'Top Up', 'bill' => 'Bil'];
$regionOpts = ['' => 'Semua region', 'maybank' => 'Maybank', 'japan' => 'Jepun', 'us' => 'US', 'eu' => 'EU', 'generic' => 'Generik'];
$statusOpts = ['' => 'Semua status', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'];
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Central Command — Pengesahan Resit</title>
<link rel="stylesheet" href="../style.css">
<style>
/* ===== CENTRAL COMMAND // terminal treatment ===== */
.panel { max-width: 1060px; margin: 0 auto; padding: 18px 14px 40px; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; background: rgba(7,14,25,.92); border: 1px solid rgba(0,240,255,.35); border-radius: 10px; padding: 14px 16px; box-shadow: 0 0 22px rgba(0,240,255,.1); }
.panel-head h2 { color: #00f0ff; margin: 0; font-family: Consolas, 'Courier New', monospace; letter-spacing: 2px; font-size: 1.05rem; text-shadow: 0 0 12px rgba(0,240,255,.7); }
.panel-sub { color: #5f7a99; font-size: .75rem; margin: 4px 0 0; font-family: Consolas, monospace; }
.cmd-stamp { display: inline-block; font-family: Consolas, monospace; font-size: .62rem; letter-spacing: 3px; color: #ff2d55; border: 1px solid rgba(255,45,85,.6); padding: 3px 10px; transform: rotate(-1.5deg); text-shadow: 0 0 8px rgba(255,45,85,.7); }
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.tabbtn { font-family: Consolas, monospace; letter-spacing: 1px; font-size: .75rem; background: transparent; border: 1px solid rgba(0,240,255,.3); color: #5f7a99; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
.tabbtn.on { color: #00f0ff; border-color: #00f0ff; box-shadow: 0 0 12px rgba(0,240,255,.35); }
.tabsec { display: none; } .tabsec.on { display: block; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px; }
.stat { background: rgba(7,14,25,.92); border: 1px solid rgba(0,240,255,.25); border-top: 2px solid #00f0ff; border-radius: 8px; padding: 12px; text-align: center; font-family: Consolas, monospace; color: #5f7a99; font-size: .7rem; letter-spacing: 2px; }
.stat b { font-size: 1.7rem; display: block; margin-bottom: 2px; }
.stat.p { border-top-color: #ffb300; } .stat.p b { color: #ffb300; text-shadow: 0 0 12px rgba(255,179,0,.7); }
.stat.a { border-top-color: #39ff88; } .stat.a b { color: #39ff88; text-shadow: 0 0 12px rgba(57,255,136,.7); }
.stat.t { border-top-color: #00f0ff; } .stat.t b { color: #00f0ff; text-shadow: 0 0 12px rgba(0,240,255,.7); }
.filterbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; background: rgba(7,14,25,.92); border: 1px solid rgba(0,240,255,.25); border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; }
.filterbar .fg { display: flex; flex-direction: column; gap: 3px; }
.filterbar label { font-family: Consolas, monospace; font-size: .62rem; letter-spacing: 2px; color: #00f0ff; }
.filterbar input, .filterbar select { background: #020610; border: 1px solid rgba(0,240,255,.25); color: #39ff88; border-radius: 6px; padding: 7px 9px; font-family: Consolas, monospace; font-size: .78rem; }
.filterbar input { min-width: 170px; }
.filterbar .btn { width: auto; padding: 8px 12px; font-size: .72rem; }
table.grid { width: 100%; border-collapse: collapse; background: rgba(2,8,16,.94); border: 1px solid rgba(0,240,255,.3); border-radius: 10px; overflow: hidden; font-size: .8rem; box-shadow: 0 0 22px rgba(0,240,255,.08); color: #c9e4ff; }
table.grid th, table.grid td { padding: 9px 8px; border-bottom: 1px solid rgba(0,240,255,.12); text-align: left; vertical-align: top; }
table.grid th { background: rgba(0,40,55,.6); color: #00f0ff; font-size: .68rem; text-transform: uppercase; letter-spacing: 2px; font-family: Consolas, monospace; }
table.grid tbody tr { transition: background .15s; }
table.grid tbody tr:hover { background: rgba(0,240,255,.06); }
table.grid td .dim { color: #5f7a99; }
.pill { display: inline-flex; align-items: center; gap: 6px; padding: 2px 9px; border-radius: 20px; font-size: .68rem; font-weight: 700; font-family: Consolas, monospace; letter-spacing: 1px; border: 1px solid; }
.pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.pill.PENDING { background: rgba(255,179,0,.08); color: #ffb300; border-color: rgba(255,179,0,.5); }
.pill.PENDING::before { animation: sf-blink 1.2s infinite; box-shadow: 0 0 8px currentColor; }
.pill.APPROVED { background: rgba(57,255,136,.08); color: #39ff88; border-color: rgba(57,255,136,.5); }
.pill.REJECTED { background: rgba(255,45,85,.08); color: #ff8095; border-color: rgba(255,45,85,.5); }
.rowbtns { display: flex; gap: 6px; flex-wrap: wrap; }
.rowbtns .btn { width: auto; padding: 6px 10px; font-size: .72rem; background: transparent; }
.btn-ok { border-color: #39ff88; color: #39ff88; }
.btn-ok:hover { background: rgba(57,255,136,.15); box-shadow: 0 0 14px rgba(57,255,136,.5); color: #fff; }
.btn-no { border-color: #ff2d55; color: #ff8095; }
.btn-no:hover { background: rgba(255,45,85,.15); box-shadow: 0 0 14px rgba(255,45,85,.5); color: #fff; }
.btn-view { border-color: #00f0ff; color: #00f0ff; }
.btn-view:hover { background: rgba(0,240,255,.15); box-shadow: 0 0 14px rgba(0,240,255,.5); color: #fff; }
.btn-danger { border-color: #ff2d55; color: #ff8095; }
.btn-danger:hover { background: rgba(255,45,85,.15); color: #fff; }
.mono { font-family: Consolas, monospace; font-size: .72rem; color: #39ff88; text-shadow: 0 0 8px rgba(57,255,136,.4); }
.sec-title { margin: 20px 0 8px; color: #00f0ff; font-family: Consolas, monospace; letter-spacing: 2px; font-size: .9rem; text-shadow: 0 0 10px rgba(0,240,255,.5); }
.empty { background: rgba(2,8,16,.9); border: 1px dashed rgba(0,240,255,.3); border-radius: 10px; padding: 16px; color: #5f7a99; font-size: .85rem; font-family: Consolas, monospace; }
.topbar { display: flex; gap: 8px; align-items: center; }
.topbar a { font-size: .8rem; color: #00f0ff; font-family: Consolas, monospace; }
#bulkbar { display: none; gap: 8px; align-items: center; flex-wrap: wrap; background: rgba(0,40,55,.7); border: 1px solid rgba(0,240,255,.4); border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; font-family: Consolas, monospace; font-size: .78rem; color: #00f0ff; }
#bulkbar.show { display: flex; }
#bulkbar .btn { width: auto; padding: 6px 10px; font-size: .72rem; background: transparent; }
#newBanner { display: none; background: rgba(255,179,0,.1); border: 1px solid rgba(255,179,0,.5); color: #ffb300; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; font-family: Consolas, monospace; font-size: .8rem; }
#newBanner.show { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
#newBanner .btn { width: auto; padding: 6px 10px; font-size: .72rem; }
.bars { display: flex; flex-direction: column; gap: 6px; }
.barrow { display: grid; grid-template-columns: 110px 1fr 46px; gap: 8px; align-items: center; font-family: Consolas, monospace; font-size: .72rem; color: #c9e4ff; }
.bartrack { height: 10px; background: rgba(0,240,255,.1); border-radius: 5px; overflow: hidden; }
.barfill { height: 100%; background: linear-gradient(90deg, #00f0ff, #39ff88); box-shadow: 0 0 8px rgba(0,240,255,.6); }
.setgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; }
.setcard { background: rgba(7,14,25,.92); border: 1px solid rgba(0,240,255,.25); border-radius: 10px; padding: 14px; }
.setcard h4 { color: #00f0ff; font-family: Consolas, monospace; letter-spacing: 2px; margin: 0 0 8px; font-size: .82rem; }
.setcard p { color: #5f7a99; font-size: .75rem; }
.setcard.danger { border-color: rgba(255,45,85,.5); }
.setcard.danger h4 { color: #ff8095; }
.switch { display: flex; gap: 8px; align-items: center; font-family: Consolas, monospace; font-size: .78rem; color: #c9e4ff; margin: 8px 0; }
.switch input { width: 18px; height: 18px; accent-color: #00f0ff; }
.setcard .form-group input { background: #020610; border: 1px solid rgba(0,240,255,.25); color: #39ff88; border-radius: 6px; padding: 8px 10px; font-family: Consolas, monospace; width: 100%; box-sizing: border-box; }
.setcard .btn { width: auto; padding: 8px 14px; font-size: .75rem; margin-top: 6px; }
details.manual { background: rgba(7,14,25,.92); border: 1px solid rgba(0,240,255,.25); border-radius: 10px; padding: 10px 14px; margin-bottom: 10px; }
details.manual summary { cursor: pointer; color: #00f0ff; font-family: Consolas, monospace; letter-spacing: 1px; font-size: .8rem; }
.manual-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-top: 10px; }
.manual-grid .fg { display: flex; flex-direction: column; gap: 3px; }
.manual-grid label { font-family: Consolas, monospace; font-size: .62rem; letter-spacing: 2px; color: #00f0ff; }
.manual-grid input, .manual-grid select { background: #020610; border: 1px solid rgba(0,240,255,.25); color: #39ff88; border-radius: 6px; padding: 7px 9px; font-family: Consolas, monospace; font-size: .78rem; }
.pager { display: flex; gap: 8px; align-items: center; margin-top: 10px; font-family: Consolas, monospace; font-size: .75rem; color: #5f7a99; }
.pager a { color: #00f0ff; }
.pv-meta { font-family: Consolas, monospace; font-size: .7rem; color: #5f7a99; border: 1px dashed rgba(0,240,255,.3); border-radius: 6px; padding: 6px 8px; margin-bottom: 10px; }
.pv-meta b { color: #39ff88; }
#pvModal { position: fixed; inset: 0; background: rgba(1,3,6,.78); display: none; align-items: flex-start; justify-content: center; padding: 24px 12px; z-index: 9999; overflow-y: auto; }
#pvBox { background: rgba(7,14,25,.97); border: 1px solid rgba(0,240,255,.4); border-radius: 12px; max-width: 560px; width: 100%; padding: 16px; box-shadow: 0 0 40px rgba(0,240,255,.2); }
#pvBar { display: flex; gap: 8px; margin-bottom: 10px; align-items: flex-end; flex-wrap: wrap; }
#pvBar .btn { width: auto; padding: 8px 12px; }
#pvBar .select { flex: 1; min-width: 140px; }
.wrap-scroll { overflow-x: auto; border-radius: 10px; }
@media print {
    body > *:not(#pvModal) { display: none !important; }
    #pvModal { position: static; background: none; padding: 0; display: block !important; }
    #pvBox { max-width: none; }
    #pvBar { display: none !important; }
}
</style>
</head>
<body>
<div class="sys-ticker" aria-hidden="true"><span>/// CENTRAL COMMAND /// ALL TRANSACTIONS MONITORED /// CLEARANCE LEVEL 5 REQUIRED /// UNAUTHORIZED ACCESS WILL BE TRACED /// RECEIPT GENERATION GATED BY HUMAN CONFIRMATION ///</span></div>
<div class="panel">
    <div class="panel-head">
        <div>
            <span class="cmd-stamp">TOP SECRET // EYES ONLY</span>
            <h2>CENTRAL COMMAND — PENGESAHAN RESIT</h2>
            <p class="panel-sub">Sahkan transaksi → resit dijana di wallet // Receipt gated by confirmation</p>
        </div>
        <div class="topbar"><a href="logout.php">Log keluar</a></div>
    </div>

    <div class="tabs">
        <button class="tabbtn on" data-tab="txns">TRANSAKSI</button>
        <button class="tabbtn" data-tab="stats">ANALITIK</button>
        <button class="tabbtn" data-tab="audit">LOG AUDIT (<?= count($audit) ?>)</button>
        <button class="tabbtn" data-tab="settings">TETAPAN<?= ($settings['maintenance'] || $settings['autoApprove']) ? ' ●' : '' ?></button>
    </div>

    <div id="newBanner"><span id="newBannerText"></span><button class="btn btn-view" onclick="location.reload()">Muat semula</button></div>

    <!-- ============ TAB: TRANSAKSI ============ -->
    <div class="tabsec on" id="tab-txns">
    <div class="stats">
        <div class="stat p"><b><?= count($pendingAll) ?></b>Menunggu</div>
        <div class="stat a"><b><?= $approvedToday ?></b>Disahkan hari ini</div>
        <div class="stat t"><b><?= count($txns) ?></b>Jumlah transaksi</div>
    </div>

    <form class="filterbar" method="get">
        <div class="fg"><label>CARIAN</label><input type="text" name="q" value="<?= $e($_GET['q'] ?? '') ?>" placeholder="ref / id / nama / nota…"></div>
        <div class="fg"><label>JENIS</label><select name="ftype"><?php foreach ($typeOpts as $v => $l): ?><option value="<?= $e($v) ?>" <?= $ftype === (string)$v ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
        <div class="fg"><label>REGION</label><select name="fregion"><?php foreach ($regionOpts as $v => $l): ?><option value="<?= $e($v) ?>" <?= $fregion === (string)$v ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
        <div class="fg"><label>STATUS (SEJARAH)</label><select name="fstatus"><?php foreach ($statusOpts as $v => $l): ?><option value="<?= $e($v) ?>" <?= strtoupper((string)($_GET['fstatus'] ?? '')) === (string)$v ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-view" type="submit">Tapis</button>
        <a class="btn btn-view" style="text-decoration:none" href="export.php?<?= $e($qstr()) ?>">CSV ⬇</a>
        <label style="font-family:Consolas,monospace;font-size:.7rem;color:#5f7a99"><input type="checkbox" id="arToggle" checked> auto-refresh</label>
    </form>

    <details class="manual">
        <summary>+ CIPTA TRANSAKSI MANUAL (terus diluluskan)</summary>
        <div class="manual-grid">
            <div class="fg"><label>JENIS</label><select id="mType"><option value="send">Hantar</option><option value="request">Minta</option><option value="topup">Top Up</option><option value="bill">Bil</option></select></div>
            <div class="fg"><label>JUMLAH</label><input id="mAmount" type="number" min="0.01" step="0.01" placeholder="cth: 130.00"></div>
            <div class="fg"><label>MATA WANG</label><select id="mCurrency"><option>MYR</option><option>JPY</option><option>USD</option><option>EUR</option></select></div>
            <div class="fg"><label>REGION</label><select id="mRegion"><option value="maybank">Maybank</option><option value="japan">Jepun</option><option value="us">US</option><option value="eu">EU</option><option value="generic">Generik</option></select></div>
            <div class="fg"><label>PENERIMA</label><input id="mRecipient" type="text" placeholder="nama / akaun"></div>
            <div class="fg"><label>NOTA</label><input id="mNote" type="text" placeholder="nota (pilihan)"></div>
        </div>
        <button class="btn btn-ok" id="mCreate" style="margin-top:10px">Cipta &amp; Luluskan</button>
    </details>

    <h3 class="sec-title">// MENUNGGU PENGESAHAN (<?= count($pending) ?>)</h3>
    <div id="bulkbar"><span id="bulkcount"></span>
        <button class="btn btn-ok" id="bulkApprove">Sahkan terpilih ✓</button>
        <button class="btn btn-no" id="bulkReject">Tolak terpilih ✕</button>
    </div>
    <?php if (!$pending): ?>
        <div class="empty">Tiada transaksi menunggu. Transaksi baharu dari wallet akan muncul di sini.</div>
    <?php else: ?>
    <div class="wrap-scroll"><table class="grid">
        <thead><tr><th><input type="checkbox" id="chkAll" title="Pilih semua"></th><th>Rujukan</th><th>Jenis</th><th>Jumlah</th><th>Butiran</th><th>Masa</th><th>Tindakan</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $t):
            $ref = $refOf($t);
            $detail = $t['recipient'] !== '' ? $t['recipient'] : ($t['billType'] !== '' ? $t['billType'] : ($t['method'] !== '' ? $t['method'] : '—'));
        ?>
        <tr id="row-<?= $e($t['id']) ?>">
            <td><input type="checkbox" class="bulkchk" value="<?= $e($t['id']) ?>"></td>
            <td class="mono"><?= $e($ref) ?><br><span class="dim"><?= $e($t['id']) ?></span></td>
            <td><?= $e($typeLabel[$t['type']] ?? $t['type']) ?><br><span class="dim"><?= $e(strtoupper($t['currency'])) ?> · <?= $e($t['region']) ?></span></td>
            <td><strong><?= $e(number_format($t['amountCents'] / 100, 2)) ?></strong></td>
            <td><?= $e($detail) ?><?= $t['note'] !== '' ? '<br><span class="dim">' . $e($t['note']) . '</span>' : '' ?><?= $t['senderEmail'] !== '' ? '<br><span class="dim">✉ ' . $e($t['senderEmail']) . '</span>' : '' ?></td>
            <td style="white-space:nowrap" class="mono"><?= $e($t['dateISO']) ?></td>
            <td><div class="rowbtns">
                <button class="btn btn-ok" data-act="approve" data-id="<?= $e($t['id']) ?>">Sahkan ✓</button>
                <button class="btn btn-no" data-act="reject" data-id="<?= $e($t['id']) ?>">Tolak ✕</button>
                <button class="btn btn-view" data-preview="<?= $e($t['id']) ?>">Resit</button>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

    <h3 class="sec-title">// SEJARAH KEPUTUSAN (<?= count($historyAll) ?>)</h3>
    <?php if (!$history): ?>
        <div class="empty">Belum ada keputusan.</div>
    <?php else: ?>
    <div class="wrap-scroll"><table class="grid">
        <thead><tr><th>Rujukan</th><th>Jenis</th><th>Jumlah</th><th>Status</th><th>Diputuskan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($history as $t): ?>
        <tr>
            <td class="mono"><?= $e($refOf($t)) ?></td>
            <td><?= $e($typeLabel[$t['type']] ?? $t['type']) ?></td>
            <td><?= $e(number_format($t['amountCents'] / 100, 2)) ?> <?= $e($t['currency']) ?></td>
            <td><span class="pill <?= $e($t['status']) ?>"><?= $e($t['status']) ?></span></td>
            <td style="white-space:nowrap" class="mono"><?= $e($t['decidedAt'] ?? '—') ?></td>
            <td><div class="rowbtns"><?php if (($t['status'] ?? '') === 'APPROVED'): ?><button class="btn btn-view" data-preview="<?= $e($t['id']) ?>">Resit</button><?php endif; ?><button class="btn btn-danger" data-del="<?= $e($t['id']) ?>">Padam</button></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="pager">
        <?php if ($hpage > 1): ?><a href="?<?= $e($qstr(['hpage' => $hpage - 1])) ?>#tab-txns">← Sebelum</a><?php endif; ?>
        <span>Halaman <?= $hpage ?> / <?= $hpages ?></span>
        <?php if ($hpage < $hpages): ?><a href="?<?= $e($qstr(['hpage' => $hpage + 1])) ?>#tab-txns">Seterus →</a><?php endif; ?>
    </div>
    <?php endif; ?>
    </div>

    <!-- ============ TAB: ANALITIK ============ -->
    <div class="tabsec" id="tab-stats">
        <h3 class="sec-title">// STATUS</h3>
        <div class="bars">
            <?php $mx = max(1, max($byStatus)); foreach ($byStatus as $k => $v): ?>
            <div class="barrow"><span><?= $e($k) ?></span><div class="bartrack"><div class="barfill" style="width:<?= round($v / $mx * 100) ?>%"></div></div><b><?= $v ?></b></div>
            <?php endforeach; ?>
        </div>
        <h3 class="sec-title">// NILAI DILULUSKAN (MENGIKUT MATA WANG)</h3>
        <div class="bars">
            <?php if (!$sums): ?><div class="empty">Belum ada transaksi diluluskan.</div><?php endif; ?>
            <?php $mx = max(1, max(array_merge([1], array_values($sums)))); foreach ($sums as $k => $v): ?>
            <div class="barrow"><span><?= $e($k) ?></span><div class="bartrack"><div class="barfill" style="width:<?= round($v / $mx * 100) ?>%"></div></div><b><?= $e(number_format($v / 100, 2)) ?></b></div>
            <?php endforeach; ?>
        </div>
        <h3 class="sec-title">// MENGIKUT JENIS</h3>
        <div class="bars">
            <?php $mx = max(1, max(array_merge([1], array_values($byType)))); foreach ($byType as $k => $v): ?>
            <div class="barrow"><span><?= $e($typeLabel[$k] ?? $k) ?></span><div class="bartrack"><div class="barfill" style="width:<?= round($v / $mx * 100) ?>%"></div></div><b><?= $v ?></b></div>
            <?php endforeach; ?>
        </div>
        <h3 class="sec-title">// MENGIKUT REGION</h3>
        <div class="bars">
            <?php $mx = max(1, max(array_merge([1], array_values($byRegion)))); foreach ($byRegion as $k => $v): ?>
            <div class="barrow"><span><?= $e($k) ?></span><div class="bartrack"><div class="barfill" style="width:<?= round($v / $mx * 100) ?>%"></div></div><b><?= $v ?></b></div>
            <?php endforeach; ?>
        </div>
        <h3 class="sec-title">// 7 HARI TERAKHIR (TRANSAKSI/HARI)</h3>
        <div class="bars">
            <?php foreach ($last7 as $d => $v): ?>
            <div class="barrow"><span><?= $e(substr($d, 5)) ?></span><div class="bartrack"><div class="barfill" style="width:<?= round($v / $maxDay * 100) ?>%"></div></div><b><?= $v ?></b></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============ TAB: LOG AUDIT ============ -->
    <div class="tabsec" id="tab-audit">
        <h3 class="sec-title">// JEJAK TINDAKAN (<?= count($audit) ?>)</h3>
        <?php if (!$audit): ?><div class="empty">Belum ada aktiviti direkodkan.</div>
        <?php else: ?>
        <div class="wrap-scroll"><table class="grid">
            <thead><tr><th>Masa (UTC)</th><th>Oleh</th><th>Peristiwa</th><th>Butiran</th></tr></thead>
            <tbody>
            <?php foreach ($audit as $a): ?>
            <tr><td class="mono" style="white-space:nowrap"><?= $e($a['at'] ?? '') ?></td><td><?= $e($a['by'] ?? '') ?></td><td><span class="pill APPROVED"><?= $e($a['event'] ?? '') ?></span></td><td><?= $e($a['detail'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- ============ TAB: TETAPAN ============ -->
    <div class="tabsec" id="tab-settings">
        <h3 class="sec-title">// KAWALAN SISTEM</h3>
        <div class="setgrid">
            <div class="setcard">
                <h4>SUIS OPERASI</h4>
                <label class="switch"><input type="checkbox" id="swMaint" <?= $settings['maintenance'] ? 'checked' : '' ?>> Mod penyelenggaraan (sekat hantaran baharu)</label>
                <label class="switch"><input type="checkbox" id="swAuto" <?= $settings['autoApprove'] ? 'checked' : '' ?>> Auto-lulus (resit terus tanpa pengesahan)</label>
                <button class="btn btn-view" id="saveToggles">Simpan Tetapan</button>
            </div>
            <div class="setcard">
                <h4>TUKAR KATA LALUAN PANEL</h4>
                <div class="form-group"><input type="password" id="pwOld" placeholder="Kata laluan semasa" autocomplete="current-password"></div>
                <div class="form-group"><input type="password" id="pwNew" placeholder="Kata laluan baharu (≥6)" autocomplete="new-password"></div>
                <div class="form-group"><input type="password" id="pwNew2" placeholder="Ulang kata laluan baharu" autocomplete="new-password"></div>
                <button class="btn btn-view" id="savePw">Tukar Kata Laluan</button>
            </div>
            <div class="setcard danger">
                <h4>ZON BAHAYA</h4>
                <p>Padam SEMUA data transaksi dan mula kosong. Tindakan ini direkod dalam log audit.</p>
                <button class="btn btn-danger" id="resetAll">Reset Semua Data</button>
            </div>
        </div>
    </div>
</div>

<div id="pvModal"><div id="pvBox">
    <div id="pvBar">
        <select id="pvRegion" class="select" style="flex:1;min-width:140px">
            <option value="maybank">Maybank (Melayu/English)</option>
            <option value="japan">Jepun (日本語)</option>
            <option value="us">Bank of America (English)</option>
            <option value="eu">Eurobank (English)</option>
            <option value="generic">Generik</option>
        </select>
        <button class="btn" onclick="window.print()">Cetak</button>
        <button class="btn" id="pvClose">Tutup</button>
    </div>
    <div id="pvBody"></div>
</div></div>

<script src="../receipts.js"></script>
<script>
(function () {
    async function post(path, body) {
        const res = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        let data = null;
        try { data = await res.json(); } catch { /* abaikan */ }
        if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || ('HTTP ' + res.status));
        return data;
    }
    // ---- tab ----
    const tabs = document.querySelectorAll('.tabbtn');
    function showTab(name) {
        tabs.forEach(b => b.classList.toggle('on', b.getAttribute('data-tab') === name));
        document.querySelectorAll('.tabsec').forEach(s => s.classList.toggle('on', s.id === 'tab-' + name));
        if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    }
    tabs.forEach(b => b.addEventListener('click', () => showTab(b.getAttribute('data-tab'))));
    const h0 = (location.hash || '').replace('#', '');
    if (['txns', 'stats', 'audit', 'settings'].includes(h0)) showTab(h0);

    // ---- keputusan tunggal ----
    async function decide(id, action) {
        if (action === 'reject' && !confirm('Tolak transaksi ' + id + '?')) return;
        try { await post('../api/decision.php', { id, action }); location.reload(); }
        catch (e) { alert('Gagal: ' + e.message); }
    }
    document.querySelectorAll('[data-act]').forEach(b =>
        b.addEventListener('click', () => decide(b.getAttribute('data-id'), b.getAttribute('data-act'))));

    // ---- pukal ----
    const chkAll = document.getElementById('chkAll');
    const bulkbar = document.getElementById('bulkbar');
    function selIds() {
        return [...document.querySelectorAll('.bulkchk:checked')].map(c => c.value);
    }
    function refreshBulk() {
        const n = selIds().length;
        document.getElementById('bulkcount').textContent = n ? (n + ' dipilih') : '';
        bulkbar.classList.toggle('show', n > 0);
        if (chkAll) {
            const all = document.querySelectorAll('.bulkchk');
            chkAll.checked = all.length > 0 && n === all.length;
        }
    }
    if (chkAll) chkAll.addEventListener('change', () => {
        document.querySelectorAll('.bulkchk').forEach(c => { c.checked = chkAll.checked; });
        refreshBulk();
    });
    document.querySelectorAll('.bulkchk').forEach(c => c.addEventListener('change', refreshBulk));
    async function bulk(action) {
        const ids = selIds();
        if (!ids.length) return;
        if (!confirm((action === 'approve' ? 'Sahkan ' : 'Tolak ') + ids.length + ' transaksi?')) return;
        try {
            const d = await post('../api/bulk.php', { ids, action });
            alert('Berjaya: ' + d.done.length + (d.failed.length ? ' · Gagal: ' + d.failed.length : ''));
            location.reload();
        } catch (e) { alert('Gagal: ' + e.message); }
    }
    const bA = document.getElementById('bulkApprove');
    const bR = document.getElementById('bulkReject');
    if (bA) bA.addEventListener('click', () => bulk('approve'));
    if (bR) bR.addEventListener('click', () => bulk('reject'));

    // ---- padam ----
    document.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
        const id = b.getAttribute('data-del');
        if (!confirm('Padam transaksi ' + id + ' dari rekod?')) return;
        try { await post('../api/delete.php', { id }); location.reload(); }
        catch (e) { alert('Gagal: ' + e.message); }
    }));

    // ---- cipta manual ----
    const mBtn = document.getElementById('mCreate');
    if (mBtn) mBtn.addEventListener('click', async () => {
        const cents = Math.round(parseFloat(document.getElementById('mAmount').value) * 100);
        if (!Number.isFinite(cents) || cents <= 0) { alert('Jumlah tidak sah.'); return; }
        const body = {
            type: document.getElementById('mType').value,
            amountCents: cents,
            currency: document.getElementById('mCurrency').value,
            region: document.getElementById('mRegion').value,
            recipient: document.getElementById('mRecipient').value.trim(),
            note: document.getElementById('mNote').value.trim()
        };
        try {
            const d = await post('../api/manual.php', body);
            alert('Dicipta & diluluskan: ' + d.txn.ref);
            location.reload();
        } catch (e) { alert('Gagal: ' + e.message); }
    });

    // ---- tetapan ----
    const svT = document.getElementById('saveToggles');
    if (svT) svT.addEventListener('click', async () => {
        try {
            await post('../api/settings.php', {
                action: 'toggles',
                maintenance: document.getElementById('swMaint').checked,
                autoApprove: document.getElementById('swAuto').checked
            });
            alert('Tetapan disimpan.');
            location.reload();
        } catch (e) { alert('Gagal: ' + e.message); }
    });
    const svP = document.getElementById('savePw');
    if (svP) svP.addEventListener('click', async () => {
        const nw = document.getElementById('pwNew').value;
        if (nw !== document.getElementById('pwNew2').value) { alert('Pengesahan kata laluan tidak sama.'); return; }
        try {
            await post('../api/settings.php', { action: 'password', old: document.getElementById('pwOld').value, new: nw });
            alert('Kata laluan ditukar. Sila log masuk semula.');
            location.href = 'logout.php';
        } catch (e) { alert('Gagal: ' + e.message); }
    });
    const rsA = document.getElementById('resetAll');
    if (rsA) rsA.addEventListener('click', async () => {
        if (!confirm('PADAM SEMUA DATA? Tindakan ini tidak boleh diundur.')) return;
        if (!confirm('Sahkan sekali lagi: reset semua transaksi?')) return;
        try { await post('../api/delete.php', { all: true }); location.reload(); }
        catch (e) { alert('Gagal: ' + e.message); }
    });

    // ---- auto-refresh: banner bila ada transaksi baharu ----
    const banner = document.getElementById('newBanner');
    let baseCount = <?= count($pendingAll) ?>;
    setInterval(async () => {
        const tgl = document.getElementById('arToggle');
        if (tgl && !tgl.checked) return;
        try {
            const res = await fetch('../api/list.php?status=pending');
            const data = await res.json();
            if (data.ok && data.count > baseCount) {
                document.getElementById('newBannerText').textContent =
                    '⚡ ' + (data.count - baseCount) + ' transaksi baharu menunggu pengesahan.';
                banner.classList.add('show');
            }
        } catch { /* abaikan — cuba lagi nanti */ }
    }, 15000);

    // ---- pratonton resit ----
    async function preview(id) {
        const res = await fetch('../api/list.php?status=all');
        const data = await res.json();
        if (!data.ok) { alert('Gagal memuatkan: ' + (data.error || res.status)); return; }
        const t = (data.txns || []).find(x => x.id === id);
        if (!t) { alert('Transaksi tidak dijumpai.'); return; }
        pvTxn = t;
        const sel = document.getElementById('pvRegion');
        if (sel) sel.value = (t.region && window.REGIONS && window.REGIONS[t.region]) ? t.region : activeRegionKey;
        renderPv();
        document.getElementById('pvModal').style.display = 'flex';
    }
    function renderPv() {
        if (!pvTxn) return;
        const sel = document.getElementById('pvRegion');
        if (sel && sel.value && window.REGIONS && window.REGIONS[sel.value]) activeRegionKey = sel.value;
        const meta = '<div class="pv-meta">STATUS <b>' + pvTxn.status + '</b> · ' +
            (pvTxn.decidedAt ? 'DIPUTUSKAN <b>' + pvTxn.decidedAt + '</b> OLEH <b>' + (pvTxn.decidedBy || '—') + '</b> · ' : '') +
            'HANTAR <b>' + pvTxn.dateISO + '</b></div>';
        document.getElementById('pvBody').innerHTML = meta + receiptHTML(pvTxn);
    }
    let pvTxn = null;
    document.getElementById('pvRegion').addEventListener('change', renderPv);
    document.querySelectorAll('[data-preview]').forEach(b =>
        b.addEventListener('click', () => preview(b.getAttribute('data-preview'))));
    document.getElementById('pvClose').addEventListener('click', () => {
        document.getElementById('pvModal').style.display = 'none';
    });
    document.getElementById('pvModal').addEventListener('click', (e) => {
        if (e.target.id === 'pvModal') e.target.style.display = 'none';
    });
})();
</script>
</body>
</html>
