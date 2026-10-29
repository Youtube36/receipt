// SecurePay — shared receipt engine (digunakan oleh wallet index.html + admin control panel)
// Bahasa + kod rujukan unik mengikut bank (rujuk assets/template/):
// - Maybank (MY): dwibahasa Melayu/English, kod MYBK- + 13 digit (cth: MYBK-1234121324124)
// - Jepun (JP): Bahasa Jepun penuh (領収書), 伝票 No. JPBK- + 10 digit, 登録番号 T + 13 digit
// - US (Bank of America): English (US), kod BOFA- + 10 digit
// - EU (Eurobank): English (EU banking), kod EURO- + 12 aksara alfanumerik
'use strict';

const REGIONS = {
    maybank: { key: 'maybank', label: 'Malaysia — Maybank (MYR)', bank: 'maybank2u.com', currency: 'MYR', locale: 'ms-MY', brand: 'maybank' },
    japan:   { key: 'japan', label: 'Jepun — Bank Jepun (JPY)', bank: 'JP BANK', currency: 'JPY', locale: 'ja-JP', brand: 'japan' },
    us:      { key: 'us', label: 'Amerika — Bank of America (USD)', bank: 'Bank of America.', currency: 'USD', locale: 'en-US', brand: 'us' },
    eu:      { key: 'eu', label: 'Eropah — Eurobank (EUR)', bank: 'EUROBANK', currency: 'EUR', locale: 'en-GB', brand: 'eu' },
    generic: { key: 'generic', label: 'Generik / Lain-lain', bank: 'SECUREPAY', currency: 'USD', locale: 'en-US', brand: 'generic' }
};
let activeRegionKey = 'maybank';
const getRegion = () => REGIONS[activeRegionKey] || REGIONS.generic;
function setRegion(key) {
    if (!REGIONS[key]) return false;
    activeRegionKey = key;
    return true;
}

// ---------- Sanitization ----------
const sanitize = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])).slice(0, 500);

// ---------- Money (integer cents, no floats) ----------
// Nota: untuk JPY, cents = yen*100 supaya seragam; formatCents bahagikan semula.
function formatCents(cents, currency, locale) {
    const r = getRegion();
    const cur = currency || r.currency;
    const loc = locale || r.locale;
    if (cur === 'JPY') {
        const yen = Math.round(cents / 100);
        try { return new Intl.NumberFormat(loc, { style: 'currency', currency: 'JPY' }).format(yen); }
        catch { return '¥' + yen.toLocaleString(); }
    }
    try { return new Intl.NumberFormat(loc, { style: 'currency', currency: cur }).format(cents / 100); }
    catch { return (cents / 100).toFixed(2) + ' ' + cur; }
}

// ---------- Dates (simpan ISO 8601, papar ikut gaya template) ----------
const nowISO = () => new Date().toISOString();
function formatDateTime(iso) {
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    try { return new Intl.DateTimeFormat(getRegion().locale, { dateStyle: 'medium', timeStyle: 'short' }).format(d); }
    catch { return d.toISOString(); }
}
function formatDateOnly(iso) {
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    try { return new Intl.DateTimeFormat(getRegion().locale, { dateStyle: 'medium' }).format(d); }
    catch { return d.toISOString().slice(0, 10); }
}
function formatDateMaybank(iso) {
    // cth template: 23 Dec 2014 13:20:29
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())} ${M[d.getMonth()]} ${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}
function formatDateJP(iso) {
    // cth template: 2025年08月26日（火）
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    try { return new Intl.DateTimeFormat('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' }).format(d); }
    catch { return d.toISOString().slice(0, 10); }
}
function formatDateUS(iso) {
    // cth template: 29th May 2020
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    const M = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const day = d.getDate();
    const suf = (day === 1 || day === 21 || day === 31) ? 'st' : (day === 2 || day === 22) ? 'nd' : (day === 3 || day === 23) ? 'rd' : 'th';
    return `${day}${suf} ${M[d.getMonth()]} ${d.getFullYear()}`;
}
function formatDateEU(iso) {
    // cth template: Friday, May 02 2025 12:37 AM
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    try {
        const date = new Intl.DateTimeFormat('en-GB', { weekday: 'long', month: 'long', day: '2-digit', year: 'numeric' }).format(d);
        let h = d.getHours(); const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
        return `${date} ${String(h).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')} ${ap}`;
    } catch { return d.toISOString(); }
}
function formatCopyEU(iso) {
    // cth template: 05/02/2025 00:37:06
    const d = new Date(iso);
    if (isNaN(d)) return String(iso);
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getMonth() + 1)}/${p(d.getDate())}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

