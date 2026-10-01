# PayBySquare PHP

OOP PHP knižnica pre generovanie slovenských **PAY by square** QR kódov.

**[Live Demo](https://pbs.engazan.eu)**

## Požiadavky

- PHP 8.1+
- `xz` nainštalovaný na serveri (`apt install xz-utils` / `yum install xz` / `brew install xz`)
- PHP extension `gd` (pre renderovanie QR obrázkov)

## Inštalácia

```bash
composer require engazan/pay-by-square
```

## Použitie

```php
use Engazan\PayBySquare\Generator;
use Engazan\PayBySquare\QrStyle;

$qr = (new Generator())
    ->setIban('SK3112000000198742637541')   // povinné, bez medzier
    ->setSwift('TATRSKBX')
    ->setAmount('49.99')                     // voliteľné; string zachová presnú hodnotu
    ->setRecipient('Jozko Mrkvicka')
    ->setRecipientAddressLine1('Hlavná 12')
    ->setRecipientAddressLine2('811 01 Bratislava')
    ->setVariableSymbol('20240001')          // max 10 číslic
    ->setConstantSymbol('0308')              // max 4 znaky
    ->setSpecificSymbol('9999')
    ->setNote('Faktura č. 2024/001')         // max 140 znakov
    ->setDueDate(new DateTime('+14 days'))
    ->setStyle(QrStyle::PayBySquare);
```

### Výstupné metódy

```php
// HTML <img> tag – priamo do šablóny
echo $qr->getImgTag(300);

// Len data URI
$uri = $qr->getDataUri(300);
echo "<img src=\"{$uri}\">";

// Uložiť PNG súbor
$qr->saveToFile('/var/www/qr/platba.png', 300);

// Surové PNG bajty (napr. pre HTTP response)
header('Content-Type: image/png');
echo $qr->getPngBytes(300);

// Len Pay by square reťazec (ak máš vlastný QR renderer)
$string = $qr->generateString();
```

## Štýly QR kódov

Vizuálny štýl QR kódu sa nastavuje cez `setStyle()`:

```php
use Engazan\PayBySquare\QrStyle;

$qr->setStyle(QrStyle::PayBySquare);
```

| Štýl | Hodnota | Popis |
|---|---|---|
| `QrStyle::Default` | `default` | Čistý QR kód, biele pozadie |
| `QrStyle::Transparent` | `transparent` | QR kód s priehľadným pozadím (PNG s alpha kanálom) |
| `QrStyle::PayBySquare` | `pay_by_square` | Modrý rám + "PAY by square" footer, biele pozadie |
| `QrStyle::PayBySquareTransparent` | `pay_by_square_transparent` | Modrý rám + "PAY by square" footer, priehľadné pozadie QR oblasti |

### Zmena cesty k xz

Ak `xz` nie je na štandardnej ceste:

```php
$qr->setXzPath('/usr/local/bin/xz');
```

### Ošetrenie výnimiek

```php
use Engazan\PayBySquare\Exception\ValidationException;
use Engazan\PayBySquare\Exception\PayBySquareException;

try {
    echo $qr->getImgTag();
} catch (ValidationException $e) {
    // Chýba IBAN, suma <= 0, príliš dlhá poznámka...
    echo 'Neplatné dáta: ' . $e->getMessage();
} catch (PayBySquareException $e) {
    // xz binárka sa nenašla, kompresia zlyhala...
    echo 'Chyba generovania: ' . $e->getMessage();
}
```

## Parametre

| Setter | Popis | Obmedzenie |
|---|---|---|
| `setIban(string)` | IBAN (povinné) | bez medzier, platný kontrolný súčet |
| `setSwift(string)` | BIC/SWIFT kód banky | voliteľný, 8 alebo 11 znakov |
| `setAmount(float\|string\|null)` | Suma | voliteľná, kladná; max 8 desatinných miest a 15 znakov |
| `setCurrency(string)` | Mena | platný kód ISO 4217, default `EUR` |
| `setRecipient(string)` | Príjemca | max 70 znakov |
| `setRecipientAddressLine1(string)` | Adresa príjemcu, riadok 1 | max 70 znakov |
| `setRecipientAddressLine2(string)` | Adresa príjemcu, riadok 2 | max 70 znakov |
| `setVariableSymbol(string)` | VS | max 10 číslic |
| `setSpecificSymbol(string)` | ŠS | max 10 číslic |
| `setConstantSymbol(string)` | KS | max 4 číslice |
| `setPaymentReference(string)` | Referencia platiteľa | max 35 znakov; alternatíva k VS/KS/ŠS |
| `setNote(string)` | Poznámka | max 140 znakov |
| `setDueDate(DateTimeInterface)` | Dátum splatnosti | predvolene prázdny |
| `setStyle(QrStyle)` | Vizuálny štýl QR kódu | default: `QrStyle::Default` |
| `setXzPath(string)` | Cesta k xz binárke | auto-detekcia |

Pri sumách, kde záleží na presnosti, používaj desatinný reťazec. `float` môže stratiť presnosť ešte pred odovzdaním knižnici.

### Viac platieb a účtov

```php
$first = (new Generator())
    ->setInvoiceId('INV-2026')
    ->setIban('SK3112000000198742637541')
    ->addBankAccount('DE89370400440532013000', 'COBADEFFXXX')
    ->setAmount('49.99')
    ->setRecipient('Prvý príjemca');

$second = (new Generator())
    ->setIban('DE89370400440532013000')
    ->setAmount('1.23456789')
    ->setRecipient('Druhý príjemca');

$first->addPayment($second);
$code = $first->generateString();
```

`InvoiceID` patrí celému kódu, preto sa nastavuje na prvom generátore. Prvý účet každej platby je predvolený. Ak spoločná sekvencia presiahne 550 znakov, treba platby rozdeliť do viacerých QR kódov.

### Trvalý príkaz a inkaso

```php
$payment = (new Generator())
    ->setIban('SK3112000000198742637541')
    ->setStandingOrder('Annually', 15, [1, 3], new DateTimeImmutable('2027-12-31'));

$debit = (new Generator())
    ->setIban('SK3112000000198742637541')
    ->setPaymentOrderEnabled(false)
    ->setDirectDebit(
        'SEPA', 'recurrent',
        ['mandateId' => 'MANDATE-1', 'creditorId' => 'CREDITOR-1'],
        '100.00', new DateTimeImmutable('2028-12-31'),
    );
```

`setStandingOrder()` prijíma periodicitu `Daily`, `Weekly`, `Biweekly`, `Monthly`, `Bimonthly`, `Quarterly`, `Semiannually` alebo `Annually`; mesiace sú čísla 1–12. `setDirectDebit()` prijíma schému `other` alebo `SEPA`, typ `one-off` alebo `recurrent` a jednu alternatívu identifikácie: `variableSymbol`/`specificSymbol`, `reference`, alebo `mandateId`/`creditorId`/`contractId`. SEPA vyžaduje mandát a identifikátor veriteľa. Predvolene sa k rozšíreniu ponúka aj jednorazová platba; `setPaymentOrderEnabled(false)` ju vypne.

## Testy

Testy spustíš z koreňa projektu:

```sh
vendor/bin/phpunit
```

Vyžadujú nainštalované vývojové závislosti (PHPUnit 10), binárku `xz` na
štandardnej ceste a PHP rozšírenie GD s podporou PNG a FreeType.
Overujú dátum splatnosti, validácie a ich hraničné hodnoty, dekódovaný obsah
platby vrátane dĺžky a CRC32, všetky štyri PNG štýly, data URI a zápis do súboru.
Kontrola PNG overuje formát a rozmery; neoveruje načítanie QR bankovou aplikáciou.

## Licencia

MIT
