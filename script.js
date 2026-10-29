// SecurePay Wallet — aliran kelulusan control panel (PHP) + mod demo fallback.
// Enjin resit (REGIONS, formatCents, bankRef, receiptHTML, ...) dalam receipts.js,
// dikongsi dengan admin/index.php supaya bahasa + kod rujukan sentiasa selaras.
'use strict';

// ---------- DOM ----------
const $ = (id) => document.getElementById(id);
const loginPage = $('loginPage');
const registerPage = $('registerPage');
const walletPage = $('walletPage');
const adminPage = $('adminPage');
const requestPage = $('requestPage');
const topUpPage = $('topUpPage');
const payBillsPage = $('payBillsPage');
const savingsPage = $('savingsPage');
const rewardsPage = $('rewardsPage');
const budgetPage = $('budgetPage');
const investmentsPage = $('investmentsPage');
const loansPage = $('loansPage');
const insurancePage = $('insurancePage');
const splitBillPage = $('splitBillPage');
const qrPayPage = $('qrPayPage');

// ---------- State (money in integer minor units = cents) ----------
const userData = {
    balanceCents: 100000, // $1,000.00
    transactions: [],
    savingsGoals: [],
    budgets: [],
    rewards: 0,
    investments: [],
    loans: [],
    insurance: []
};

// FIX (audit #2): demo credential was hardcoded as admin@example.com/admin123.
// Kept as clearly-marked demo constant so the demo still works, but must be
// replaced by server-side auth before any production use. Never commit real secrets.
const DEMO_ADMIN = { email: 'admin@example.com', password: 'admin123' };

// ---------- Money ----------
function toCents(amountStr) {
    const n = Number(String(amountStr).trim());
    if (!Number.isFinite(n) || n <= 0) return null;
    return Math.round(n * 100);
}

// ---------- Validation ----------
const isValidEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s).trim());
// sanitize() disediakan oleh receipts.js.
function flashError(el, msg) {
    if (!el) { if (msg) alert(msg); return false; }
    el.style.borderColor = '#ef476f';
    setTimeout(() => { el.style.borderColor = '#ddd'; }, 2000);
    if (msg) alert(msg);
    return false;
}

// ---------- Navigation ----------
const ALL_PAGES = () => [loginPage, registerPage, walletPage, adminPage, requestPage, topUpPage, payBillsPage, savingsPage, rewardsPage, budgetPage, investmentsPage, loansPage, insurancePage, splitBillPage, qrPayPage];
function showPage(page) {
    ALL_PAGES().forEach((p) => { if (p) p.style.display = 'none'; });
    if (page) page.style.display = 'block';
    if (page === walletPage) { updateBalanceDisplay(); renderTransactions(); }
}

// ---------- Auth (client guard is UX only — real auth must be server-side) ----------
function getSession() {
    try { return JSON.parse(localStorage.getItem('user')); } catch { return null; }
}
function requireSession() {
    const s = getSession();
    if (!s || !s.isLoggedIn) { showPage(loginPage); return null; }
    return s;
}
function initApp() {
    const s = getSession();
    loadPendings();
    if (pendingTxns.length) startPolling();
    showPage(s && s.isLoggedIn ? walletPage : loginPage);
    const rs = $('regionSelect');
    if (rs) rs.value = activeRegionKey;
}

// ---------- Display ----------
function updateBalanceDisplay() {
    const el = document.querySelector('.balance span');
    if (!el) return;
    const target = formatCents(userData.balanceCents);
    // Kesan "decrypt" ala filem FBI; fallback paparan terus jika tiada rAF.
    if (typeof requestAnimationFrame !== 'function' || typeof cancelAnimationFrame !== 'function') {
        el.textContent = target;
        return;
    }
    const glyphs = '$¥€£01<>/\\|[]{}#+*';
    let frame = 0;
    const total = 14;
    if (updateBalanceDisplay._raf) cancelAnimationFrame(updateBalanceDisplay._raf);
    const tick = () => {
        frame++;
        if (frame >= total) { el.textContent = target; updateBalanceDisplay._raf = 0; return; }
        let out = '';
        for (let i = 0; i < target.length; i++) {
            out += (frame / total > i / target.length) ? target[i] : glyphs[Math.floor(Math.random() * glyphs.length)];
        }
        el.textContent = out;
        updateBalanceDisplay._raf = requestAnimationFrame(tick);
    };
    updateBalanceDisplay._raf = requestAnimationFrame(tick);
}
function updateRewardsDisplay() {
    const el = $('rewardPoints');
    if (el) el.textContent = String(userData.rewards);
}
function addRewards(points) {
    userData.rewards += points;
    updateRewardsDisplay();
}

