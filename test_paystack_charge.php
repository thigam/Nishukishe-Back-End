<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$paystackService = app()->make(\App\Services\PaystackService::class);

$data = [
    'email' => 'test@example.com',
    'amount' => 1000,
    'currency' => 'KES',
    'mobile_money' => [
        'phone' => '+254710000000',
        'provider' => 'mpesa'
    ],
    'reference' => 'TEST_REF_' . time(),
];

try {
    $response = $paystackService->charge($data);
    echo "SUCCESS\n";
    print_r($response);
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
