# PayBySquare PHP

OOP PHP knižnica pre generovanie slovenských **PAY by square** QR kódov.

**[Live Demo](https://pbs.engazan.eu)**

## Požiadavky

- PHP 8.0+
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
    ->setIban('SK7700000000000000000000')   // povinné, bez medzier
    ->setSwift('CEKOSKBX')
    ->setAmount(49.99)                       // povinné
    ->setRecipient('Jozko Mrkvicka')
    ->setVariableSymbol('20240001')          // max 10 číslic
    ->setConstantSymbol('0308')              // max 4 znaky
    ->setSpecificSymbol('9999')
    ->setPaymentReference('/VS1234/SS5678')   // max 35 znakov
    ->setNote('Faktura č. 2024/001')         // max 35 znakov
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
| `setIban(string)` | IBAN (povinné) | bez medzier |
| `setSwift(string)` | BIC/SWIFT kód banky | |
| `setAmount(float)` | Suma (povinné) | > 0, max 2 des. |
| `setCurrency(string)` | Mena | default `EUR` |
| `setRecipient(string)` | Príjemca | |
| `setVariableSymbol(string)` | VS | max 10 číslic |
| `setSpecificSymbol(string)` | ŠS | max 10 číslic |
| `setConstantSymbol(string)` | KS | max 4 znaky |
| `setPaymentReference(string)` | Referencia platiteľa | max 35 znakov |
| `setNote(string)` | Poznámka | max 35 znakov |
| `setDueDate(DateTimeInterface)` | Dátum splatnosti | default: dnes |
| `setStyle(QrStyle)` | Vizuálny štýl QR kódu | default: `QrStyle::Default` |
| `setXzPath(string)` | Cesta k xz binárke | auto-detekcia |

## Licencia

MIT
