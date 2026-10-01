<?php

declare(strict_types=1);

namespace Engazan\PayBySquare;

use Engazan\PayBySquare\Exception\PayBySquareException;
use Engazan\PayBySquare\Exception\ValidationException;

/**
 * OOP generátor PAY by square QR kódov pre slovenské platby.
 *
 * Algoritmus je inšpirovaný implementáciou Jána Fečíka:
 * https://jan.fecik.sk/blog/qr-generator-platieb-pay-by-square-v-php/
 *
 * Použitie:
 *   $qr = (new Generator())
 *       ->setIban('SK3112000000198742637541')
 *       ->setSwift('TATRSKBX')
 *       ->setAmount(49.99)
 *       ->setRecipient('Jozko Mrkvicka')
 *       ->setVariableSymbol('20240001')
 *       ->setNote('Faktura 2024');
 *
 *   $qr->saveToFile('/tmp/platba.png');
 *   echo $qr->getDataUri();
 *   echo $qr->getImgTag();
 */
class Generator
{
    private string $iban = '';
    private string $swift = '';
    private float|string|null $amount = null;
    private string $currency = 'EUR';
    private string $recipient = '';
    private string $recipientAddressLine1 = '';
    private string $recipientAddressLine2 = '';
    private string $variableSymbol = '';
    private string $specificSymbol = '';
    private string $constantSymbol = '';
    private string $paymentReference = '';
    private string $note = '';
    private ?\DateTimeInterface $dueDate = null;
    private string $invoiceId = '';
    private array $additionalAccounts = [];
    private array $additionalPayments = [];
    private bool $paymentOrderEnabled = true;
    private ?array $standingOrder = null;
    private ?array $directDebit = null;
    private string $xzPath = '';
    private QrStyle $style = QrStyle::Default;

    // ─── Setters (fluent interface) ──────────────────────────────────────────

    public function setIban(string $iban): static
    {
        $this->iban = strtoupper(str_replace(' ', '', $iban));
        return $this;
    }

    public function setSwift(string $swift): static
    {
        $this->swift = strtoupper(trim($swift));
        return $this;
    }

    public function setAmount(float|string|null $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper(trim($currency));
        return $this;
    }

    public function setRecipient(string $recipient): static
    {
        $this->recipient = trim($recipient);
        return $this;
    }

    public function setRecipientAddressLine1(string $address): static
    {
        $this->recipientAddressLine1 = trim($address);
        return $this;
    }

    public function setRecipientAddressLine2(string $address): static
    {
        $this->recipientAddressLine2 = trim($address);
        return $this;
    }

    public function setVariableSymbol(string $vs): static
    {
        $this->variableSymbol = $vs;
        return $this;
    }

    public function setSpecificSymbol(string $ss): static
    {
        $this->specificSymbol = $ss;
        return $this;
    }

    public function setConstantSymbol(string $cs): static
    {
        $this->constantSymbol = $cs;
        return $this;
    }

    public function setPaymentReference(string $reference): static
    {
        $this->paymentReference = trim($reference);
        return $this;
    }

