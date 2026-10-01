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
    private ?float $amount = null;
    private string $currency = 'EUR';
    private string $recipient = '';
    private string $recipientAddressLine1 = '';
    private string $recipientAddressLine2 = '';
    private string $variableSymbol = '';
    private string $specificSymbol = '';
    private string $constantSymbol = '';
    private string $paymentReference = '';
    private string $note = '';
    private ?string $dueDate = null;
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

    public function setAmount(?float $amount): static
    {
        $this->amount = $amount === null ? null : round($amount, 2);
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
        $this->dueDate = $date->format('Ymd');
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

    public function getAmount(): ?float
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
        $this->validate();

        // Vnútorná časť platobného príkazu (podľa Pay by square špecifikácie)
        $fields = [
            '1',                        // PaymentOptions: jednorazový platobný príkaz
            $this->amount === null ? '' : number_format($this->amount, 2, '.', ''),
            $this->currency,
            $this->dueDate,
            $this->variableSymbol,
            $this->constantSymbol,
            $this->specificSymbol,
            $this->paymentReference,    // referencia platiteľa
            $this->note,
            '1',                        // počet IBAN-ov
            $this->iban,
            $this->swift,
            '0',                        // bez StandingOrderExt
            '0',                        // bez DirectDebitExt
            $this->recipient,
            $this->recipientAddressLine1,
            $this->recipientAddressLine2,
        ];

        // Tabulátor je oddeľovač polí; vo vnútri hodnoty musí byť medzera.
        $inner = implode("\t", array_map(
            static fn (?string $value): string => str_replace("\t", ' ', $value ?? ''),
            $fields,
        ));

        // Celý dátový reťazec: InvoiceID (prázdne)\tPayments (count)\túdaje platby
        $data = implode("\t", ['', '1', $inner]);

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
        $cmd = $xzPath." '--format=raw' '--lzma1=lc=3,lp=0,pb=2,dict=128KiB' '-c' '-'";

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
        fclose($pipes[2]);
        proc_close($process);

        if ($compressed === false || $compressed === '') {
            throw new PayBySquareException('LZMA kompresia zlyhala. Skontrolujte dostupnosť xz.');
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

    /**
     * @throws ValidationException
     */
    private function validate(): void
    {
        $errors = [];

        if ($this->iban === '') {
            $errors[] = 'IBAN je povinný (setIban())';
        } elseif (!$this->isValidIban($this->iban)) {
            $errors[] = 'IBAN musí mať platný formát a kontrolný súčet';
        }

        if ($this->swift !== '' && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $this->swift)) {
            $errors[] = 'BIC/SWIFT musí mať 8 alebo 11 platných znakov';
        }

        if ($this->amount !== null && (!is_finite($this->amount) || $this->amount <= 0)) {
            $errors[] = 'Suma musí byť väčšia ako 0 (setAmount())';
        } elseif ($this->amount !== null && strlen(number_format($this->amount, 2, '.', '')) > 15) {
            $errors[] = 'Suma môže mať maximálne 15 znakov';
        }

        if (!preg_match('/^[A-Z]{3}$/D', $this->currency)) {
            $errors[] = 'Mena musí mať 3 písmená A-Z';
        }

        foreach ([
            ['Referencia platiteľa', $this->paymentReference, 35],
            ['Poznámka', $this->note, 140],
            ['Príjemca', $this->recipient, 70],
            ['Adresa príjemcu, riadok 1', $this->recipientAddressLine1, 70],
            ['Adresa príjemcu, riadok 2', $this->recipientAddressLine2, 70],
        ] as [$label, $value, $maximum]) {
            $length = preg_match_all('/./us', $value);
            if ($length === false) {
                $errors[] = $label.' musí byť platný UTF-8 text';
            } elseif ($length > $maximum) {
                $errors[] = $label.' môže mať maximálne '.$maximum.' znakov';
            }
        }

        foreach ([
            ['Variabilný symbol', $this->variableSymbol, 10],
            ['Konštantný symbol', $this->constantSymbol, 4],
            ['Špecifický symbol', $this->specificSymbol, 10],
        ] as [$label, $value, $maximum]) {
            if ($value !== '' && !ctype_digit($value)) {
                $errors[] = $label.' môže obsahovať len číslice';
            }
            if (strlen($value) > $maximum) {
                $errors[] = $label.' môže mať maximálne '.$maximum.($label === 'Konštantný symbol' ? ' znaky' : ' číslic');
            }
        }

        if ($this->paymentReference !== '' && ($this->variableSymbol !== '' || $this->constantSymbol !== '' || $this->specificSymbol !== '')) {
            $errors[] = 'Referencia platiteľa sa nemôže kombinovať s platobnými symbolmi';
        }

        if (!empty($errors)) {
            throw new ValidationException(implode('; ', $errors));
        }
    }

    private function isValidIban(string $iban): bool
    {
        $length = strlen($iban);
        if ($length < 15 || $length > 34 || !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'SK') && $length !== 24) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        for ($i = 0, $count = strlen($rearranged); $i < $count; $i++) {
            $character = $rearranged[$i];
            if (ctype_digit($character)) {
                $remainder = ($remainder * 10 + (int) $character) % 97;
            } else {
                $remainder = ($remainder * 100 + ord($character) - 55) % 97;
            }
        }

        return $remainder === 1;
    }
}
