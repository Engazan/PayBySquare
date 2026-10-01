<?php

declare(strict_types=1);

namespace Engazan\PayBySquare;

use Engazan\PayBySquare\Exception\ValidationException;

/** Shared field formatting and validation for PAY by square 1.1.0. */
final class PaymentFormat
{
    // SWIFT IBAN Registry, release 101 (December 2025).
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28,
        'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BI' => 27,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DJ' => 27, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FK' => 18,
        'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23,
        'GL' => 18, 'GR' => 27, 'GT' => 28, 'HN' => 28, 'HR' => 21,
        'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28,
        'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19,
        'MN' => 20, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NI' => 28,
        'NL' => 18, 'NO' => 15, 'OM' => 23, 'PK' => 24, 'PL' => 28,
        'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22,
        'RU' => 33, 'SA' => 24, 'SC' => 31, 'SD' => 18, 'SE' => 24,
        'SI' => 19, 'SK' => 24, 'SM' => 27, 'SO' => 23, 'ST' => 25,
        'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29,
        'VA' => 22, 'VG' => 24, 'XK' => 20, 'YE' => 30,
    ];

    public static function decimal(float|string|null $value, string $label): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new ValidationException($label.' musí byť kladné konečné číslo');
            }
            // Keep legacy float inputs usable near the 15-character field limit.
            // Decimal strings are preferable when every fractional digit matters.
            $integerDigits = strlen(sprintf('%.0F', floor(abs($value))));
            $fractionDigits = min(8, max(0, 14 - $integerDigits));
            $value = sprintf('%.'.$fractionDigits.'F', $value);
            if (str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
        }
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $value) || !preg_match('/[1-9]/', $value)) {
            throw new ValidationException($label.' musí byť kladné číslo s najviac 8 desatinnými miestami');
        }
        if (strlen($value) > 15) {
            throw new ValidationException($label.' môže mať maximálne 15 znakov');
        }

        return $value;
    }

    public static function date(?\DateTimeInterface $date, string $label): string
    {
        if ($date === null) {
            return '';
        }
        $value = $date->format('Ymd');
        if (!preg_match('/^[0-9]{8}$/D', $value) || substr($value, 0, 4) === '0000') {
            throw new ValidationException($label.' musí byť v rozsahu rokov 0001 až 9999');
        }

        return $value;
    }

    public static function text(string $value, int $maximum, string $label): string
    {
        $value = str_replace("\t", ' ', $value);
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new ValidationException($label.' musí byť platný UTF-8 text');
        }
        if (preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value)) {
            throw new ValidationException($label.' obsahuje nepovolený znak XML 1.0');
        }
        if ($length > $maximum) {
            throw new ValidationException($label.' môže mať maximálne '.$maximum.' znakov');
        }

        return $value;
    }

    public static function symbol(string $value, int $maximum, string $label): string
    {
        if ($value !== '' && !ctype_digit($value)) {
            throw new ValidationException($label.' môže obsahovať len číslice');
        }
        if (strlen($value) > $maximum) {
            throw new ValidationException($label.' môže mať maximálne '.$maximum.' číslic');
        }

        return $value;
    }

    public static function currency(string $currency): string
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new ValidationException('Mena musí mať 3 písmená A-Z');
        }
        static $codes = null;
        if ($codes === null) {
            $contents = @file_get_contents(__DIR__.'/../resources/iso4217-codes.txt');
            if ($contents === false) {
                throw new ValidationException('Zoznam kódov ISO 4217 nie je dostupný');
            }
            $codes = array_fill_keys(preg_split('/\s+/', trim(preg_replace('/^#.*$/m', '', $contents))), true);
        }
        if (!isset($codes[$currency])) {
            throw new ValidationException('Mena nie je platný kód ISO 4217');
        }

        return $currency;
    }

    public static function bic(string $bic): string
    {
        if ($bic !== '' && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $bic)) {
            throw new ValidationException('BIC/SWIFT musí mať 8 alebo 11 platných znakov');
        }

        return $bic;
    }

    public static function iban(string $iban): string
    {
        if ($iban === '') {
            throw new ValidationException('IBAN je povinný (setIban())');
        }
        $country = substr($iban, 0, 2);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $iban)
            || !isset(self::IBAN_LENGTHS[$country])
            || strlen($iban) !== self::IBAN_LENGTHS[$country]) {
            throw new ValidationException('IBAN musí mať platný formát a dĺžku podľa krajiny');
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        for ($i = 0, $count = strlen($rearranged); $i < $count; $i++) {
            $character = $rearranged[$i];
            $remainder = ctype_digit($character)
                ? ($remainder * 10 + (int) $character) % 97
                : ($remainder * 100 + ord($character) - 55) % 97;
        }
        if ($remainder !== 1) {
            throw new ValidationException('IBAN musí mať platný kontrolný súčet');
        }

        return $iban;
    }
}
