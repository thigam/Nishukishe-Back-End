<?php

namespace App\Services;

use InvalidArgumentException;

class MpesaCostService
{
    private array $rates = [
        [
            'min' => 1, 'max' => 49,
            'transfer_to_mpesa' => 0,
            'transfer_to_other' => 0,
            'withdraw_from_mpesa' => null,
            'receiving_to_till_min' => 0,
            'receiving_to_till_max' => 0,
        ],
        [
            'min' => 50, 'max' => 100,
            'transfer_to_mpesa' => 0,
            'transfer_to_other' => 0,
            'withdraw_from_mpesa' => 11,
            'receiving_to_till_min' => 0,
            'receiving_to_till_max' => 0,
        ],
        [
            'min' => 101, 'max' => 500,
            'transfer_to_mpesa' => 7,
            'transfer_to_other' => 7,
            'withdraw_from_mpesa' => 29,
            'receiving_to_till_min' => 0,
            'receiving_to_till_max' => 2.5,
        ],
        [
            'min' => 501, 'max' => 1000,
            'transfer_to_mpesa' => 13,
            'transfer_to_other' => 13,
            'withdraw_from_mpesa' => 29,
            'receiving_to_till_min' => 2.5,
            'receiving_to_till_max' => 5,
        ],
        [
            'min' => 1001, 'max' => 1500,
            'transfer_to_mpesa' => 23,
            'transfer_to_other' => 23,
            'withdraw_from_mpesa' => 29,
            'receiving_to_till_min' => 5,
            'receiving_to_till_max' => 7.5,
        ],
        [
            'min' => 1501, 'max' => 2500,
            'transfer_to_mpesa' => 33,
            'transfer_to_other' => 33,
            'withdraw_from_mpesa' => 29,
            'receiving_to_till_min' => 7.5,
            'receiving_to_till_max' => 12.5,
        ],
        [
            'min' => 2501, 'max' => 3500,
            'transfer_to_mpesa' => 53,
            'transfer_to_other' => 53,
            'withdraw_from_mpesa' => 52,
            'receiving_to_till_min' => 12.5,
            'receiving_to_till_max' => 17.5,
        ],
        [
            'min' => 3501, 'max' => 5000,
            'transfer_to_mpesa' => 57,
            'transfer_to_other' => 57,
            'withdraw_from_mpesa' => 69,
            'receiving_to_till_min' => 17.5,
            'receiving_to_till_max' => 25,
        ],
        [
            'min' => 5001, 'max' => 7500,
            'transfer_to_mpesa' => 78,
            'transfer_to_other' => 78,
            'withdraw_from_mpesa' => 87,
            'receiving_to_till_min' => 25,
            'receiving_to_till_max' => 37.5,
        ],
        [
            'min' => 7501, 'max' => 10000,
            'transfer_to_mpesa' => 90,
            'transfer_to_other' => 90,
            'withdraw_from_mpesa' => 115,
            'receiving_to_till_min' => 37.5,
            'receiving_to_till_max' => 50,
        ],
        [
            'min' => 10001, 'max' => 15000,
            'transfer_to_mpesa' => 100,
            'transfer_to_other' => 100,
            'withdraw_from_mpesa' => 167,
            'receiving_to_till_min' => 50,
            'receiving_to_till_max' => 75,
        ],
        [
            'min' => 15001, 'max' => 20000,
            'transfer_to_mpesa' => 105,
            'transfer_to_other' => 105,
            'withdraw_from_mpesa' => 185,
            'receiving_to_till_min' => 75,
            'receiving_to_till_max' => 100,
        ],
        [
            'min' => 20001, 'max' => 35000,
            'transfer_to_mpesa' => 108,
            'transfer_to_other' => 108,
            'withdraw_from_mpesa' => 197,
            'receiving_to_till_min' => 100,
            'receiving_to_till_max' => 175,
        ],
        [
            'min' => 35001, 'max' => 50000,
            'transfer_to_mpesa' => 108,
            'transfer_to_other' => 108,
            'withdraw_from_mpesa' => 278,
            'receiving_to_till_min' => 175,
            'receiving_to_till_max' => 200,
        ],
        [
            'min' => 50001, 'max' => 250000,
            'transfer_to_mpesa' => 108,
            'transfer_to_other' => 108,
            'withdraw_from_mpesa' => 309,
            'receiving_to_till_min' => 200,
            'receiving_to_till_max' => 200,
        ],
    ];

    public function calculate(float $amount, string $type): float
    {
        $validTypes = [
            'transfer_to_mpesa',
            'transfer_to_other',
            'withdraw_from_mpesa',
            'receiving_to_till',
            'receiving_from_till'
        ];

        if (!in_array($type, $validTypes)) {
            throw new InvalidArgumentException("Invalid transaction type: {$type}");
        }

        foreach ($this->rates as $rate) {
            if ($amount >= $rate['min'] && $amount <= $rate['max']) {
                switch ($type) {
                    case 'receiving_to_till':
                    case 'receiving_from_till': // ✅ added alias
                        return ($rate['receiving_to_till_min'] + $rate['receiving_to_till_max']) / 2;
                    default:
                        return $rate[$type] ?? 0;
                }
            }
        }

        return 0;
    }

    public function customerToTill(float $amount): float
    {
        foreach ($this->rates as $rate) {
            if ($amount >= $rate['min'] && $amount <= $rate['max']) {
                return $rate['receiving_to_till_max'] ?? 0;
            }
        }

        return 0;
    }

    public function getRates(): array
    {
        return $this->rates;
    }
}
