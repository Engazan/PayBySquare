<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PayBySquare\Generator;
use PayBySquare\QrStyle;
use PayBySquare\Exception\ValidationException;
use PayBySquare\Exception\PayBySquareException;

try {
    $base = (new Generator())
        ->setIban('SK7700000000000000000000')
        ->setSwift('CEKOSKBX')
        ->setAmount(49.99)
        ->setRecipient('Jozko Mrkvicka')
        ->setVariableSymbol('20240001')
        ->setNote('Faktura 2024/001');

    // Štýl 1: Default (biele pozadie)
    $defaultQr = (clone $base)->setStyle(QrStyle::Default);
    echo $defaultQr->getImgTag(300);

    // Štýl 2: Transparent
    $transparentQr = (clone $base)->setStyle(QrStyle::Transparent);
    echo $transparentQr->getImgTag(300);

    // Štýl 3: PAY by square dizajn
    $styledQr = (clone $base)->setStyle(QrStyle::PayBySquare);
    echo $styledQr->getImgTag(300);

    // Uloženie do súborov
    $defaultQr->saveToFile('/tmp/qr_default.png', 300);
    $transparentQr->saveToFile('/tmp/qr_transparent.png', 300);
    $styledQr->saveToFile('/tmp/qr_pay_by_square.png', 300);

} catch (ValidationException $e) {
    echo 'Chyba validácie: ' . $e->getMessage();
} catch (PayBySquareException $e) {
    echo 'Chyba generovania: ' . $e->getMessage();
}
