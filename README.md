# PayBySquare PHP

OOP PHP knižnica pre generovanie slovenských **PAY by square** QR kódov.

Podporuje dátové polia PAY by square 1.1.0: jednorazové platby, viac platieb
a bankových účtov v jednom kóde, trvalé príkazy a inkaso. Výstupom je zakódovaný
reťazec alebo PNG v jednom zo štyroch vizuálnych štýlov.

**[Live Demo](https://pbs.engazan.eu)**

## Požiadavky

- PHP 8.1+
- `xz` nainštalovaný na serveri (`apt install xz-utils` / `yum install xz` / `brew install xz`)
- PHP rozšírenia `gd` (s podporou PNG; pre rámované štýly aj FreeType) a `ctype`
- dostupná funkcia `proc_open` na spustenie kompresie cez `xz`
- Composer na inštaláciu PHP závislostí

QR obrázky renderuje `bacon/bacon-qr-code` (`^3.0`), ktorý Composer nainštaluje
automaticky. Imagick nie je potrebný. Font pre rámované štýly je súčasťou balíka
v `resources/fonts/Inter.ttf`; zoznam podporovaných mien je v
`resources/iso4217-codes.txt`. Pri nasadení zachovaj adresár `resources/`.

## Inštalácia

```bash
composer require engazan/pay-by-square
```

## Použitie

```php
require __DIR__ . '/vendor/autoload.php';

use Engazan\PayBySquare\Generator;
use Engazan\PayBySquare\QrStyle;

$qr = (new Generator())
    ->setIban('SK3112000000198742637541')   // povinné; medzery sa odstránia
    ->setSwift('TATRSKBX')
    ->setAmount('49.99')                     // voliteľné; string zachová presnú hodnotu
    ->setRecipient('Jozko Mrkvicka')
    ->setRecipientAddressLine1('Hlavná 12')
    ->setRecipientAddressLine2('811 01 Bratislava')
    ->setVariableSymbol('20240001')          // max 10 číslic
    ->setConstantSymbol('0308')              // max 4 číslice
    ->setSpecificSymbol('9999')
    ->setNote('Faktura č. 2024/001')         // max 140 znakov
    ->setDueDate(new DateTime('+14 days'))
    ->setStyle(QrStyle::PayBySquare);
```

### Výstupné metódy

```php
// HTML <img> tag – priamo do šablóny
echo $qr->getImgTag(300, 'QR kód platby');

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

Veľkosť je v pixeloch, predvolene `300`, a určuje samotnú QR oblasť. Štýly
`Default` a `Transparent` vytvoria pri veľkosti `300` PNG s rozmermi 300 × 300;
rámované štýly vytvoria PNG s rozmermi 342 × 384 (vrátane rámu a päty).
`getImgTag()` aktuálne nastavuje obe HTML atribúty `width` a `height` na zadanú
veľkosť aj pri rámovaných štýloch. Na zachovanie pomeru strán použi vlastný
`<img>` s data URI, ako v príklade vyššie, prípadne CSS `height: auto`.

Pri `saveToFile()` musí cieľový adresár existovať a byť zapisovateľný. Metóda
nezakladá adresáre ani nekontroluje návratovú hodnotu zápisu; pri zlyhaní zápisu
môže PHP vydať warning.

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

Automaticky sa kontrolujú `/usr/bin/xz`, `/usr/local/bin/xz`,
`/opt/homebrew/bin/xz` a `/opt/homebrew/opt/xz/bin/xz`, nie celý systémový `PATH`.
Manuálna cesta má prednosť, ak je spustiteľná; inak sa použije auto-detekcia.

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

Validácia väčšiny polí prebehne až pri `generateString()` alebo pri generovaní
obrázka. `addPayment()` kontroluje prípustnosť pridávanej platby hneď.
`ValidationException` dedí z `PayBySquareException`, preto ju zachytávaj prvú.

## Parametre

| Setter | Popis | Obmedzenie |
|---|---|---|
| `setIban(string)` | IBAN (povinné) | medzery sa odstránia, písmená sa zmenia na veľké; platná dĺžka podľa podporovanej krajiny a kontrolný súčet |
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
| `setInvoiceId(string)` | Identifikátor celého kódu | max 10 znakov; iba na hlavnom generátore |
| `addBankAccount(string $iban, string $bic = '')` | Ďalší účet platby | rovnaká validácia IBAN/BIC ako pri prvom účte |
| `addPayment(Generator)` | Ďalšia platba v kóde | bez vlastného InvoiceID a vnorených platieb |
| `setPaymentOrderEnabled(bool)` | Ponúknuť jednorazovú platbu | predvolene `true`; musí zostať aspoň jedna možnosť platby |
| `setStandingOrder(...)` | Trvalý príkaz | parametre a limity nižšie |
| `setDirectDebit(...)` | Inkaso | parametre a limity nižšie |
| `setStyle(QrStyle)` | Vizuálny štýl QR kódu | default: `QrStyle::Default` |
| `setXzPath(string)` | Cesta k xz binárke | auto-detekcia |

Pri sumách, kde záleží na presnosti, používaj desatinný reťazec. `float` môže stratiť presnosť ešte pred odovzdaním knižnici.

Desatinný reťazec používa bodku, bez oddeľovačov tisícov, znamienka alebo
exponentu, napr. `'49.99'`. `null` znamená nezadanú sumu; prázdny reťazec a nula
nie sú platné sumy. Referencia platiteľa sa nesmie kombinovať s VS/KS/ŠS.
Textové polia musia byť platné UTF-8 bez nepovolených znakov XML 1.0; limity
sa počítajú v znakoch a tabulátory sa pri generovaní nahradia medzerami.
Dátumy sa kódujú ako `YYYYMMDD`, v rozsahu rokov 0001–9999; generátor si uloží
kópiu odovzdaného dátumu.

Na čítanie základných hodnôt sú dostupné `getIban()`, `getSwift()`, `getAmount()`,
`getCurrency()`, `getRecipient()`, `getRecipientAddressLine1()`,
`getRecipientAddressLine2()`, `getVariableSymbol()`, `getSpecificSymbol()`,
`getConstantSymbol()`, `getPaymentReference()` a `getNote()`.

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

Limit 550 znakov zahŕňa celú nekomprimovanú dátovú sekvenciu vrátane oddeľovačov.
`addPayment()` uloží kópiu platby; jej neskoršie úpravy sa do pridaného záznamu
nepremietnu. Generátor nemôže pridať sám seba.

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

Signatúry rozšírených metód:

```php
setStandingOrder(string $periodicity, ?int $day = null, array $months = [], ?DateTimeInterface $lastDate = null)
setDirectDebit(string $scheme, string $type, array $identification = [], float|string|null $maxAmount = null, ?DateTimeInterface $validTillDate = null)
```

Deň trvalého príkazu je voliteľný: 1–7 pri `Weekly`/`Biweekly`, inak 1–31.
Prázdne pole mesiacov a dátum `null` nechajú príslušné polia nezadané.
Pri inkase sú hodnoty identifikácie reťazce: symboly majú max 10 číslic,
`reference`, `mandateId`, `creditorId` a `contractId` max 35 znakov.
`mandateId` a `creditorId` sa zadávajú spolu a iba pre SEPA; `contractId` je
voliteľný doplnok tejto dvojice. Pri schéme `other` môže byť identifikácia aj
prázdna. Maximálna suma inkasa má rovnaké limity ako suma platby.
Jednorazovú platbu, trvalý príkaz a inkaso možno v jednej platbe kombinovať.

## Príklady a lokálne demo

Po stiahnutí repozitára spusti z jeho koreňa:

```sh
composer install
php -S 127.0.0.1:8080 -t examples
```

Otvor [http://127.0.0.1:8080](http://127.0.0.1:8080). Demo umožňuje viac platieb
a účtov, trvalé príkazy, inkaso a náhľad všetkých štyroch štýlov.
[examples/basic.php](examples/basic.php) obsahuje jednoduché PHP použitie.

Formulár odosiela JSON na [examples/payment-preview.php](examples/payment-preview.php),
ktorý vracia data URI pre všetky štyri štýly. [examples/generate.php](examples/generate.php)
je kompatibilný alias pôvodného endpointu. Endpoint podporuje aj jednoduché GET
volanie s parametrami `iban`, `swift`, `amount`, `currency`, `recipient`,
`recipientAddressLine1`, `recipientAddressLine2`, `vs`, `cs`, `ss`, `reference`,
`note`, `dueDate` (`YYYY-MM-DD`) a `size`. Veľkosť dema sa obmedzuje na 100–1000 px.
Neplatný vstup vracia HTTP 422, chyba kompresie HTTP 500.

## Testy

Testy spustíš z koreňa projektu:

```sh
composer install
vendor/bin/phpunit
```

Vyžadujú nainštalované vývojové závislosti (PHPUnit 10), binárku `xz` na
štandardnej ceste a PHP rozšírenie GD s podporou PNG a FreeType.
Overujú dátum splatnosti, validácie a ich hraničné hodnoty, dekódovaný obsah
platby vrátane dĺžky a CRC32, všetky štyri PNG štýly, data URI a zápis do súboru.
Zahŕňajú aj viac platieb a účtov, trvalé príkazy a inkaso.
Kontrola PNG overuje formát a rozmery; neoveruje načítanie QR bankovou aplikáciou.

## Licencia

MIT
