<?php

declare(strict_types=1);

// Potlačíme deprecated/notice warnings aby nerozbíjali JSON output
error_reporting(E_ERROR | E_PARSE);

require __DIR__ . '/../vendor/autoload.php';

use Engazan\PayBySquare\Generator;
use Engazan\PayBySquare\QrStyle;
use Engazan\PayBySquare\Exception\ValidationException;
use Engazan\PayBySquare\Exception\PayBySquareException;

// ── Vstup ────────────────────────────────────────────────────────────────────

$iban      = trim($_GET['iban'] ?? '');
$swift     = trim($_GET['swift'] ?? '');
$amount    = (float) ($_GET['amount'] ?? 0);
$recipient = trim($_GET['recipient'] ?? '');
$vs        = trim($_GET['vs'] ?? '');
$cs        = trim($_GET['cs'] ?? '');
$ss        = trim($_GET['ss'] ?? '');
$note      = trim($_GET['note'] ?? '');
$currency  = trim($_GET['currency'] ?? 'EUR');
$dueDate   = trim($_GET['dueDate'] ?? '');
$size      = max(100, min(1000, (int) ($_GET['size'] ?? 300)));
$styleRaw  = trim($_GET['style'] ?? 'default');

$style = match ($styleRaw) {
    'transparent'              => QrStyle::Transparent,
    'pay_by_square'            => QrStyle::PayBySquare,
    'pay_by_square_transparent' => QrStyle::PayBySquareTransparent,
    default                    => QrStyle::Default,
};

// ── Generovanie ───────────────────────────────────────────────────────────────

try {
    $gen = (new Generator())
        ->setIban($iban)
        ->setSwift($swift)
        ->setAmount($amount)
        ->setCurrency($currency)
        ->setRecipient($recipient)
        ->setVariableSymbol($vs)
        ->setConstantSymbol($cs)
        ->setSpecificSymbol($ss)
        ->setNote($note)
        ->setStyle($style);

    if ($dueDate !== '') {
        $gen->setDueDate(new \DateTime($dueDate));
    }

    $png = $gen->getDataUri($size);

    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['dataUri' => $png]);

} catch (ValidationException $e) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
} catch (PayBySquareException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