// ---------- Unique bank references ----------
function randDigits(n) {
    let s = '';
    for (let i = 0; i < n; i++) s += Math.floor(Math.random() * 10);
    return s;
}
function ensureSeed(t) {
    if (!t.refSeed) t.refSeed = randDigits(13);
    return t.refSeed;
}
// Kod rujukan unik ikut bank — cth Maybank: MYBK-1234121324124
function bankRef(t, regionKey) {
    const key = regionKey || activeRegionKey;
    const seed = ensureSeed(t);
    if (key === 'maybank') return 'MYBK-' + seed;
    if (key === 'japan') return 'JPBK-' + seed.slice(0, 10);
    if (key === 'us') return 'BOFA-' + seed.slice(0, 10);
    if (key === 'eu') {
        // Alfanumerik seperti template Eurobank (cth: 19SZghhVXn2oZ3cNlzoW)
        let num = 0n;
        try { num = BigInt(seed); } catch { num = 0n; }
        const chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let s = '';
        for (let i = 0; i < 12; i++) { s = chars[Number(num % 36n)] + s; num = num / 36n; }
        return 'EURO-' + s;
    }
    return 'SPAY-' + seed.slice(0, 10);
}
// Nombor pendaftaran Jepun gaya template: T + 13 digit (cth: T5010001238914)
const japanToroku = (t) => 'T' + ensureSeed(t);
// Nombor akaun demo diterbitkan daripada seed supaya stabil setiap resit
const demoFromAcct = (t) => '1571' + ensureSeed(t).slice(0, 8);
const demoToAcct = (t) => t.account ? String(t.account) : '8007' + ensureSeed(t).slice(0, 6);
// IBAN Greece demo 27 aksara (cth: GR3901727501005751111262610)
const demoIBAN = (t) => 'GR' + ensureSeed(t).slice(0, 2) + '0172019' + ensureSeed(t) + ensureSeed(t).slice(0, 3);

function txnTitle(t) {
    if (t.type === 'send' && t.method === 'qr') return `QR paid to ${t.recipient || '—'}`;
    if (t.type === 'send') return `Sent to ${t.recipient || '—'}`;
    if (t.type === 'request' && String(t.note || '').startsWith('Split "')) return `Split bill — ${t.recipient || '—'}`;
    if (t.type === 'request') return `Requested from ${t.recipient || '—'}`;
    if (t.type === 'topup') return `Top up via ${t.method || 'card'}`;
    if (t.type === 'bill') return `${t.billType || 'Bill'} payment`;
    return t.type;
}

