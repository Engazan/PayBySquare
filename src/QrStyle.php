<?php

declare(strict_types=1);

namespace PayBySquare;

enum QrStyle: string
{
    /** Čistý QR kód, biele pozadie */
    case Default = 'default';

    /** QR kód s priehľadným pozadím (PNG s alpha kanálom) */
    case Transparent = 'transparent';

    /** Rám + "PAY by square" footer – biele pozadie */
    case PayBySquare = 'pay_by_square';

    /** Rám + "PAY by square" footer – priehľadné pozadie QR oblasti */
    case PayBySquareTransparent = 'pay_by_square_transparent';
}
