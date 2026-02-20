<?php

declare(strict_types=1);

namespace Engazan\PayBySquare;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Engazan\PayBySquare\Exception\PayBySquareException;

/**
 * Renderuje QR kód v rôznych vizuálnych štýloch pomocou GD.
 */
class QrRenderer
{
    private const COLOR_BLUE_BORDER = [100, 160, 215];
    private const COLOR_GRAY_BG     = [240, 242, 245];
    private const COLOR_BLUE_TEXT   = [100, 160, 215];
    private const COLOR_GRAY_TEXT   = [140, 150, 165];

    public function __construct(
        private readonly string  $qrString,
        private readonly int     $size,
        private readonly QrStyle $style,
    ) {}

    public function render(): string
    {
        return match ($this->style) {
            QrStyle::Default                => $this->renderDefault(),
            QrStyle::Transparent            => $this->renderTransparent(),
            QrStyle::PayBySquare            => $this->renderPayBySquare(false),
            QrStyle::PayBySquareTransparent => $this->renderPayBySquare(true),
        };
    }

    // ─── Štýly ───────────────────────────────────────────────────────────────

    private function renderDefault(): string
    {
        return $this->gdPng($this->size);
    }

    private function renderTransparent(): string
    {
        // Vygenerujeme čierny QR na bielom pozadí, potom biele → transparentné
        $src = imagecreatefromstring($this->gdPng($this->size));
        $w   = imagesx($src);
        $h   = imagesy($src);

        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transp = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transp);
        $black = imagecolorallocatealpha($dst, 0, 0, 0, 0);
        imagealphablending($dst, true);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($src, $x, $y);
                $r   = ($rgb >> 16) & 0xFF;
                // Tmavý pixel = QR modul → čierny
                if ($r < 128) {
                    imagesetpixel($dst, $x, $y, $black);
                }
                // Svetlý pixel = pozadie → ostane transparentný
            }
        }

        ob_start();
        imagepng($dst);
        return ob_get_clean();
    }

    private function renderPayBySquare(bool $transparent = false): string
    {
        $qrSize  = $this->size;
        $padding = (int)round($qrSize * 0.07);
        $borderW = max(2, (int)round($qrSize * 0.012));
        $footerH = (int)round($qrSize * 0.14);
        $cornerR = (int)round($qrSize * 0.06);
        $innerR  = max(1, $cornerR - $borderW);
        $footerY = $borderW + $padding + $qrSize + $padding;

        $totalW = $qrSize + $padding * 2;
        $totalH = $qrSize + $padding * 2 + $footerH;

        // Canvas vždy s alpha kanálom
        $img = imagecreatetruecolor($totalW, $totalH);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        [$br, $bg, $bb] = self::COLOR_BLUE_BORDER;
        [$fr, $fg, $fb] = self::COLOR_GRAY_BG;

        $cTransp = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $cWhite  = imagecolorallocate($img, 255, 255, 255);
        $cBlue   = imagecolorallocate($img, $br, $bg, $bb);
        $cFooter = imagecolorallocate($img, $fr, $fg, $fb);

        // Začíname s úplne transparentným canvasom
        imagefill($img, 0, 0, $cTransp);
        imagealphablending($img, true);

        // Modrý rám
        $this->filledRoundedRect($img, $cBlue, 0, 0, $totalW - 1, $totalH - 1, $cornerR);

        if ($transparent) {
            // QR oblasť ostane priehľadná – prerežeme dieru cez rám
            $cHole = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagealphablending($img, false);
            // Prerežeme dieru cez celé vnútro (QR + footer)
            $this->filledRoundedRect($img, $cHole, $borderW, $borderW, $totalW - 1 - $borderW, $totalH - 1 - $borderW, $innerR);
            imagealphablending($img, true);
        } else {
            // Biele vnútro QR oblasti
            $this->filledRoundedRect($img, $cWhite, $borderW, $borderW, $totalW - 1 - $borderW, $totalH - 1 - $borderW, $innerR);
        }

        // Sivý footer (len pri nepriehľadnom režime)
        if (!$transparent) {
            $this->filledRoundedRect($img, $cWhite, $borderW, $footerY, $totalW - 1 - $borderW, $totalH - 1 - $borderW, $innerR);
            imagefilledrectangle($img, $borderW, $footerY, $totalW - 1 - $borderW, $footerY + $innerR, $cWhite);
        }

        // QR kód
        if ($transparent) {
            $qrSrc = imagecreatefromstring($this->renderTransparent());
            imagecopy($img, $qrSrc, $padding, $padding, 0, 0, $qrSize, $qrSize);
        } else {
            $qrSrc = imagecreatefromstring($this->gdPng($qrSize));
            imagecopy($img, $qrSrc, $padding, $padding, 0, 0, $qrSize, $qrSize);
        }

        // Footer text + ikona – centrovanie medzi koncom QR a koncom obrázka
        $qrBottom = $padding + $qrSize;
        $this->drawFooter($img, (int)($totalW / 2), (int)(($qrBottom + $totalH) / 2), max(1, (int)round($qrSize / 60)));

        ob_start();
        imagepng($img);
        return ob_get_clean();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Vygeneruje čistý QR PNG cez bacon GDLibRenderer (nevyžaduje Imagick).
     */
    private function gdPng(int $size): string
    {
        $renderer = new GDLibRenderer($size, 0);
        $writer   = new Writer($renderer);
        return $writer->writeString($this->qrString);
    }

    private function drawFooter(\GdImage $img, int $cx, int $cy, int $scale): void
    {
        [$br, $bg, $bb] = self::COLOR_BLUE_TEXT;
        [$gr, $gg, $gb] = self::COLOR_GRAY_TEXT;

        $cBlue  = imagecolorallocate($img, $br, $bg, $bb);
        $cGray  = imagecolorallocate($img, $gr, $gg, $gb);
        $cCard  = imagecolorallocate($img, $br, $bg, $bb);
        $cWhite = imagecolorallocate($img, 255, 255, 255);

        $fontSize = max(6, (int)round($this->size * 0.055));
        $fontPath = __DIR__ . '/../resources/fonts/Inter.ttf';

        // Zmeráme rozmery textov
        $payBox = imagettfbbox($fontSize, 0, $fontPath, 'PAY');
        $payW   = $payBox[2] - $payBox[0];
        $byBox  = imagettfbbox($fontSize, 0, $fontPath, ' by square');
        $byW    = $byBox[2] - $byBox[0];
        $textH  = $payBox[1] - $payBox[5];

        $iconH   = (int)round($textH * 0.9);
        $iconW   = (int)round($iconH * 1.5);
        $iconGap = (int)round($fontSize * 0.5);

        $totalW  = $payW + $byW + $iconGap + $iconW;
        $startX  = $cx - (int)($totalW / 2);
        $ascent  = -$payBox[5]; // výška nad baseline (payBox[5] je záporné)
        $descent = $payBox[1];  // hĺbka pod baseline
        $textY   = $cy + (int)(($ascent - $descent) / 2); // baseline vertikálne vycentrovaná

        imagettftext($img, $fontSize, 0, $startX, $textY, $cBlue, $fontPath, 'PAY');
        imagettftext($img, $fontSize, 0, $startX + $payW, $textY, $cGray, $fontPath, ' by square');

        // Ikona karty
        $iconX = $startX + $payW + $byW + $iconGap;
        $iconY = $cy - (int)($iconH / 2);
        $this->filledRoundedRect($img, $cCard, $iconX, $iconY, $iconX + $iconW, $iconY + $iconH, max(2, (int)round($iconH * 0.2)));
        $stripeY = $iconY + (int)($iconH * 0.35);
        imagefilledrectangle($img, $iconX, $stripeY, $iconX + $iconW, $stripeY + max(1, (int)($iconH * 0.2)), $cWhite);
    }

    private function filledRoundedRect(\GdImage $img, int $color, int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        $r = min($r, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2));
        if ($r < 1) { imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color); return; }
        imagefilledrectangle($img, $x1 + $r, $y1,      $x2 - $r, $y2,      $color);
        imagefilledrectangle($img, $x1,      $y1 + $r, $x2,      $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }
}
