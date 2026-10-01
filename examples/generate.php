<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Engazan\PayBySquare\Exception\PayBySquareException;
use Engazan\PayBySquare\Exception\ValidationException;
use Engazan\PayBySquare\Generator;
use Engazan\PayBySquare\QrStyle;

/** @param array<string, mixed> $data */
function textField(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        throw new ValidationException($key.' musí byť text');
    }
    return trim($value);
}

/** @param array<string, mixed> $data */
function dateField(array $data, string $key): ?\DateTimeImmutable
{
    $value = textField($data, $key);
    if ($value === '') {
        return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new ValidationException($key.' musí byť platný dátum YYYY-MM-DD');
    }
    return $date;
}

/** @param array<string, mixed> $data */
function paymentFromData(array $data): Generator
{
    $accounts = $data['accounts'] ?? null;
    if (!is_array($accounts) || !array_is_list($accounts) || $accounts === [] || !is_array($accounts[0])) {
        throw new ValidationException('Platba musí obsahovať aspoň jeden bankový účet');
    }

    $payment = (new Generator())
        ->setIban(textField($accounts[0], 'iban'))
        ->setSwift(textField($accounts[0], 'bic'))
        ->setCurrency(textField($data, 'currency'))
        ->setRecipient(textField($data, 'recipient'))
        ->setRecipientAddressLine1(textField($data, 'address1'))
        ->setRecipientAddressLine2(textField($data, 'address2'))
        ->setVariableSymbol(textField($data, 'vs'))
        ->setConstantSymbol(textField($data, 'cs'))
        ->setSpecificSymbol(textField($data, 'ss'))
        ->setPaymentReference(textField($data, 'reference'))
        ->setNote(textField($data, 'note'));

    $amount = textField($data, 'amount');
    if ($amount !== '') {
        $payment->setAmount($amount);
    }
    $dueDate = dateField($data, 'dueDate');
    if ($dueDate !== null) {
        $payment->setDueDate($dueDate);
    }
    foreach (array_slice($accounts, 1) as $account) {
        if (!is_array($account)) {
            throw new ValidationException('Bankový účet musí byť objekt');
        }
        $payment->addBankAccount(textField($account, 'iban'), textField($account, 'bic'));
    }

    $paymentOrder = $data['paymentOrder'] ?? true;
    if (!is_bool($paymentOrder)) {
        throw new ValidationException('paymentOrder musí byť logická hodnota');
    }
    $payment->setPaymentOrderEnabled($paymentOrder);

    if (isset($data['standing'])) {
        $standing = $data['standing'];
        if (!is_array($standing)) {
            throw new ValidationException('Trvalý príkaz musí byť objekt');
        }
        $day = $standing['day'] ?? null;
        $months = $standing['months'] ?? [];
        if (($day !== null && !is_int($day)) || !is_array($months) || !array_is_list($months)) {
            throw new ValidationException('Neplatný deň alebo mesiace trvalého príkazu');
        }
        $payment->setStandingOrder(
            textField($standing, 'periodicity'),
            $day,
            $months,
            dateField($standing, 'lastDate'),
        );
    }

    if (isset($data['debit'])) {
        $debit = $data['debit'];
        if (!is_array($debit) || !is_array($debit['identification'] ?? null)) {
            throw new ValidationException('Inkaso musí obsahovať identifikáciu');
        }
        $identification = [];
        foreach ($debit['identification'] as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new ValidationException('Identifikačné polia inkasa musia byť textové');
            }
            $identification[$key] = trim($value);
        }
        $maxAmount = textField($debit, 'maxAmount');
        $payment->setDirectDebit(
            textField($debit, 'scheme'),
            textField($debit, 'type'),
            $identification,
            $maxAmount === '' ? null : $maxAmount,
            dateField($debit, 'validTillDate'),
        );
    }

    return $payment;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $request = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !is_array($request['payments'] ?? null) || !array_is_list($request['payments']) || $request['payments'] === []) {
            throw new ValidationException('Očakáva sa aspoň jedna platba');
        }
    } else {
        // Zachová jednoduché priame volanie endpointu cez GET.
        $request = [
            'invoiceId' => '',
            'size' => $_GET['size'] ?? '300',
            'payments' => [array_merge($_GET, [
                'currency' => $_GET['currency'] ?? 'EUR',
                'accounts' => [[
                    'iban' => $_GET['iban'] ?? '',
                    'bic' => $_GET['swift'] ?? '',
                ]],
                'address1' => $_GET['recipientAddressLine1'] ?? '',
                'address2' => $_GET['recipientAddressLine2'] ?? '',
                'paymentOrder' => true,
            ])],
        ];
    }

    $size = $request['size'] ?? 300;
    if (!is_numeric($size)) {
        throw new ValidationException('Veľkosť QR musí byť číslo');
    }
    $size = max(100, min(1000, (int) $size));
    $payments = $request['payments'];
    if (!is_array($payments[0])) {
        throw new ValidationException('Platba musí byť objekt');
    }
    $generator = paymentFromData($payments[0])->setInvoiceId(textField($request, 'invoiceId'));
    foreach (array_slice($payments, 1) as $payment) {
        if (!is_array($payment)) {
            throw new ValidationException('Platba musí byť objekt');
        }
        $generator->addPayment(paymentFromData($payment));
    }

    $styles = [
        'pay_by_square' => QrStyle::PayBySquare,
        'pay_by_square_transparent' => QrStyle::PayBySquareTransparent,
        'default' => QrStyle::Default,
        'transparent' => QrStyle::Transparent,
    ];
    $result = [];
    foreach ($styles as $key => $style) {
        $result[$key] = $generator->setStyle($style)->getDataUri($size);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\JsonException|ValidationException $exception) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
} catch (PayBySquareException $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
}
