<?php

declare(strict_types=1);

namespace Engazan\PayBySquare\Lzma;

/**
 * LZMA range encoder (binary arithmetic coder with adaptive 11-bit models).
 * Port of packages/core/src/lzma/encoder.ts; PHP 64-bit ints make the
 * 32-bit + carry arithmetic straightforward.
 */
final class RangeEncoder
{
    private const K_TOP = 1 << 24;
    private const BIT_MODEL_TOTAL = 2048;
    private const MOVE_BITS = 5;

    private int $low = 0;
    private int $range = 0xFFFFFFFF;
    private int $cache = 0;
    private int $cacheSize = 1;
    /** @var list<int> */
    private array $out = [];

    /** @param array<int, int> $probs */
    public function encodeBit(array &$probs, int $index, int $bit): void
    {
        $prob = $probs[$index];
        $bound = ($this->range >> 11) * $prob;
        if ($bit === 0) {
            $this->range = $bound;
            $probs[$index] = $prob + ((self::BIT_MODEL_TOTAL - $prob) >> self::MOVE_BITS);
        } else {
            $this->low += $bound;
            $this->range -= $bound;
            $probs[$index] = $prob - ($prob >> self::MOVE_BITS);
        }
        while ($this->range < self::K_TOP) {
            $this->range = ($this->range << 8) & 0xFFFFFFFF;
            $this->shiftLow();
        }
    }

    public function encodeDirectBits(int $value, int $numBits): void
    {
        for ($i = $numBits - 1; $i >= 0; $i--) {
            $this->range >>= 1;
            if (($value >> $i) & 1) {
                $this->low += $this->range;
            }
            if ($this->range < self::K_TOP) {
                $this->range = ($this->range << 8) & 0xFFFFFFFF;
                $this->shiftLow();
            }
        }
    }

    public function flush(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $this->shiftLow();
        }
        return pack('C*', ...$this->out);
    }

    private function shiftLow(): void
    {
        $carry = $this->low >> 32;
        if ($carry !== 0 || $this->low < 0xFF000000) {
            $temp = $this->cache;
            do {
                $this->out[] = ($temp + $carry) & 0xFF;
                $temp = 0xFF;
            } while (--$this->cacheSize !== 0);
            $this->cache = ($this->low >> 24) & 0xFF;
        }
        $this->cacheSize++;
        $this->low = ($this->low & 0x00FFFFFF) << 8;
    }
}
