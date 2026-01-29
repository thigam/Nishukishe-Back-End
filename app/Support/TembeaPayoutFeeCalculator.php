<?php

namespace App\Support;

class TembeaPayoutFeeCalculator
{
    public static function estimate(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $fee = $amount * 0.02;

        return round(max($fee, 10), 2);
    }
}
