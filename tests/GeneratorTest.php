<?php

declare(strict_types=1);

namespace Engazan\PayBySquare\Tests;

use Engazan\PayBySquare\Exception\ValidationException;
use Engazan\PayBySquare\Generator;
use Engazan\PayBySquare\QrStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private function payment(): Generator
    {
        return (new Generator())
            ->setIban('SK3112000000198742637541')
            ->setAmount(49.99);
    }

    public function testUnsetDueDateProducesEmptyField(): void
    {
        $fields = $this->decode($this->payment()->generateString());

        self::assertSame('', $fields[5]);
        self::assertCount(19, $fields);
    }

    #[DataProvider('dateTypes')]
    public function testDueDateUsesYmdFormat(string $dateClass): void
    {
        $payment = $this->payment()->setDueDate(new $dateClass('2026-02-03 14:15:16'));

        self::assertSame('20260203', $this->decode($payment->generateString())[5]);
    }

    public static function dateTypes(): array
    {
        return [
            'mutable' => [\DateTime::class],
            'immutable' => [\DateTimeImmutable::class],
        ];
    }

    public function testEncodedPaymentPreservesAllFields(): void
    {
        $payment = $this->payment()
            ->setIban('sk31 1200 0000 1987 4263 7541')
            ->setSwift(' tatrskbx ')
            ->setAmount(123.456)
            ->setCurrency(' eur ')
            ->setDueDate(new \DateTimeImmutable('2026-12-05'))
            ->setVariableSymbol('0012345678')
            ->setConstantSymbol('0308')
            ->setSpecificSymbol('000123')
            ->setNote('Faktúra za služby')
            ->setRecipient(' Ján Novák ')
            ->setRecipientAddressLine1(' Hlavná 12 ')
            ->setRecipientAddressLine2(' 811 01 Bratislava ');

        self::assertSame([
            '', '1', '1', '123.46', 'EUR', '20261205', '0012345678',
            '0308', '000123', '', 'Faktúra za služby',
            '1', 'SK3112000000198742637541', 'TATRSKBX', '0', '0',
            'Ján Novák', 'Hlavná 12', '811 01 Bratislava',
        ], $this->decode($payment->generateString()));
    }

    public function testMissingRecipientAddressKeepsBothEmptyPositions(): void
    {
        $fields = $this->decode($this->payment()->setRecipient('Ján Novák')->generateString());

        self::assertCount(19, $fields);
        self::assertSame(['Ján Novák', '', ''], array_slice($fields, 16));
    }

    public function testTabsInValuesBecomeSpacesWithoutShiftingFields(): void
    {
        $payment = $this->payment()
            ->setPaymentReference("RF\t123")
            ->setNote("Faktúra\tza služby")
            ->setRecipient("Ján\tNovák")
            ->setRecipientAddressLine1("Hlavná\t12")
            ->setRecipientAddressLine2("Bratislava\tSK");

        $fields = $this->decode($payment->generateString());
        self::assertCount(19, $fields);
        self::assertSame('RF 123', $fields[9]);
        self::assertSame('Faktúra za služby', $fields[10]);
        self::assertSame(['Ján Novák', 'Hlavná 12', 'Bratislava SK'], array_slice($fields, 16));
    }

    public function testAmountCanBeOmitted(): void
    {
        $payment = (new Generator())->setIban('SK3112000000198742637541');

        self::assertNull($payment->getAmount());
        self::assertSame('', $this->decode($payment->generateString())[3]);
        self::assertSame('', $this->decode($this->payment()->setAmount(null)->generateString())[3]);
    }

    public function testMaximumFormattedAmountFitsFifteenCharacters(): void
    {
        $fields = $this->decode($this->payment()->setAmount(999999999999.99)->generateString());

        self::assertSame('999999999999.99', $fields[3]);
    }

    public function testValidForeignIbanIsAccepted(): void
    {
        $fields = $this->decode($this->payment()->setIban('DE89370400440532013000')->generateString());

        self::assertSame('DE89370400440532013000', $fields[12]);
    }

    public function testReferenceCanBeUsedWithoutPaymentSymbols(): void
    {
        $fields = $this->decode($this->payment()->setPaymentReference('RF18539007547034')->generateString());

        self::assertSame(['', '', '', 'RF18539007547034'], array_slice($fields, 6, 4));
    }

    public function testElevenCharacterBicAndUnicodeBeneficiaryLimitsAreAccepted(): void
    {
        $fields = $this->decode($this->payment()
            ->setSwift('TATRSKBX123')
            ->setRecipient(str_repeat('Ž', 70))
            ->setRecipientAddressLine1(str_repeat('á', 70))
            ->setRecipientAddressLine2(str_repeat('č', 70))
            ->generateString());

        self::assertSame('TATRSKBX123', $fields[13]);
        self::assertSame(str_repeat('Ž', 70), $fields[16]);
        self::assertSame(str_repeat('á', 70), $fields[17]);
        self::assertSame(str_repeat('č', 70), $fields[18]);
    }

    #[DataProvider('invalidPayments')]
    public function testInvalidPaymentIsRejected(string $setter, string|float $value, string $message): void
    {
        $payment = $this->payment();
        $payment->$setter($value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        $payment->generateString();
    }

    public static function invalidPayments(): array
    {
        return [
            'missing IBAN' => ['setIban', '', 'IBAN je povinný'],
            'blank IBAN' => ['setIban', '   ', 'IBAN je povinný'],
            'IBAN format' => ['setIban', 'SK31-1200-0000-1987-4263-7541', 'IBAN musí mať platný formát'],
            'IBAN checksum' => ['setIban', 'SK3212000000198742637541', 'kontrolný súčet'],
            'IBAN Slovak length' => ['setIban', 'SK311200000019874263754', 'IBAN musí mať platný formát'],
            'BIC length' => ['setSwift', 'TATRSKB', 'BIC/SWIFT musí mať 8 alebo 11'],
            'BIC format' => ['setSwift', 'TATR1KBX', 'BIC/SWIFT musí mať 8 alebo 11'],
            'currency format' => ['setCurrency', 'EU1', 'Mena musí mať 3 písmená A-Z'],
            'zero amount' => ['setAmount', 0.0, 'Suma musí byť väčšia ako 0'],
            'negative amount' => ['setAmount', -0.01, 'Suma musí byť väčšia ako 0'],
            'amount rounded to zero' => ['setAmount', 0.004, 'Suma musí byť väčšia ako 0'],
            'infinite amount' => ['setAmount', INF, 'Suma musí byť väčšia ako 0'],
            'NaN amount' => ['setAmount', NAN, 'Suma musí byť väčšia ako 0'],
            'amount length' => ['setAmount', 1000000000000.0, 'Suma môže mať maximálne 15 znakov'],
            'long reference' => ['setPaymentReference', str_repeat('R', 36), 'Referencia platiteľa môže mať maximálne 35 znakov'],
            'long note' => ['setNote', str_repeat('N', 141), 'Poznámka môže mať maximálne 140 znakov'],
            'long unicode note' => ['setNote', str_repeat('á', 141), 'Poznámka môže mať maximálne 140 znakov'],
            'invalid UTF-8 note' => ['setNote', "\xFF", 'Poznámka musí byť platný UTF-8 text'],
            'long recipient' => ['setRecipient', str_repeat('Ž', 71), 'Príjemca môže mať maximálne 70 znakov'],
            'long address 1' => ['setRecipientAddressLine1', str_repeat('á', 71), 'Adresa príjemcu, riadok 1 môže mať maximálne 70 znakov'],
            'long address 2' => ['setRecipientAddressLine2', str_repeat('č', 71), 'Adresa príjemcu, riadok 2 môže mať maximálne 70 znakov'],
            'non-numeric VS' => ['setVariableSymbol', '123A', 'Variabilný symbol môže obsahovať len číslice'],
            'long VS' => ['setVariableSymbol', '12345678901', 'Variabilný symbol môže mať maximálne 10 číslic'],
            'long KS' => ['setConstantSymbol', '12345', 'Konštantný symbol môže mať maximálne 4 znaky'],
            'non-numeric KS' => ['setConstantSymbol', '12A4', 'Konštantný symbol môže obsahovať len číslice'],
            'long SS' => ['setSpecificSymbol', '12345678901', 'Špecifický symbol môže mať maximálne 10 číslic'],
            'non-numeric SS' => ['setSpecificSymbol', '12A4', 'Špecifický symbol môže obsahovať len číslice'],
        ];
    }

    public function testValuesAtValidationLimitsAreAccepted(): void
    {
        $payment = $this->payment()
            ->setAmount(0.01)
            ->setVariableSymbol('1234567890')
            ->setConstantSymbol('1234')
            ->setNote(str_repeat('á', 140));

        $fields = $this->decode($payment->generateString());
        self::assertSame('0.01', $fields[3]);
        self::assertSame('1234567890', $fields[6]);
        self::assertSame('1234', $fields[7]);
        self::assertSame('', $fields[9]);
        self::assertSame(str_repeat('á', 140), $fields[10]);
    }

    public function testReferenceAndSymbolsCannotBeCombined(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Referencia platiteľa sa nemôže kombinovať');

        $this->payment()->setVariableSymbol('123')->setPaymentReference('RF123')->generateString();
    }

    #[DataProvider('styles')]
    public function testEveryStyleProducesReadablePng(QrStyle $style, int $width, int $height): void
    {
        $this->assertPng($this->payment()->setStyle($style)->getPngBytes(300), $width, $height);
    }

    public static function styles(): array
    {
        return [
            'default' => [QrStyle::Default, 300, 300],
            'transparent' => [QrStyle::Transparent, 300, 300],
            'branded' => [QrStyle::PayBySquare, 342, 384],
            'branded transparent' => [QrStyle::PayBySquareTransparent, 342, 384],
        ];
    }

    public function testDataUriContainsBase64EncodedPng(): void
    {
        $uri = $this->payment()->getDataUri(240);
        $prefix = 'data:image/png;base64,';
        self::assertStringStartsWith($prefix, $uri);
        $png = base64_decode(substr($uri, strlen($prefix)), true);
        self::assertNotFalse($png);
        $this->assertPng($png, 240, 240);
    }

    public function testSaveToFileWritesReadablePng(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pay-by-square-test-');
        self::assertNotFalse($path);

        try {
            $this->payment()->saveToFile($path, 240);
            $png = file_get_contents($path);
            self::assertNotFalse($png);
            $this->assertPng($png, 240, 240);
        } finally {
            unlink($path);
        }
    }

    private function assertPng(string $png, int $width, int $height): void
    {
        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
        self::assertSame($width, $info[0]);
        self::assertSame($height, $info[1]);
        $image = imagecreatefromstring($png);
        self::assertInstanceOf(\GdImage::class, $image);
    }

    /** Decode the public output independently; never call private encoder methods. */
    private function decode(string $encoded): array
    {
        self::assertMatchesRegularExpression('/^[0-9A-V]+$/D', $encoded);
        $bytes = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($encoded) as $character) {
            $buffer = ($buffer << 5) | intval($character, 32);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        self::assertSame(0, $buffer, 'Base32 padding must contain only zero bits.');
        self::assertSame("\x00\x00", substr($bytes, 0, 2));
        $length = unpack('vlength', substr($bytes, 2, 2))['length'];

        $xz = null;
        foreach (['/usr/bin/xz', '/usr/local/bin/xz', '/opt/homebrew/bin/xz', '/opt/homebrew/opt/xz/bin/xz'] as $candidate) {
            if (is_executable($candidate)) {
                $xz = $candidate;
                break;
            }
        }
        self::assertNotNull($xz, 'Integration tests require xz on a standard path.');
        $process = proc_open(
            [$xz, '--decompress', '--format=raw', '--lzma1=lc=3,lp=0,pb=2,dict=128KiB', '-c', '-'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], substr($bytes, 4));
        fclose($pipes[0]);
        $payload = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertNotFalse($payload);
        self::assertSame($length, strlen($payload), 'Header must describe the uncompressed payload length.');

        $data = substr($payload, 4);
        self::assertSame(pack('V', crc32($data)), substr($payload, 0, 4), 'CRC32 must match payment data.');

        return explode("\t", $data);
    }
}
