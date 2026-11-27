<?php

declare(strict_types=1);
namespace Engazan\PayBySquare\Lzma;
use Engazan\PayBySquare\Lzma\RangeEncoder;

/**
 * Minimal raw LZMA1 encoder for Pay By Square payloads.
 *
 * Emits a literal-only stream (no match finding) terminated by the
 * end-of-stream marker, using the fixed Pay By Square parameters
 * lc=3, lp=0, pb=2. Any conforming LZMA decoder accepts the output;
 * see spec/SPEC.md section 4 for why validity, not byte-equality with
 * liblzma, is the contract. Port of packages/core/src/lzma/encoder.ts.
 */
final class Lzma1Encoder
{
    private const LC = 3;
    private const POS_STATE_MASK = 0b11; // pb = 2
    private const NUM_STATES = 12;
    private const NUM_POS_SLOTS = 64;
    private const ALIGN_BITS = 4;
    private const END_POS_MODEL_INDEX = 14;
    private const EOS_DISTANCE = 0xFFFFFFFF;
    private const INIT_PROB = 1024;

    public static function compress(string $data): string
    {
        $rc = new RangeEncoder();

        $isMatch = self::newProbs(self::NUM_STATES << 4);
        $isRep = self::newProbs(self::NUM_STATES);
        $posSlot = self::newProbs(4 * self::NUM_POS_SLOTS);
        $align = self::newProbs(1 << self::ALIGN_BITS);
        $lenChoice = self::newProbs(2);
        $lenLow = self::newProbs(16 * 8);
        $literals = self::newProbs((1 << self::LC) * 0x300);

        // Only literals are emitted, so the LZMA state machine stays in
        // state 0 throughout.
        $state = 0;
        $prevByte = 0;
        $length = strlen($data);

        for ($pos = 0; $pos < $length; $pos++) {
            $posState = $pos & self::POS_STATE_MASK;
            $rc->encodeBit($isMatch, ($state << 4) + $posState, 0);

            $symbol = ord($data[$pos]);
            $litBase = ($prevByte >> (8 - self::LC)) * 0x300;
            $ctx = 1;
            for ($i = 7; $i >= 0; $i--) {
                $bit = ($symbol >> $i) & 1;
                $rc->encodeBit($literals, $litBase + $ctx, $bit);
                $ctx = ($ctx << 1) | $bit;
            }
            $prevByte = $symbol;
        }

        // End-of-stream marker: a simple match of minimum length (2) with
        // the reserved distance 0xFFFFFFFF.
        $posState = $length & self::POS_STATE_MASK;
        $rc->encodeBit($isMatch, ($state << 4) + $posState, 1);
        $rc->encodeBit($isRep, $state, 0);

        // Match length 2 → choice bit 0, low coder symbol 0.
        $rc->encodeBit($lenChoice, 0, 0);
        self::bitTreeEncode($rc, $lenLow, $posState * 8, 3, 0);

        // Distance 0xFFFFFFFF → pos slot 63.
        $slot = 63;
        self::bitTreeEncode($rc, $posSlot, 0 * self::NUM_POS_SLOTS, 6, $slot);

        $footerBits = ($slot >> 1) - 1;             // 30
        $base = (2 | ($slot & 1)) << $footerBits;   // 0xC0000000
        $reduced = self::EOS_DISTANCE - $base;      // 0x3FFFFFFF
        if ($slot >= self::END_POS_MODEL_INDEX) {
            $rc->encodeDirectBits($reduced >> self::ALIGN_BITS, $footerBits - self::ALIGN_BITS);
            self::reverseBitTreeEncode($rc, $align, 0, self::ALIGN_BITS, $reduced & ((1 << self::ALIGN_BITS) - 1));
        }

        return $rc->flush();
    }

    /** @return array<int, int> */
    private static function newProbs(int $size): array
    {
        return array_fill(0, $size, self::INIT_PROB);
    }

    /** @param array<int, int> $probs */
    private static function bitTreeEncode(
        RangeEncoder $rc,
        array &$probs,
        int $offset,
        int $numBits,
        int $symbol,
    ): void {
        $m = 1;
        for ($i = $numBits - 1; $i >= 0; $i--) {
            $bit = ($symbol >> $i) & 1;
            $rc->encodeBit($probs, $offset + $m, $bit);
            $m = ($m << 1) | $bit;
        }
    }

    /** @param array<int, int> $probs */
    private static function reverseBitTreeEncode(
        RangeEncoder $rc,
        array &$probs,
        int $offset,
        int $numBits,
        int $symbol,
    ): void {
        $m = 1;
        for ($i = 0; $i < $numBits; $i++) {
            $bit = $symbol & 1;
            $symbol >>= 1;
            $rc->encodeBit($probs, $offset + $m, $bit);
            $m = ($m << 1) | $bit;
        }
    }
}
