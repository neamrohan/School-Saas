<?php

namespace App\Services;

use InvalidArgumentException;

class MoneyCalculator
{
    public function toCents(int|float|string $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Money must be a non-negative decimal with up to two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function sum(iterable $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $this->toCents($amount);
        }

        return $total;
    }
}