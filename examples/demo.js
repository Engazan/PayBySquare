'use strict';

const $ = (id) => document.getElementById(id);
const field = (scope, name) => scope.querySelector(`[data-field="${name}"]`);
const value = (scope, name) => field(scope, name).value.trim();
const checked = (scope, name) => field(scope, name).checked;

let images = {};
let activeStyle = 'pay_by_square';
let requestId = 0;
let debounceTimer = null;

function addAccount(payment, initial = false) {
    const row = document.createElement('div');
    row.className = 'account-row';
    row.innerHTML = `
        <div class="field"><label>IBAN<input data-field="iban" class="mono" maxlength="42" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="SK00 0000 0000 0000 0000 0000"></label></div>
        <div class="field"><label>SWIFT / BIC<input data-field="bic" class="mono" maxlength="11" placeholder="TATRASBX"></label></div>
        <button type="button" class="small-btn danger remove-account">Odstrániť</button>`;
    if (initial) {
        field(row, 'iban').value = 'SK18 1100 0000 0029 3511 6681';
        field(row, 'bic').value = 'TATRASBX';
    }
    payment.querySelector('.accounts').append(row);
    renumber();
}

function addPayment(initial = false) {
    const card = $('paymentTemplate').content.firstElementChild.cloneNode(true);
    for (let month = 1; month <= 12; month++) {
        const label = document.createElement('label');
        label.className = 'check';
        label.innerHTML = `<input type="checkbox" data-month="${month}"> ${month}`;
        card.querySelector('.months').append(label);
    }
    $('payments').append(card);
    addAccount(card, initial);
    renumber();
    return card;
}

function renumber() {
    document.querySelectorAll('.payment-card').forEach((card, index) => {
        card.querySelector('h2').textContent = `Platba ${index + 1}`;
        card.querySelector('.remove-payment').hidden = index === 0;
        card.querySelectorAll('.account-row').forEach((row, accountIndex) => {
            row.querySelector('.remove-account').hidden = accountIndex === 0;
        });
    });
}

function updatePanels(card) {
    card.querySelector('.standing-fields').hidden = !checked(card, 'standingEnabled');
    card.querySelector('.debit-fields').hidden = !checked(card, 'debitEnabled');
    const mode = value(card, 'debitIdentification');
    card.querySelectorAll('.debit-identification').forEach((section) => {
        section.hidden = section.dataset.mode !== mode;
    });
    field(card, 'standingDay').max = ['Weekly', 'Biweekly'].includes(value(card, 'periodicity')) ? '7' : '31';
}

function paymentData(card) {
    const accounts = [...card.querySelectorAll('.account-row')].map((row) => ({
        iban: value(row, 'iban').replace(/\s/g, ''), bic: value(row, 'bic'),
    }));
    const payment = {
        accounts,
        recipient: value(card, 'recipient'), address1: value(card, 'address1'), address2: value(card, 'address2'),
        amount: value(card, 'amount'), currency: value(card, 'currency').toUpperCase(), dueDate: value(card, 'dueDate'),
        vs: value(card, 'vs'), cs: value(card, 'cs'), ss: value(card, 'ss'),
        reference: value(card, 'reference'), note: value(card, 'note'),
        paymentOrder: checked(card, 'paymentOrder'), standing: null, debit: null,
    };
    if (checked(card, 'standingEnabled')) {
        const day = value(card, 'standingDay');
        payment.standing = {
            periodicity: value(card, 'periodicity'), day: day === '' ? null : Number(day),
            months: [...card.querySelectorAll('[data-month]:checked')].map((input) => Number(input.dataset.month)),
            lastDate: value(card, 'lastDate'),
        };
    }
    if (checked(card, 'debitEnabled')) {
        const mode = value(card, 'debitIdentification');
        const identification = {};
        if (mode === 'symbols') {
            identification.variableSymbol = value(card, 'debitVs');
            identification.specificSymbol = value(card, 'debitSs');
        } else if (mode === 'reference') {
            identification.reference = value(card, 'debitReference');
        } else if (mode === 'mandate') {
            identification.mandateId = value(card, 'mandateId');
            identification.creditorId = value(card, 'creditorId');
            identification.contractId = value(card, 'contractId');
        }
        payment.debit = {
            scheme: value(card, 'debitScheme'), type: value(card, 'debitType'), identification,
            maxAmount: value(card, 'maxAmount'), validTillDate: value(card, 'validTillDate'),
        };
    }
    return payment;
}