    public function setNote(string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function setDueDate(\DateTimeInterface $date): static
    {
        $this->dueDate = clone $date;
        return $this;
    }

    public function setInvoiceId(string $invoiceId): static
    {
        $this->invoiceId = trim($invoiceId);
        return $this;
    }

    public function addBankAccount(string $iban, string $bic = ''): static
    {
        $this->additionalAccounts[] = [
            strtoupper(str_replace(' ', '', $iban)),
            strtoupper(trim($bic)),
        ];
        return $this;
    }

    public function addPayment(self $payment): static
    {
        if ($payment === $this || $payment->additionalPayments !== [] || $payment->invoiceId !== '') {
            throw new ValidationException('Pridaná platba nesmie obsahovať ďalšie platby ani InvoiceID');
        }
        $this->additionalPayments[] = clone $payment;
        return $this;
    }

    public function setPaymentOrderEnabled(bool $enabled): static
    {
        $this->paymentOrderEnabled = $enabled;
        return $this;
    }

    /** $months contains month numbers 1–12. */
    public function setStandingOrder(string $periodicity, ?int $day = null, array $months = [], ?\DateTimeInterface $lastDate = null): static
    {
        $lastDate = $lastDate === null ? null : clone $lastDate;
        $this->standingOrder = compact('periodicity', 'day', 'months', 'lastDate');
        return $this;
    }

    /**
     * $identification accepts variableSymbol/specificSymbol, reference, or
     * mandateId/creditorId/contractId (one alternative only).
     */
    public function setDirectDebit(
        string $scheme,
        string $type,
        array $identification = [],
        float|string|null $maxAmount = null,
        ?\DateTimeInterface $validTillDate = null,
    ): static {
        $validTillDate = $validTillDate === null ? null : clone $validTillDate;
        $this->directDebit = compact('scheme', 'type', 'identification', 'maxAmount', 'validTillDate');
        return $this;
    }

    /**
     * Cesta k xz binárke – nastaviť len ak auto-detekcia zlyháva.
     * Predvolene sa hľadá v: /usr/bin/xz, /usr/local/bin/xz, /opt/homebrew/bin/xz
     */
    public function setXzPath(string $path): static
    {
        $this->xzPath = $path;
        return $this;
    }

    /**
     * Nastav vizuálny štýl QR kódu.
     *
     *   QrStyle::Default     – čistý QR, biele pozadie
     *   QrStyle::Transparent – QR s priehľadným pozadím
     *   QrStyle::PayBySquare – tmavý rám + PAY by square footer
     */
    public function setStyle(QrStyle $style): static
    {
        $this->style = $style;
        return $this;
    }

    // ─── Getters ─────────────────────────────────────────────────────────────

    public function getIban(): string
    {
        return $this->iban;
    }

    public function getSwift(): string
    {
        return $this->swift;
    }

    public function getAmount(): float|string|null
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getRecipientAddressLine1(): string
    {
        return $this->recipientAddressLine1;
    }

    public function getRecipientAddressLine2(): string
    {
        return $this->recipientAddressLine2;
    }

    public function getVariableSymbol(): string
    {
        return $this->variableSymbol;
    }

    public function getSpecificSymbol(): string
    {
        return $this->specificSymbol;
    }

    public function getConstantSymbol(): string
    {
        return $this->constantSymbol;
    }

    public function getPaymentReference(): string
    {
        return $this->paymentReference;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    // ─── Generovanie ─────────────────────────────────────────────────────────

    /**
     * Vráti zakódovaný Pay by square reťazec (obsah QR kódu).
     *
     * @throws ValidationException ak chýbajú povinné polia
     * @throws PayBySquareException ak zlyhá komprimácia
     */
    public function generateString(): string
    {
        $payments = array_merge([$this], $this->additionalPayments);
        $fields = [PaymentFormat::text($this->invoiceId, 10, 'InvoiceID'), (string) count($payments)];
        foreach ($payments as $payment) {
            array_push($fields, ...$payment->paymentFields());
        }
        // Verzia 1.1.0 ukladá údaje príjemcov až za všetkými platbami.
        foreach ($payments as $payment) {
            array_push($fields, ...$payment->beneficiaryFields());
        }
        $data = implode("\t", $fields);

        $characterCount = preg_match_all('/./us', $data);
        if ($characterCount === false) {
            throw new ValidationException('Dátová sekvencia musí byť platný UTF-8 text');
        }
        if ($characterCount > 550) {
            throw new ValidationException('Dátová sekvencia QR môže mať maximálne 550 znakov');
        }

        // CRC32b checksum – strrev(hash("crc32b", $data, TRUE))
        $crc = strrev(hash('crc32b', $data, true));

        $payload = $crc.$data;

        // LZMA1 kompresia
        $compressed = $this->lzmaCompress($payload);

        // Header: 2 nulové bajty + 2 bajty dĺžka pôvodného $payload (little-endian)
        $header = "\x00\x00".pack('v', strlen($payload));
        $withHeader = $header.$compressed;

        // Zakódovanie do Base32 (abeceda Pay by square)
        return $this->base32encode($withHeader);
    }

    /**
     * Vráti QR kód ako PNG data URI (data:image/png;base64,...).
     */
    public function getDataUri(int $size = 300): string
    {
        return 'data:image/png;base64,'.base64_encode($this->renderPng($size));
    }

    /**
     * Vráti HTML <img> tag s QR kódom.
     */
    public function getImgTag(int $size = 300, string $alt = 'PAY by square'): string
    {
        $uri = $this->getDataUri($size);
        return sprintf('<img src="%s" width="%d" height="%d" alt="%s">', $uri, $size, $size, htmlspecialchars($alt));
    }

    /**
     * Uloží QR kód do PNG súboru.
     */
    public function saveToFile(string $filePath, int $size = 300): void
    {
        file_put_contents($filePath, $this->renderPng($size));
    }

    /**
     * Vráti surové PNG bajty QR kódu.
     */
    public function getPngBytes(int $size = 300): string
    {
        return $this->renderPng($size);
    }

    // ─── Interné metódy ──────────────────────────────────────────────────────

    /**
     * Vráti PNG bajty QR kódu vo zvolenom štýle.
     */
    private function renderPng(int $size): string
    {
        $qrString = $this->generateString();
        return (new QrRenderer($qrString, $size, $this->style))->render();
    }

    private function lzmaCompress(string $data): string
    {
        $xzPath = $this->resolveXzPath();

        // Parametre presne podľa Pay by square špecifikácie
        $cmd = [$xzPath, '--format=raw', '--lzma1=lc=3,lp=0,pb=2,dict=128KiB', '-c', '-'];

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if ($process === false) {
            throw new PayBySquareException('Nepodarilo sa spustiť xz proces.');
        }

        fwrite($pipes[0], $data);
        fclose($pipes[0]);

        $compressed = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $compressed === false || $compressed === '') {
            throw new PayBySquareException('LZMA kompresia zlyhala: '.trim((string) $error));
        }

        return $compressed;
    }

    private function resolveXzPath(): string
    {
        // Manuálne nastavená cesta má prednosť
        if ($this->xzPath !== '' && is_executable($this->xzPath)) {
            return $this->xzPath;
        }

        $candidates = [
            '/usr/bin/xz',
            '/usr/local/bin/xz',
            '/opt/homebrew/bin/xz',   // macOS Apple Silicon
            '/opt/homebrew/opt/xz/bin/xz',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new PayBySquareException(
            "xz binárka sa nenašla. Nainštalujte xz-utils (Linux: apt install xz-utils, Mac: brew install xz) ".
            "alebo nastavte cestu manuálne cez setXzPath()."
        );
    }

    /**
     * Base32 enkódovanie podľa Pay by square špecifikácie (vlastná abeceda).
     */
    /**
     * Base32 enkódovanie podľa Pay by square špecifikácie.
     * Abeceda: 0123456789ABCDEFGHIJKLMNOPQRSTUV (nie RFC 4648!)
     */
    private function base32encode(string $data): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUV';

        // Konvertujeme na hex string, potom na binárny reťazec po 4 bitoch
        $hex = bin2hex($data);
        $bits = '';
        for ($i = 0; $i < strlen($hex); $i++) {
            $bits .= str_pad(base_convert($hex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
        }

        // Doplníme nulami na násobok 5
        $len = strlen($bits);
        $rem = $len % 5;
        if ($rem > 0) {
            $bits .= str_repeat('0', 5 - $rem);
            $len += 5 - $rem;
        }

        // Každých 5 bitov = 1 znak abecedy
        $output = str_repeat('_', $len / 5);
        for ($i = 0; $i < $len / 5; $i++) {
            $output[$i] = $alphabet[bindec(substr($bits, $i * 5, 5))];
        }

        return $output;
    }

    /** @return list<string> */
    private function paymentFields(): array
    {
        $options = (int) $this->paymentOrderEnabled
            + ($this->standingOrder !== null ? 2 : 0)
            + ($this->directDebit !== null ? 4 : 0);
        if ($options === 0) {
            throw new ValidationException('Platba musí mať aspoň jednu možnosť platby');
        }
        if ($this->paymentReference !== '' && ($this->variableSymbol !== '' || $this->constantSymbol !== '' || $this->specificSymbol !== '')) {
            throw new ValidationException('Referencia platiteľa sa nemôže kombinovať s platobnými symbolmi');
        }

        $accounts = array_merge([[$this->iban, $this->swift]], $this->additionalAccounts);
        $fields = [
            (string) $options,
            PaymentFormat::decimal($this->amount, 'Suma'),
            PaymentFormat::currency($this->currency),
            PaymentFormat::date($this->dueDate, 'Dátum splatnosti'),
            PaymentFormat::symbol($this->variableSymbol, 10, 'Variabilný symbol'),
            PaymentFormat::symbol($this->constantSymbol, 4, 'Konštantný symbol'),
            PaymentFormat::symbol($this->specificSymbol, 10, 'Špecifický symbol'),
            PaymentFormat::text($this->paymentReference, 35, 'Referencia platiteľa'),
            PaymentFormat::text($this->note, 140, 'Poznámka'),
            (string) count($accounts),
        ];
        foreach ($accounts as [$iban, $bic]) {
            $fields[] = PaymentFormat::iban($iban);
            $fields[] = PaymentFormat::bic($bic);
        }

        $fields[] = $this->standingOrder === null ? '0' : '1';
        if ($this->standingOrder !== null) {
            array_push($fields, ...$this->standingOrderFields());
        }
        $fields[] = $this->directDebit === null ? '0' : '1';
        if ($this->directDebit !== null) {
            array_push($fields, ...$this->directDebitFields());
        }

        return $fields;
    }

    /** @return list<string> */
    private function beneficiaryFields(): array
    {
        return [
            PaymentFormat::text($this->recipient, 70, 'Príjemca'),
            PaymentFormat::text($this->recipientAddressLine1, 70, 'Adresa príjemcu, riadok 1'),
            PaymentFormat::text($this->recipientAddressLine2, 70, 'Adresa príjemcu, riadok 2'),
        ];
    }

    /** @return list<string> */
    private function standingOrderFields(): array
    {
        $order = $this->standingOrder;
        $periodicities = [
            'Daily' => 'd', 'Weekly' => 'w', 'Biweekly' => 'b', 'Monthly' => 'm',
            'Bimonthly' => 'B', 'Quarterly' => 'q', 'Semiannually' => 's', 'Annually' => 'a',
        ];
        if (!isset($periodicities[$order['periodicity']])) {
            throw new ValidationException('Neplatná periodicita trvalého príkazu');
        }
        $day = $order['day'];
        $weekly = in_array($order['periodicity'], ['Weekly', 'Biweekly'], true);
        if ($day !== null && ($day < 1 || $day > ($weekly ? 7 : 31))) {
            throw new ValidationException('Neplatný deň trvalého príkazu');
        }
        $monthMask = 0;
        foreach ($order['months'] as $month) {
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new ValidationException('Mesiace trvalého príkazu musia byť čísla 1 až 12');
            }
            $monthMask |= 1 << ($month - 1);
        }

        return [
            $day === null ? '' : (string) $day,
            $monthMask === 0 ? '' : (string) $monthMask,
            $periodicities[$order['periodicity']],
            PaymentFormat::date($order['lastDate'], 'Posledný dátum trvalého príkazu'),
        ];
    }

    /** @return list<string> */
    private function directDebitFields(): array
    {
        $debit = $this->directDebit;
        $scheme = ['other' => '0', 'SEPA' => '1'][$debit['scheme']] ?? null;
        $type = ['one-off' => '0', 'recurrent' => '1'][$debit['type']] ?? null;
        if ($scheme === null || $type === null) {
            throw new ValidationException('Neplatná schéma alebo typ inkasa');
        }
        $identification = $debit['identification'];
        $allowed = ['variableSymbol', 'specificSymbol', 'reference', 'mandateId', 'creditorId', 'contractId'];
        if (array_diff(array_keys($identification), $allowed) !== []) {
            throw new ValidationException('Neznáme identifikačné pole inkasa');
        }
        foreach ($identification as $value) {
            if (!is_string($value)) {
                throw new ValidationException('Identifikačné polia inkasa musia byť textové');
            }
        }
        $vs = PaymentFormat::symbol($identification['variableSymbol'] ?? '', 10, 'Variabilný symbol inkasa');
        $ss = PaymentFormat::symbol($identification['specificSymbol'] ?? '', 10, 'Špecifický symbol inkasa');
        $reference = PaymentFormat::text($identification['reference'] ?? '', 35, 'Referencia inkasa');
        $mandate = PaymentFormat::text($identification['mandateId'] ?? '', 35, 'MandateID');
        $creditor = PaymentFormat::text($identification['creditorId'] ?? '', 35, 'CreditorID');
        $contract = PaymentFormat::text($identification['contractId'] ?? '', 35, 'ContractID');
        $variants = (int) ($vs !== '' || $ss !== '') + (int) ($reference !== '') + (int) ($mandate !== '' || $creditor !== '' || $contract !== '');
        if ($variants > 1 || ($mandate !== '') !== ($creditor !== '') || ($contract !== '' && $mandate === '')) {
            throw new ValidationException('Identifikácia inkasa musí použiť práve jednu úplnú alternatívu');
        }
        if ($scheme === '1' && ($mandate === '' || $creditor === '')) {
            throw new ValidationException('SEPA inkaso vyžaduje MandateID a CreditorID');
        }
        if ($scheme === '0' && $mandate !== '') {
            throw new ValidationException('MandateID a CreditorID patria len do SEPA inkasa');
        }

        return [
            $scheme, $type, $vs, $ss, $reference, $mandate, $creditor, $contract,
            PaymentFormat::decimal($debit['maxAmount'], 'Maximálna suma inkasa'),
            PaymentFormat::date($debit['validTillDate'], 'Platnosť inkasa'),
        ];
    }
}
