# PayBySquare PHP

OOP PHP knižnica pre generovanie slovenských **PAY by square** QR kódov.

## Požiadavky

- PHP 8.0+
- `xz` nainštalovaný na serveri (`apt install xz-utils` / `yum install xz`)
- PHP extension `gd` alebo `imagick` (pre renderovanie QR obrázkov)

## Inštalácia

```bash
composer require engazan/pay-by-square
```

## Použitie

```php
use PayBySquare\PayBySquare;

$qr = (new PayBySquare())
    ->setIban('SK7700000000000000000000')   // povinné, bez medzier
    ->setSwift('CEKOSKBX')
    ->setAmount(49.99)                       // povinné
    ->setRecipient('Jozko Mrkvicka')
    ->setVariableSymbol('20240001')          // max 10 číslic
    ->setConstantSymbol('0308')              // max 4 znaky
    ->setSpecificSymbol('9999')
    ->setNote('Faktura č. 2024/001')         // max 35 znakov
    ->setDueDate(new DateTime('+14 days'));
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

### Zmena cesty k xz

Ak `xz` nie je na `/usr/bin/xz`:

```php
$qr->setXzPath('/usr/local/bin/xz');
```

### Ošetrenie výnimiek

```php
use PayBySquare\Exception\ValidationException;
use PayBySquare\Exception\PayBySquareException;

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
| `setAmount(float)` | Suma v EUR (povinné) | > 0, max 2 des. |
| `setCurrency(string)` | Mena | default `EUR` |
| `setRecipient(string)` | Príjemca | |
| `setVariableSymbol(string)` | VS | max 10 číslic |
| `setSpecificSymbol(string)` | ŠS | max 10 číslic |
| `setConstantSymbol(string)` | KS | max 4 znaky |
| `setNote(string)` | Poznámka | max 35 znakov |
| `setDueDate(DateTimeInterface)` | Dátum splatnosti | default: dnes |
| `setXzPath(string)` | Cesta k xz binárke | default: `/usr/bin/xz` |

## Licencia

MIT