function formData() {
    const requestedSize = Number($('size').value);
    const size = Number.isFinite(requestedSize) && requestedSize > 0
        ? Math.max(100, Math.min(1000, Math.trunc(requestedSize))) : 300;
    return {
        invoiceId: $('invoiceId').value.trim(), size,
        payments: [...document.querySelectorAll('.payment-card')].map(paymentData),
    };
}

function invalidatePreview() {
    ++requestId;
    images = {};
    $('qrMain').hidden = true;
    $('emptyState').hidden = false;
    $('download').removeAttribute('href');
    $('download').setAttribute('aria-disabled', 'true');
    document.querySelectorAll('.style-opt img').forEach((img) => img.removeAttribute('src'));
}

async function generate() {
    invalidatePreview();
    const id = ++requestId;
    $('stage').classList.add('loading');
    try {
        const response = await fetch('payment-preview.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData()),
        });
        const body = await response.text();
        if (!response.ok) throw new Error(body || `HTTP ${response.status}`);
        let result;
        try { result = JSON.parse(body); } catch { throw new Error(body || 'Neplatná odpoveď zo servera'); }
        if (id !== requestId) return;
        images = result;
        document.querySelectorAll('.style-opt').forEach((button) => {
            button.querySelector('img').src = result[button.dataset.style];
        });
        $('errorMsg').className = 'error-msg';
        showStyle(activeStyle);
    } catch (error) {
        if (id !== requestId) return;
        $('errorMsg').textContent = '⚠  ' + error.message;
        $('errorMsg').classList.add('visible');
    } finally {
        if (id === requestId) $('stage').classList.remove('loading');
    }
}

function showStyle(style) {
    activeStyle = style;
    document.querySelectorAll('.style-opt').forEach((button) => button.classList.toggle('active', button.dataset.style === style));
    $('stage').classList.toggle('checker', style.includes('transparent'));
    if ($('tabCode').classList.contains('active')) updateCodeExample();
    if (!images[style]) return;
    $('emptyState').hidden = true;
    $('qrMain').hidden = false;
    $('qrMain').src = images[style];
    $('download').href = images[style];
    $('download').download = `qr-${style.replace(/_/g, '-')}.png`;
    $('download').removeAttribute('aria-disabled');
}

function updateSummary() {
    const payments = formData().payments;
    const first = payments[0];
    const amount = first.amount;
    const parts = amount.split('.');
    const whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const formatted = amount === '' ? '—' : whole + ',' + (parts[1] || '').padEnd(2, '0');
    $('sumAmount').textContent = formatted;
    const currency = document.createElement('span');
    currency.textContent = first.currency;
    $('sumAmount').append(currency);
    $('sumIban').textContent = first.accounts[0].iban.replace(/(.{4})/g, '$1 ').trim() || '—';
    $('sumRecipient').textContent = first.recipient || '—';
    $('sumSymbols').textContent = [first.vs, first.cs, first.ss].map((symbol) => symbol || '–').join(' / ');
    $('sumPayments').textContent = String(payments.length);
}

function switchTab(tab) {
    document.querySelectorAll('.tab').forEach((button) => button.classList.toggle('active', button.dataset.tab === tab));
    $('tabPreview').classList.toggle('active', tab === 'preview');
    $('tabCode').classList.toggle('active', tab === 'code');
    if (tab === 'code') updateCodeExample();
}