// ---------- Receipts (mengikut assets/template/* + bahasa tempatan) ----------
// Template resit mengikut pilihan region yang aktif — tukar pemilih template
// (Maybank ↔ Jepun ↔ US ↔ EU) dan resit bertukar serta-merta.
function receiptHTML(t) {
    ensureSeed(t);
    const key = activeRegionKey;
    if (key === 'maybank') return receiptMaybank(t);
    if (key === 'japan') return receiptJapan(t);
    if (key === 'us') return receiptUS(t);
    if (key === 'eu') return receiptEU(t);
    return receiptGeneric(t);
}
// Maybank2u — dwibahasa Melayu/English, gaya maybank-template.jpeg
function receiptMaybank(t) {
    const typeLabel = t.type === 'bill' ? 'Pembayaran Bil / Bill Payment'
        : t.type === 'topup' ? 'Tambah Nilai / Top Up'
        : t.type === 'request' ? 'Permintaan Dana / Fund Request' : 'Pindahan Dana Antara Bank / Funds Transfer Interbank';
    const beneficiary = t.recipient || t.billType || '—';
    const rows = [
        ['Status', '<span class="my2u-ok">Successful / Berjaya</span>'],
        ['Reference number /<br>Nombor Rujukan', bankRef(t, 'maybank')],
        ['Transaction date /<br>Tarikh Transaksi', formatDateMaybank(t.dateISO)],
        ['Amount / Jumlah', formatCents(t.amountCents, 'MYR', 'en-MY')],
        ['From Account /<br>Dari Akaun', demoFromAcct(t) + ' WSA'],
        ['Transaction Type /<br>Jenis Transaksi', typeLabel],
        ['Beneficiary Name /<br>Nama Penerima', beneficiary],
        ['Receiving Bank /<br>Bank Penerima', t.type === 'bill' ? 'JomPAY' : 'CIMB BANK BERHAD'],
        ['Beneficiary Account Number /<br>Nombor Akaun Penerima', demoToAcct(t)],
    ];
    if (t.note) rows.push(['Note / Nota', t.note]);
    return `
    <div class="rcpt my2u">
        <div class="my2u-brand">maybank2u.com</div>
        <div class="my2u-title">Open Interbank / Pindahan Antara Bank</div>
        <table class="my2u-table"><tbody>
        ${rows.map(([k, v]) => `<tr><th>${k}</th><td>${k === 'Status' ? v : sanitize(String(v))}</td></tr>`).join('')}
        </tbody></table>
        <p class="my2u-note">Note: This receipt is computer generated and no signature is required.<br>Nota: Resit ini dijana oleh komputer dan tandatangan tidak diperlukan.</p>
        <div class="my2u-badge">m2u</div>
    </div>`;
}
// Jepun 領収書 — Bahasa Jepun penuh, gaya Japan-template.png
function receiptJapan(t) {
    const yen = Math.round(t.amountCents / 100);
    const base = Math.round(yen / 1.08); // 8%対象税（税抜）
    const tax = yen - base;              // 消費税等 8%
    const fmtY = (n) => '¥' + n.toLocaleString('ja-JP');
    const who = t.recipient || t.billType || 'お客様';
    const matter = t.note || (t.type === 'bill' ? 'お支払い代' : t.type === 'topup' ? 'チャージ代' : 'ご送金');
    return `
    <div class="rcpt jprcpt">
        <div class="jp-head"><span class="jp-shop">セキュアペイ</span><span class="jp-logo">収</span></div>
        <div class="jp-title">領収書</div>
        <div class="jp-row"><span>会計日</span><span>${sanitize(formatDateJP(t.dateISO))}</span></div>
        <div class="jp-row"><span>伝票</span><span>No.${sanitize(bankRef(t, 'japan'))}</span></div>
        <div class="jp-row"><span>登録番号</span><span>${sanitize(japanToroku(t))}</span></div>
        <div class="jp-name">${sanitize(who)}　様</div>
        <div class="jp-amount">${sanitize(fmtY(yen))}-</div>
        <div class="jp-tax"><span>(8%対象税（税抜)</span><span>${sanitize(fmtY(base))})</span></div>
        <div class="jp-tax"><span>(　消費税等　8%</span><span>${sanitize(fmtY(tax))})</span></div>
        <div class="jp-tax"><span>支払</span><span>${sanitize(fmtY(yen))}</span></div>
        <p class="jp-cert">上記金額正に領収いたしました。</p>
        <p class="jp-matter">但し ${sanitize(matter)} として</p>
        <div class="jp-stamp">収入印紙</div>
        <div class="jp-company">セキュアペイ株式会社 東京店</div>
        <div class="jp-addr">東京都渋谷区南5条東1丁目3-5-1<br>TEL：011-444-5566</div>
        <div class="jp-staff">担当者</div>
        <p class="jp-keep">印刷面を内側に折って保管願います</p>
    </div>`;
}
// Bank of America — English (US), gaya us-template.webp
function receiptUS(t) {
    const dollars = t.amountCents / 100;
    const sub = dollars, tax = Math.round(dollars * 2) / 100, total = sub + tax;
    const money = (n) => '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const who = t.recipient || t.billType || 'Valued Customer';
    return `
    <div class="rcpt bofa">
        <div class="bofa-brand">Bank of America.</div>
        <div class="bofa-flag"><span></span><span></span><span></span></div>
        <p class="bofa-tag">Easing Financial for You</p>
        <p class="bofa-date"><strong>Date : ${sanitize(formatDateUS(t.dateISO))}</strong></p>
        <p class="bofa-sec">Transfers Detail</p>
        <p class="bofa-p">In payment of ${sanitize(txnTitle(t))} on behalf of ${sanitize(who)}. Reference ${sanitize(bankRef(t, 'us'))}.</p>
        <p class="bofa-sec">Invoice Detail</p>
        <p class="bofa-p"><strong>To, ${sanitize(who)}</strong><br>SecurePay Wallet Services<br>New York, NY 10016<br>+1 212-223-2900<br>support@securepay.example<br>www.securepay.example</p>
        <table class="bofa-table">
            <thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody>
            <tr><td>${sanitize(txnTitle(t))}${t.note ? ' — ' + sanitize(t.note) : ''}</td><td>${sanitize(money(sub))}</td></tr>
            <tr><td class="r">Subtotal</td><td>${sanitize(money(sub))}</td></tr>
            <tr><td class="r">Includes Tax (2.00%)</td><td>${sanitize(money(tax))}</td></tr>
            <tr><td class="r"><strong>Total</strong></td><td><strong>${sanitize(money(total))}</strong></td></tr>
            <tr><td class="r">Paid</td><td>${sanitize(money(total))}</td></tr>
            <tr><td class="r"><strong>Balance Due</strong></td><td><strong>$0.00</strong></td></tr>
            </tbody></table>
        <p class="bofa-sec">Important Notes</p>
        <p class="bofa-notes">This receipt is computer generated for demonstration purposes. Please verify the transaction on your official bank statement; contact us through our website and email for any issue.</p>
        <div class="bofa-foot">SecurePay Demo, New York, USA · support@securepay.example · www.securepay.example</div>
    </div>`;
}
// Eurobank — English (EU banking), gaya euro-template.webp
function receiptEU(t) {
    return `
    <div class="rcpt euro">
        <div class="euro-head"><span class="euro-logo">≋ EUROBANK</span>
            <span class="euro-bank">EUROBANK S.A.<br>8, Othonos Str., GR 105 57 Athens<br><strong>GCR.</strong>&nbsp;&nbsp;154558160000</span></div>
        <div class="euro-title">Transaction Receipt</div>
        <div class="euro-sub">Transfer to an account of Other Bank in Greece</div>
        <div class="euro-sec">Credit Information</div>
        <div class="euro-grid"><span>ACCOUNT NUMBER</span><strong>${sanitize(demoIBAN(t))}</strong></div>
        <div class="euro-grid"><span>NAME</span><strong>${sanitize((t.recipient || t.billType || 'Valued Customer').toUpperCase())}</strong></div>
        <div class="euro-sec">Beneficiary Bank</div>
        <div class="euro-grid"><span>COUNTRY</span><strong>Greece</strong></div>
        <div class="euro-grid"><span>BIC</span><strong>PIRBGRAAXXX</strong></div>
        <div class="euro-grid"><span>BANK NAME</span><strong>PIRAEUS BANK S.A.</strong></div>
        <div class="euro-sec">Debit Information</div>
        <div class="euro-grid"><span>AMOUNT</span><strong>EUR ${sanitize((t.amountCents / 100).toFixed(2))}</strong></div>
        <div class="euro-sec">Execution and commission/fee charging types</div>
        <div class="euro-grid"><span>FUND TRANSFER CREDIT VALEUR</span><strong>With instant credit</strong></div>
        <div class="euro-grid"><span>NOTIFICATION VIA SMS AT</span><strong>••••••••••${sanitize(ensureSeed(t).slice(-4))}</strong></div>
        <div class="euro-grid"><span>COMMISSION/FEE CHARGING TYPE</span><strong>All charges are borne by the sender (OUR)</strong></div>
        <div class="euro-grid"><span>THE TRANSACTION WILL BE EXECUTED</span><strong>Instantly</strong></div>
        <div class="euro-grid"><span>TRANSACTION EXECUTION DATE</span><strong>${sanitize(formatDateEU(t.dateISO))}</strong></div>
        <div class="euro-grid"><span>TOTAL FEES</span><strong>EUR 1.00</strong></div>
        <div class="euro-grid"><span>COMMISSIONS/FEES ANALYSIS</span><strong>EUR 0.50 Bank commission<br>EUR 0.50 Beneficiary's bank charges/intermediate bank charges</strong></div>
        <div class="euro-sec">Additional Information</div>
        <div class="euro-grid"><span>FUND TRANSFER SUBMISSION DATE/TIME</span><strong>${sanitize(formatDateEU(t.dateISO))}</strong></div>
        <div class="euro-reg">Your transaction has been registered</div>
        <div class="euro-grid"><span>TRANSACTION NUMBER</span><strong>${sanitize(bankRef(t, 'eu'))}</strong></div>
        <div class="euro-foot">For further information call EuroPhone Banking 24/7 on +302109555000 or send an email to ebanking@eurobank.gr.</div>
        <div class="euro-copy"><span>Page 1/2</span><span>Date and time of copy: ${sanitize(formatCopyEU(t.dateISO))}</span></div>
    </div>`;
}
function receiptGeneric(t) {
    const r = getRegion();
    const rows = [
        ['Transaction ID', bankRef(t)],
        ['Date / Time', formatDateTime(t.dateISO)],
        ['Status', t.status || 'SUCCESS'],
        ['Type', txnTitle(t)],
        t.recipient ? ['Counterparty', t.recipient] : null,
        t.method ? ['Method', t.method] : null,
        t.billType ? ['Bill type', t.billType] : null,
        t.account ? ['Account', t.account] : null,
        t.note ? ['Note', t.note] : null,
        ['Amount', formatCents(t.amountCents, t.currency)],
        ['Currency', t.currency]
    ].filter(Boolean);
    return `
    <div class="rcpt rcpt-${r.brand}">
        <div class="rcpt-head"><div class="rcpt-bank">${sanitize(r.bank)}</div></div>
        <div class="rcpt-title">PAYMENT RECEIPT</div>
        <div class="rcpt-amount">${sanitize(formatCents(t.amountCents, t.currency))}</div>
        <table class="rcpt-table"><tbody>
        ${rows.map(([k, v]) => `<tr><th>${sanitize(k)}</th><td>${sanitize(String(v))}</td></tr>`).join('')}
        </tbody></table>
        <div class="rcpt-foot">Reference: ${sanitize(bankRef(t))} · ${sanitize(formatDateTime(t.dateISO))}<br>Generated by SecurePay demo — verify with your bank statement.</div>
    </div>`;
}