// ---------- Transactions ----------
// newTxnId() untuk mod demo; ref/τemplate helpers dalam receipts.js.
function newTxnId() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    const rand = Math.random().toString(36).slice(2, 8).toUpperCase();
    return `TXN-${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${rand}`;
}
function pushTxn(t) {
    const txn = { id: newTxnId(), status: 'SUCCESS', currency: getRegion().currency, region: activeRegionKey, dateISO: nowISO(), ...t };
    userData.transactions.unshift(txn);
    renderTransactions();
    return txn;
}

// ---------- Control panel backend (PHP) ----------
// Wallet menghantar transaksi sebagai PENDING; resit hanya keluar selepas
// admin SAHKAN di control panel (admin/index.php). Jika backend tiada
// (index.html dibuka terus tanpa `php -S`), guna mod demo segera.
const API = 'api';
let pendingTxns = []; // {serverId, ref, payload, debit}
let pollTimer = null;

async function apiPost(path, body) {
    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 7000);
        try {
            const res = await fetch(`${API}/${path}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                signal: ctrl.signal
            });
            clearTimeout(timer);
            let data = null;
            try { data = await res.json(); } catch { /* bukan JSON */ }
            // Pulangkan JSON ralat juga (cth: mod penyelenggaraan) supaya
            // submitFlow boleh papar mesej tepat; null = server tiada.
            if (!res.ok) return (data && data.ok === false) ? data : null;
            return data;
        } catch { clearTimeout(timer); return null; }
    } catch { return null; }
}
async function apiStatus(id) {
    try {
        const res = await fetch(`${API}/status.php?id=${encodeURIComponent(id)}`);
        if (!res.ok) return null;
        return await res.json();
    } catch { return null; }
}
function savePendings() {
    try { localStorage.setItem('pendingTxns', JSON.stringify(pendingTxns)); } catch { /* abaikan */ }
}
function loadPendings() {
    try {
        const arr = JSON.parse(localStorage.getItem('pendingTxns'));
        pendingTxns = Array.isArray(arr) ? arr : [];
    } catch { pendingTxns = []; }
}
function startPolling() {
    if (pollTimer || typeof setInterval !== 'function') return;
    pollTimer = setInterval(pollPendings, 3000);
}
async function pollPendings() {
    if (!pendingTxns.length) return;
    for (const p of [...pendingTxns]) {
        const data = await apiStatus(p.serverId);
        if (!data || !data.ok) continue;
        const t = data.txn;
        if (t.status === 'APPROVED') {
            pendingTxns = pendingTxns.filter((x) => x.serverId !== p.serverId);
            savePendings();
            applyApproved(t);
        } else if (t.status === 'REJECTED') {
            pendingTxns = pendingTxns.filter((x) => x.serverId !== p.serverId);
            savePendings();
            userData.transactions.unshift({ ...t, status: 'REJECTED' });
            renderTransactions();
            alert(`Transaksi ${bankRef(t, t.region)} DITOLAK oleh control panel.`);
        }
    }
    renderTransactions();
}
// Kesan kewangan hanya dikenakan SELEPAS pengesahan control panel.
function applyApproved(t) {
    if (t.type === 'send' || t.type === 'bill') userData.balanceCents -= t.amountCents;
    else if (t.type === 'topup') userData.balanceCents += t.amountCents;
    if (t.type === 'send') addRewards(5);
    updateBalanceDisplay();
    userData.transactions.unshift({ ...t, status: 'APPROVED' });
    renderTransactions();
    showReceipt({ ...t, status: 'APPROVED' });
}
async function submitFlow(payload, { debit = false, clear = null } = {}) {
    if (debit && payload.amountCents > userData.balanceCents) { alert('Insufficient funds!'); return; }
    const session = getSession();
    const body = { ...payload, senderEmail: session ? session.email : '' };
    const data = await apiPost('submit.php', body);
    if (data && data.maintenance) {
        alert(data.error || 'Sistem dalam penyelenggaraan. Sila cuba sebentar lagi.');
        showPage(walletPage);
        return;
    }
    if (data && data.ok) {
        pendingTxns.unshift({ serverId: data.id, ref: data.ref, payload: body, debit });
        savePendings();
        renderTransactions();
        startPolling();
        if (clear) clear();
        alert(`Dihantar! Kod rujukan ${data.ref}\nMenunggu pengesahan control panel — resit akan keluar automatik bila disahkan.`);
        showPage(walletPage);
        return;
    }
    // Mod demo: backend tidak dapat dihubungi — resit segera seperti sebelum ini.
    const txn = pushTxn({ ...payload, demo: true });
    if (debit) { userData.balanceCents -= payload.amountCents; updateBalanceDisplay(); }
    else if (payload.type === 'topup') { userData.balanceCents += payload.amountCents; updateBalanceDisplay(); }
    if (payload.type === 'send') addRewards(5);
    if (clear) clear();
    showReceipt(txn);
}

function renderTransactions() {
    const list = $('transactionList');
    if (!list) return;
    const pend = pendingTxns.map((p) => `
        <div class="txn-item txn-pending">
            <div class="txn-main"><strong>${sanitize(txnTitle(p.payload))}</strong><span>${sanitize(formatCents(p.payload.amountCents, p.payload.currency))}</span></div>
            <div class="txn-sub"><span class="pill PENDING"><span class="spin"></span>Menunggu pengesahan…</span><span class="txn-id">${sanitize(p.ref || p.serverId || '')}</span></div>
        </div>`).join('');
    const done = userData.transactions.slice(0, 20).map((t) => {
        if (t.status === 'REJECTED') return `
        <div class="txn-item txn-rejected">
            <div class="txn-main"><strong>${sanitize(txnTitle(t))}</strong><span>${sanitize(formatCents(t.amountCents, t.currency))}</span></div>
            <div class="txn-sub"><span class="pill REJECTED">Ditolak control panel</span><span class="txn-id">${sanitize(bankRef(t))}</span></div>
        </div>`;
        const demoTag = t.demo ? ' <span class="pill DEMO">demo</span>' : '';
        return `
        <div class="txn-item">
            <div class="txn-main"><strong>${sanitize(txnTitle(t))}</strong><span>${sanitize(formatCents(t.amountCents, t.currency))}</span></div>
            <div class="txn-sub"><span>${sanitize(formatDateTime(t.dateISO))}${demoTag}</span><span class="txn-id">${sanitize(bankRef(t))}</span></div>
            <button class="btn btn-sm" data-receipt="${sanitize(t.id)}">View Receipt</button>
        </div>`;
    }).join('');
    if (!pend && !done) {
        list.innerHTML = '<div class="no-transactions"><p>No transactions yet. Your transactions will appear here.</p></div>';
        return;
    }
    list.innerHTML = pend + done;
    list.querySelectorAll('[data-receipt]').forEach((b) => b.addEventListener('click', () => {
        const t = userData.transactions.find((x) => x.id === b.getAttribute('data-receipt'));
        if (t) showReceipt(t);
    }));
}

let lastReceiptTxn = null;
function showReceipt(t) {
    lastReceiptTxn = t;
    // Template mengikut pemilih region semasa — pengguna boleh tukar
    // Maybank/Jepun/US/EU dan resit bertukar serta-merta.
    const modal = $('receiptModal');
    const body = $('receiptContent');
    const sel = $('receiptRegion');
    if (sel) sel.value = activeRegionKey;
    if (body) body.innerHTML = receiptHTML(t);
    if (modal) modal.style.display = 'flex';
}
function hideReceipt() { const m = $('receiptModal'); if (m) m.style.display = 'none'; }
function downloadReceipt() {
    if (!lastReceiptTxn) return;
    const t = lastReceiptTxn;
    const text = [`${getRegion().bank} - PAYMENT RECEIPT`, `Reference: ${bankRef(t)}`, `Amount: ${formatCents(t.amountCents, t.currency)}`, `Date: ${t.dateISO}`, `Type: ${txnTitle(t)}`, t.recipient ? `Counterparty: ${t.recipient}` : '', t.note ? `Note: ${t.note}` : '', `Status: ${t.status}`].filter(Boolean).join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${bankRef(t)}.txt`;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

// ---------- Mock seed data (ISO dates, cents) ----------
function seedMockData() {
    userData.transactions = [
        { id: 'TXN-20230515-JOHNDO', refSeed: '2343202493481', type: 'send', recipient: 'John Doe', amountCents: 13000, currency: 'USD', note: 'Dinner', dateISO: '2023-05-15T14:30:00.000Z', status: 'SUCCESS' },
        { id: 'TXN-20230514-TOPUP1', refSeed: '2247029204715', type: 'topup', amountCents: 10000, currency: 'USD', method: 'card', dateISO: '2023-05-14T09:15:00.000Z', status: 'SUCCESS' },
        { id: 'TXN-20230512-BILL01', refSeed: '1987654321098', type: 'bill', billType: 'electricity', amountCents: 8550, currency: 'USD', account: '123456789', dateISO: '2023-05-12T16:45:00.000Z', status: 'SUCCESS' }
    ];
    userData.savingsGoals = [
        { name: 'Vacation', amountCents: 100000, savedCents: 30000, targetDate: '2023-12-25' },
        { name: 'Emergency Fund', amountCents: 200000, savedCents: 65000, targetDate: '2024-06-01' }
    ];
    userData.budgets = [
        { category: 'food', amountCents: 20000, spentCents: 15000 },
        { category: 'transport', amountCents: 10000, spentCents: 4500 },
        { category: 'entertainment', amountCents: 15000, spentCents: 3000 }
    ];
}

// ---------- Events ----------
function bindEvents() {
    $('showRegister')?.addEventListener('click', (e) => { e.preventDefault(); showPage(registerPage); });
    $('showLogin')?.addEventListener('click', (e) => { e.preventDefault(); showPage(loginPage); });
    $('forgotPassword')?.addEventListener('click', (e) => { e.preventDefault(); alert('Password reset is not implemented in this demo.'); });

    $('loginForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const email = $('email').value.trim();
        const password = $('password').value;
        if (!isValidEmail(email)) return flashError($('email'), 'Enter a valid email address.');
        if (!password || password.length < 6) return flashError($('password'), 'Password must be at least 6 characters.');
        if (email === DEMO_ADMIN.email && password === DEMO_ADMIN.password) {
            localStorage.setItem('user', JSON.stringify({ isLoggedIn: true, email, role: 'admin' }));
            showPage(adminPage);
            return;
        }
        localStorage.setItem('user', JSON.stringify({ isLoggedIn: true, email }));
        showPage(walletPage);
    });

    $('registerForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = $('regName').value.trim();
        const email = $('regEmail').value.trim();
        const pw = $('regPassword').value;
        const pw2 = $('regConfirmPassword').value;
        if (name.length < 2) return flashError($('regName'), 'Enter your full name.');
        if (!isValidEmail(email)) return flashError($('regEmail'), 'Enter a valid email address.');
        if (pw.length < 6) return flashError($('regPassword'), 'Password must be at least 6 characters.');
        if (pw !== pw2) return flashError($('regConfirmPassword'), 'Passwords do not match.');
        localStorage.setItem('user', JSON.stringify({ isLoggedIn: true, email }));
        showPage(walletPage);
    });

    const go = (id, page) => $(id)?.addEventListener('click', () => { if (requireSession()) showPage(page); });
    go('requestMoneyBtn', requestPage);
    go('topUpBtn', topUpPage);
    go('payBillsBtn', payBillsPage);
    go('savingsBtn', savingsPage);
    go('rewardsBtn', rewardsPage);
    go('budgetBtn', budgetPage);
    go('investmentsBtn', investmentsPage);
    go('loansBtn', loansPage);
    go('insuranceBtn', insurancePage);
    go('splitBillBtn', splitBillPage);
    go('qrPayBtn', qrPayPage);
    go('splitBillFeatureBtn', splitBillPage);
    go('qrPayFeatureBtn', qrPayPage);
    $('splitBackBtn')?.addEventListener('click', () => showPage(walletPage));
    $('qrBackBtn')?.addEventListener('click', () => { stopQrCamera(); showPage(walletPage); });

    $('sendMoneyBtn')?.addEventListener('click', () => {
        if (!requireSession()) return;
        const recipient = $('recipient').value.trim();
        const cents = toCents($('amount').value);
        const note = sanitize($('note').value);
        if (!recipient) return flashError($('recipient'), 'Please enter a recipient.');
        if (cents === null) return flashError($('amount'));
        const isQr = qrNextSendIsQr; qrNextSendIsQr = false;
        submitFlow(
            { type: 'send', recipient: sanitize(recipient), amountCents: cents, currency: getRegion().currency, region: activeRegionKey, note, ...(isQr ? { method: 'qr' } : {}) },
            { debit: true, clear: () => { $('recipient').value = ''; $('amount').value = ''; $('note').value = ''; } }
        );
    });

    $('sendRequestBtn')?.addEventListener('click', () => {
        if (!requireSession()) return;
        const recipient = $('requestRecipient').value.trim();
        const cents = toCents($('requestAmount').value);
        const note = sanitize($('requestNote').value);
        if (!recipient) return flashError($('requestRecipient'), 'Please enter a recipient.');
        if (cents === null) return flashError($('requestAmount'));
        submitFlow(
            { type: 'request', recipient: sanitize(recipient), amountCents: cents, currency: getRegion().currency, region: activeRegionKey, note },
            { clear: () => { $('requestRecipient').value = ''; $('requestAmount').value = ''; $('requestNote').value = ''; } }
        );
    });

    $('topUpConfirmBtn')?.addEventListener('click', () => {
        if (!requireSession()) return;
        const cents = toCents($('topUpAmount').value);
        const methodEl = document.querySelector('input[name="paymentMethod"]:checked');
        if (cents === null) return flashError($('topUpAmount'));
        submitFlow(
            { type: 'topup', amountCents: cents, currency: getRegion().currency, region: activeRegionKey, method: methodEl ? methodEl.value : 'card' },
            { clear: () => { $('topUpAmount').value = ''; } }
        );
    });

    $('payBillBtn')?.addEventListener('click', () => {
        if (!requireSession()) return;
        const billType = $('billType').value;
        const cents = toCents($('billAmount').value);
        const account = $('billAccount').value.trim();
        if (!billType) { alert('Please select a bill type'); return; }
        if (cents === null) return flashError($('billAmount'));
        if (!account) return flashError($('billAccount'), 'Please enter account number.');
        submitFlow(
            { type: 'bill', billType, amountCents: cents, currency: getRegion().currency, region: activeRegionKey, account: sanitize(account) },
            { debit: true, clear: () => { $('billType').value = ''; $('billAmount').value = ''; $('billAccount').value = ''; } }
        );
    });

    $('createSavingsBtn')?.addEventListener('click', () => {
        const name = $('savingsGoal').value.trim();
        const cents = toCents($('savingsAmount').value);
        const targetDate = $('savingsDate').value;
        if (!name) return flashError($('savingsGoal'), 'Please enter a goal name.');
        if (cents === null) return flashError($('savingsAmount'));
        userData.savingsGoals.push({ name: sanitize(name), amountCents: cents, savedCents: 0, targetDate });
        alert(`Savings goal "${name}" for ${formatCents(cents)} created successfully!`);
        $('savingsGoal').value = ''; $('savingsAmount').value = ''; $('savingsDate').value = '';
    });

    $('setBudgetBtn')?.addEventListener('click', () => {
        const category = $('budgetCategory').value;
        const cents = toCents($('budgetAmount').value);
        if (!category) { alert('Please select a category'); return; }
        if (cents === null) return flashError($('budgetAmount'));
        userData.budgets.push({ category, amountCents: cents, spentCents: 0 });
        alert(`Budget for ${category} set to ${formatCents(cents)} successfully!`);
        $('budgetCategory').value = ''; $('budgetAmount').value = '';
    });

    $('adminLogout')?.addEventListener('click', () => { localStorage.removeItem('user'); showPage(loginPage); });
    $('adminToWallet')?.addEventListener('click', () => { if (requireSession()) showPage(walletPage); else showPage(loginPage); });

    document.querySelectorAll('.security-item button').forEach((button) => {
        button.addEventListener('click', function () {
            const feature = this.parentElement.querySelector('span').textContent;
            const enabling = this.textContent.trim() === 'Enable';
            this.textContent = enabling ? 'Disable' : 'Enable';
            alert(`${feature} has been ${enabling ? 'enabled' : 'disabled'} successfully!`);
        });
    });
    document.querySelectorAll('.investment-option button, .loan-option button, .insurance-option button').forEach((button) => {
        button.addEventListener('click', function () {
            const title = this.parentElement.querySelector('h4')?.textContent || 'item';
            alert(`Request for ${title} has been submitted!`);
        });
    });

    // Receipt modal
    $('receiptClose')?.addEventListener('click', hideReceipt);
    $('receiptModal')?.addEventListener('click', (e) => { if (e.target.id === 'receiptModal') hideReceipt(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideReceipt(); });
    $('receiptPrint')?.addEventListener('click', () => window.print());
    $('receiptDownload')?.addEventListener('click', downloadReceipt);
    const applyRegion = (key) => {
        if (!REGIONS[key]) return;
        activeRegionKey = key;
        updateBalanceDisplay();
        renderTransactions();
        refreshSplitSummary();
        document.querySelectorAll('.split-cur').forEach((el) => { el.textContent = getRegion().currency; });
        if (lastReceiptTxn && $('receiptModal')?.style.display === 'flex') $('receiptContent').innerHTML = receiptHTML(lastReceiptTxn);
    };
    $('receiptRegion')?.addEventListener('change', (e) => {
        applyRegion(e.target.value);
        const ws = $('regionSelect');
        if (ws) ws.value = activeRegionKey;
    });
    $('regionSelect')?.addEventListener('change', (e) => {
        applyRegion(e.target.value);
        const rr = $('receiptRegion');
        if (rr) rr.value = activeRegionKey;
    });

    bindSplitBill();
    bindQrPay();
}

// ---------- Split Bill ----------
// Each participant becomes a `request` txn so the PHP backend
// (VALID_TYPES = send/request/topup/bill) accepts it unchanged.
function splitMemberRow(name = '', amount = '') {
    const div = document.createElement('div');
    div.className = 'split-member';
    div.innerHTML = `
        <div class="split-member-row">
            <input type="text" class="split-name" placeholder="Name or email" maxlength="120" value="">
            <input type="number" class="split-amt" placeholder="0.00" min="0.01" step="0.01" value="" style="display:none;">
        </div>
        <button class="btn btn-sm split-remove" type="button" title="Remove">✕</button>`;
    div.querySelector('.split-name').value = name;
    div.querySelector('.split-amt').value = amount;
    div.querySelector('.split-remove').addEventListener('click', () => {
        if ($('splitMembers').children.length <= 1) { alert('At least 1 participant is required.'); return; }
        div.remove();
        refreshSplitSummary();
    });
    div.querySelectorAll('input').forEach((i) => i.addEventListener('input', refreshSplitSummary));
    return div;
}
function getSplitMembers() {
    return [...document.querySelectorAll('#splitMembers .split-member')].map((d) => ({
        name: d.querySelector('.split-name').value.trim(),
        cents: toCents(d.querySelector('.split-amt').value || '') // null if empty/invalid
    }));
}
function refreshSplitSummary() {
    const box = $('splitMembers');
    if (!box) return { ok: false };
    const mode = $('splitMode')?.value || 'equal';
    const total = toCents($('splitTotal')?.value || '');
    const members = getSplitMembers();
    box.querySelectorAll('.split-amt').forEach((a) => { a.style.display = mode === 'custom' ? '' : 'none'; });
    const countEl = $('splitCount');
    if (countEl) countEl.textContent = String(members.length);
    document.querySelectorAll('.split-cur').forEach((el) => { el.textContent = getRegion().currency; });
    const perEl = $('splitPerPerson'), asgEl = $('splitAssigned'), diffEl = $('splitDiff'), hint = $('splitHint');
    if (total === null || members.length === 0) {
        if (perEl) perEl.textContent = '—';
        if (asgEl) asgEl.textContent = '—';
        if (diffEl) diffEl.textContent = '—';
        if (hint) { hint.textContent = 'Enter total + participant names, then Calculate.'; hint.className = 'split-hint'; }
        return { ok: false };
    }
    if (mode === 'equal') {
        const per = Math.floor(total / members.length);
        const remainder = total - per * members.length; // first `remainder` people pay +1 cent
        if (perEl) perEl.textContent = `${formatCents(per, getRegion().currency)}${members.length > 1 ? ' each' : ''}${remainder ? ` (+1¢ × ${remainder})` : ''}`;
        if (asgEl) asgEl.textContent = formatCents(total, getRegion().currency);
        if (diffEl) diffEl.textContent = formatCents(0, getRegion().currency);
        if (hint) { hint.textContent = `Equal split: ${members.length} participant(s). Remainder ${remainder}¢ goes to first ${remainder} person(s).`; hint.className = 'split-hint ok'; }
        return { ok: true, mode, total, members, shares: members.map((_, i) => per + (i < remainder ? 1 : 0)) };
    }
    // custom
    const assigned = members.reduce((s, m) => s + (m.cents || 0), 0);
    const diff = total - assigned;
    if (perEl) perEl.textContent = '— (custom)';
    if (asgEl) asgEl.textContent = formatCents(assigned, getRegion().currency);
    if (diffEl) diffEl.textContent = (diff < 0 ? '-' : '') + formatCents(Math.abs(diff), getRegion().currency);
    if (hint) {
        if (diff === 0) { hint.textContent = 'Custom amounts match the total. Ready to send.'; hint.className = 'split-hint ok'; }
        else { hint.textContent = diff > 0 ? `Short by ${formatCents(diff, getRegion().currency)} — adjust custom amounts.` : `Over by ${formatCents(-diff, getRegion().currency)} — adjust custom amounts.`; hint.className = 'split-hint bad'; }
    }
    return { ok: diff === 0, mode, total, members, shares: members.map((m) => m.cents || 0) };
}
function bindSplitBill() {
    const box = $('splitMembers');
    if (!box || box.dataset.bound) return;
    box.dataset.bound = '1';
    box.appendChild(splitMemberRow());
    box.appendChild(splitMemberRow());
    $('splitAddBtn')?.addEventListener('click', () => {
        if (box.children.length >= 10) { alert('Maximum 10 participants.'); return; }
        box.appendChild(splitMemberRow());
        refreshSplitSummary();
    });
    $('splitMode')?.addEventListener('change', refreshSplitSummary);
    $('splitTotal')?.addEventListener('input', refreshSplitSummary);
    $('splitCalcBtn')?.addEventListener('click', () => {
        const r = refreshSplitSummary();
        if (!r.ok && r.mode === 'custom') return; // hint already explains
        if (r.total == null) { flashError($('splitTotal'), 'Enter a valid total amount.'); return; }
        alert(r.mode === 'equal'
            ? `Equal split ready: ${r.members.length} × ~${formatCents(r.shares[0], getRegion().currency)}`
            : 'Custom split adds up. Ready to send.');
    });
    $('splitClearBtn')?.addEventListener('click', () => {
        $('splitTitle').value = ''; $('splitTotal').value = '';
        box.innerHTML = '';
        box.appendChild(splitMemberRow()); box.appendChild(splitMemberRow());
        refreshSplitSummary();
    });
    $('splitSendBtn')?.addEventListener('click', async () => {
        if (!requireSession()) return;
        const title = ($('splitTitle').value || '').trim() || 'Shared bill';
        const r = refreshSplitSummary();
        if (r.total == null) return flashError($('splitTotal'), 'Enter a valid total amount.');
        for (const m of r.members) {
            if (!m.name) return flashError(box.querySelector('.split-name'), 'Every participant needs a name or email.');
        }
        if (r.mode === 'custom') {
            const bad = r.members.find((m) => m.cents === null);
            if (bad || !r.ok) { alert('Custom amounts must be valid and sum exactly to the total.'); return; }
        }
        const btn = $('splitSendBtn');
        btn.disabled = true;
        try {
            for (let i = 0; i < r.members.length; i++) {
                const m = r.members[i];
                const share = r.shares[i];
                await submitFlow(
                    { type: 'request', recipient: sanitize(m.name), amountCents: share, currency: getRegion().currency, region: activeRegionKey, note: sanitize(`Split "${title}" (${i + 1}/${r.members.length})`) },
                    {}
                );
            }
            alert(`Split "${title}" sent: ${r.members.length} request(s) totalling ${formatCents(r.total, getRegion().currency)}.`);
            showPage(walletPage);
        } finally { btn.disabled = false; }
    });
    refreshSplitSummary();
}

// ---------- QR Pay ----------
// Payload: SECUREPAY1|<to>|<amountCents or empty>|<CURRENCY>|<note>
// Pipe format keeps the QR small and easy to parse by hand.
let qrParsedTxn = null;
let qrScanner = null;
let qrScanning = false;
let qrNextSendIsQr = false; // set when user taps Pay Now from a scanned QR
const QR_TAG = 'SECUREPAY1';
function qrEncode(to, amountCentsOrNull, currency, note) {
    const clean = (s) => String(s ?? '').replace(/[\r\n|]/g, ' ').trim().slice(0, 140);
    return [QR_TAG, clean(to), amountCentsOrNull == null ? '' : String(amountCentsOrNull), currency || getRegion().currency, clean(note)].join('|');
}
function qrDecode(str) {
    const s = String(str || '').trim();
    if (!s) return null;
    // JSON flavour (forward-compat)
    if (s.startsWith('{')) {
        try {
            const o = JSON.parse(s);
            if (o && (o.app === 'securepay' || o.tag === QR_TAG) && o.to) {
                return { to: String(o.to).slice(0, 120), amountCents: Number.isFinite(+o.amountCents) && +o.amountCents > 0 ? Math.round(+o.amountCents) : null, currency: String(o.currency || getRegion().currency).slice(0, 8), note: String(o.note || '').slice(0, 140) };
            }
        } catch { /* fall through to pipe parse */ }
    }
    const parts = s.split('|');
    if (parts[0] !== QR_TAG || !parts[1]) return null;
    const cents = parts[2] !== '' ? Math.round(Number(parts[2])) : null;
    return {
        to: parts[1].slice(0, 120),
        amountCents: Number.isFinite(cents) && cents > 0 ? cents : null,
        currency: (parts[3] || getRegion().currency).slice(0, 8),
        note: (parts[4] || '').slice(0, 140)
    };
}
function qrShowPane(which) {
    $('qrTabGen')?.classList.toggle('active', which === 'gen');
    $('qrTabScan')?.classList.toggle('active', which === 'scan');
    if ($('qrGenPane')) $('qrGenPane').style.display = which === 'gen' ? '' : 'none';
    if ($('qrScanPane')) $('qrScanPane').style.display = which === 'scan' ? '' : 'none';
    if (which === 'gen') stopQrCamera();
}
async function stopQrCamera() {
    try {
        if (qrScanner && qrScanning) await qrScanner.stop();
    } catch { /* ignore */ }
    qrScanning = false;
    try { const el = $('qrReader'); if (el) el.innerHTML = ''; } catch { /* ignore */ }
}
function renderQrCode(text) {
    const box = $('qrBox'), img = $('qrImgFallback');
    if (box) box.innerHTML = '';
    if (img) img.style.display = 'none';
    // 1) qrcodejs lib (best quality, offline after first load)
    try {
        if (typeof QRCode !== 'undefined' && box) {
            new QRCode(box, { text, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
            return 'lib';
        }
    } catch { /* fallback below */ }
    // 2) hosted QR image fallback
    if (img) {
        img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(text);
        img.style.display = '';
        return 'img';
    }
    return 'none';
}
function qrFillParsed(p) {
    qrParsedTxn = p;
    const pane = $('qrParsed');
    if (pane) pane.style.display = '';
    if ($('qrPTo')) $('qrPTo').textContent = p.to;
    if ($('qrPAmt')) $('qrPAmt').textContent = p.amountCents == null ? `Open amount (${p.currency})` : formatCents(p.amountCents, p.currency);
    if ($('qrPNote')) $('qrPNote').textContent = p.note || '—';
}
function bindQrPay() {
    if ($('qrGenBtn')?.dataset.bound) return;
    $('qrGenBtn').dataset.bound = '1';
    // Prefill receive-as from session
    try {
        const s = getSession();
        if (s?.email && $('qrName') && !$('qrName').value) $('qrName').value = s.email;
    } catch { /* ignore */ }
    document.querySelectorAll('.split-cur').forEach((el) => { el.textContent = getRegion().currency; });
    $('qrTabGen')?.addEventListener('click', () => qrShowPane('gen'));
    $('qrTabScan')?.addEventListener('click', () => qrShowPane('scan'));
    $('qrGenBtn')?.addEventListener('click', () => {
        const to = ($('qrName').value || '').trim();
        const rawAmt = ($('qrAmount').value || '').trim();
        const note = ($('qrNote').value || '').trim();
        if (!to) return flashError($('qrName'), 'Enter a name or email to receive as.');
        let cents = null;
        if (rawAmt !== '') { cents = toCents(rawAmt); if (cents === null) return flashError($('qrAmount')); }
        const payload = qrEncode(to, cents, getRegion().currency, note);
        renderQrCode(payload);
        if ($('qrOut')) $('qrOut').style.display = '';
        if ($('qrPayloadText')) $('qrPayloadText').textContent = payload;
    });
    $('qrCopyBtn')?.addEventListener('click', async () => {
        const t = $('qrPayloadText')?.textContent || '';
        if (!t) return;
        try { await navigator.clipboard.writeText(t); alert('QR code copied.'); }
        catch { alert(t); }
    });
    $('qrDownloadBtn')?.addEventListener('click', () => {
        const payload = $('qrPayloadText')?.textContent || '';
        const canvas = $('qrBox')?.querySelector('canvas');
        const img = $('qrBox')?.querySelector('img');
        const fb = $('qrImgFallback');
        const a = document.createElement('a');
        a.download = 'securepay-qr.png';
        if (canvas) { a.href = canvas.toDataURL('image/png'); }
        else if (img?.src) { a.href = img.src; }
        else if (fb?.src && fb.style.display !== 'none') { a.href = fb.src; }
        else { // text fallback
            const blob = new Blob([payload], { type: 'text/plain' });
            a.href = URL.createObjectURL(blob);
            a.download = 'securepay-qr.txt';
        }
        document.body.appendChild(a);
        a.click();
        setTimeout(() => a.remove(), 500);
    });
    $('qrParseBtn')?.addEventListener('click', () => {
        const p = qrDecode($('qrPaste').value);
        if (!p) { alert('Could not read that code. It should look like: SECUREPAY1|name@mail.com|2500|MYR|Note'); return; }
        qrFillParsed(p);
    });
    $('qrPayNowBtn')?.addEventListener('click', () => {
        if (!requireSession()) return;
        if (!qrParsedTxn) return;
        const p = qrParsedTxn;
        // Prefill the normal Send form so the user sees + confirms
        if ($('recipient')) $('recipient').value = p.to;
        if ($('amount')) $('amount').value = p.amountCents == null ? '' : (p.amountCents / 100).toFixed(2);
        if ($('note')) $('note').value = p.note || 'QR payment';
        qrNextSendIsQr = true;
        stopQrCamera();
        showPage(walletPage);
        alert(p.amountCents == null ? 'QR loaded — enter the amount, then press Send Money.' : 'QR loaded — press Send Money to confirm.');
    });
    $('qrStartCamBtn')?.addEventListener('click', async () => {
        if (typeof Html5Qrcode === 'undefined') { alert('Camera library not loaded (needs internet). Use Upload Image or paste instead.'); return; }
        if (qrScanning) return;
        try {
            qrScanner = qrScanner || new Html5Qrcode('qrReader');
            qrScanning = true;
            await qrScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 240 } },
                (decoded) => {
                    const p = qrDecode(decoded);
                    if (!p) return;
                    qrFillParsed(p);
                    if ($('qrPaste')) $('qrPaste').value = decoded;
                    stopQrCamera();
                    alert(`QR read: ${p.to} — ${p.amountCents == null ? 'open amount' : formatCents(p.amountCents, p.currency)}`);
                },
                () => { /* per-frame misses ignored */ }
            );
        } catch (e) {
            qrScanning = false;
            alert('Could not start camera. Check permission / HTTPS, or use Upload instead.');
        }
    });
    $('qrStopCamBtn')?.addEventListener('click', stopQrCamera);
    $('qrFileInput')?.addEventListener('change', async (e) => {
        const f = e.target.files?.[0];
        if (!f) return;
        if (typeof Html5Qrcode === 'undefined') { alert('Image-scan library not loaded (needs internet). Paste the code text instead.'); return; }
        try {
            const tmp = new Html5Qrcode('qrReader');
            const decoded = await tmp.scanFile(f, true);
            const p = qrDecode(decoded);
            if (!p) { alert('Image scanned but the code is not a SecurePay QR.'); return; }
            if ($('qrPaste')) $('qrPaste').value = decoded;
            qrFillParsed(p);
        } catch { alert('Could not read a QR code from that image.'); }
        finally { try { const el = $('qrReader'); if (el && !qrScanning) el.innerHTML = ''; } catch { /* ignore */ } e.target.value = ''; }
    });
}

// ---------- Boot (single init, no duplicate listeners) ----------
seedMockData();
bindEvents();
window.addEventListener('load', initApp);