function phpString(text) {
    return `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function phpDate(text) {
    return text ? `new \\DateTimeImmutable(${phpString(text)})` : 'null';
}

function paymentCode(payment, variable, invoiceId = '') {
    const calls = [`setIban(${phpString(payment.accounts[0].iban)})`];
    if (payment.accounts[0].bic) calls.push(`setSwift(${phpString(payment.accounts[0].bic)})`);
    if (payment.amount) calls.push(`setAmount(${phpString(payment.amount)})`);
    if (payment.currency !== 'EUR') calls.push(`setCurrency(${phpString(payment.currency)})`);
    for (const [key, method] of [
        ['recipient', 'setRecipient'], ['address1', 'setRecipientAddressLine1'], ['address2', 'setRecipientAddressLine2'],
        ['vs', 'setVariableSymbol'], ['cs', 'setConstantSymbol'], ['ss', 'setSpecificSymbol'],
        ['reference', 'setPaymentReference'], ['note', 'setNote'],
    ]) {
        if (payment[key]) calls.push(`${method}(${phpString(payment[key])})`);
    }
    if (payment.dueDate) calls.push(`setDueDate(${phpDate(payment.dueDate)})`);
    if (invoiceId) calls.push(`setInvoiceId(${phpString(invoiceId)})`);
    for (const account of payment.accounts.slice(1)) {
        calls.push(`addBankAccount(${phpString(account.iban)}, ${phpString(account.bic)})`);
    }
    if (!payment.paymentOrder) calls.push('setPaymentOrderEnabled(false)');
    if (payment.standing) {
        const standing = payment.standing;
        calls.push(`setStandingOrder(${phpString(standing.periodicity)}, ${standing.day ?? 'null'}, [${standing.months.join(', ')}], ${phpDate(standing.lastDate)})`);
    }
    if (payment.debit) {
        const debit = payment.debit;
        const identification = Object.entries(debit.identification)
            .map(([key, item]) => `${phpString(key)} => ${phpString(item)}`).join(', ');
        const amount = debit.maxAmount ? phpString(debit.maxAmount) : 'null';
        calls.push(`setDirectDebit(${phpString(debit.scheme)}, ${phpString(debit.type)}, [${identification}], ${amount}, ${phpDate(debit.validTillDate)})`);
    }
    return [`${variable} = (new Generator())`, ...calls.map((call) => `    ->${call}`)].join('\n') + ';';
}

function updateCodeExample() {
    const data = formData();
    const styleNames = {
        pay_by_square: 'PayBySquare', pay_by_square_transparent: 'PayBySquareTransparent',
        default: 'Default', transparent: 'Transparent',
    };
    const lines = [
        'use Engazan\\PayBySquare\\Generator;',
        'use Engazan\\PayBySquare\\QrStyle;',
        '',
        paymentCode(data.payments[0], '$qr', data.invoiceId),
    ];
    data.payments.slice(1).forEach((payment, index) => {
        const variable = `$payment${index + 2}`;
        lines.push('', paymentCode(payment, variable), `$qr->addPayment(${variable});`);
    });
    lines.push(
        '', `$qr->setStyle(QrStyle::${styleNames[activeStyle]});`,
        '', `// Veľkosť ${data.size} px; cesta k xz sa prípadne nastaví cez setXzPath().`,
        `$qr->saveToFile(${phpString('/tmp/platba.png')}, ${data.size});`,
        `$uri = $qr->getDataUri(${data.size});`,
        `$png = $qr->getPngBytes(${data.size});`,
        `$html = $qr->getImgTag(${data.size});`,
        '$encoded = $qr->generateString();',
    );
    $('codeExample').textContent = lines.join('\n');
}

async function copyCode() {
    try {
        await navigator.clipboard.writeText($('codeExample').textContent);
        $('copyBtn').textContent = 'Skopírované ✓';
    } catch {
        $('copyBtn').textContent = 'Nepodarilo sa';
    }
    setTimeout(() => { $('copyBtn').textContent = 'Kopírovať'; }, 1600);
}

function toggleTheme() {
    const dark = document.documentElement.dataset.theme !== 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch {}
}

function maskIban(input) {
    const caret = input.selectionStart ?? input.value.length;
    const before = input.value.slice(0, caret).replace(/[^a-z0-9]/gi, '').length;
    const raw = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 34);
    input.value = raw.replace(/(.{4})(?=.)/g, '$1 ');
    const position = Math.min(before, raw.length);
    const newCaret = position + Math.floor((position - (position % 4 === 0 && position > 0 ? 1 : 0)) / 4);
    if (document.activeElement === input) input.setSelectionRange(newCaret, newCaret);
}

function scheduleGeneration() {
    invalidatePreview();
    updateSummary();
    if ($('tabCode').classList.contains('active')) updateCodeExample();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(generate, 400);
}

$('form').addEventListener('input', (event) => {
    if (event.target.matches('[data-field="iban"]')) maskIban(event.target);
    scheduleGeneration();
});
$('form').addEventListener('change', (event) => {
    const card = event.target.closest('.payment-card');
    if (card) updatePanels(card);
    scheduleGeneration();
});
$('form').addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button) return;
    if (button.id === 'addPayment') addPayment();
    else if (button.classList.contains('add-account')) addAccount(button.closest('.payment-card'));
    else if (button.classList.contains('remove-payment')) button.closest('.payment-card').remove();
    else if (button.classList.contains('remove-account')) button.closest('.account-row').remove();
    else return;
    renumber();
    scheduleGeneration();
});
document.querySelectorAll('.style-opt').forEach((button) => {
    button.addEventListener('click', () => showStyle(button.dataset.style));
});

addPayment(true);
updateSummary();
generate();
