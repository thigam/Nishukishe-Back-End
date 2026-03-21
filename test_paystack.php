<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$paystackService = app(\App\Services\PaystackService::class);

$paymentManager = app(\App\Services\Bookings\PaymentGatewayManager::class);
// Making method public for test or just copying logic
$formattedPhone = $data['mobile_money']['phone'] = '0710775313';
// Mbesa format in payment gateway manager:
// $phone = ltrim($phone, '+');
// if (str_starts_with($phone, '254')) $phone = '0' . substr($phone, 3);
// So it typically passes '07...' to Paystack. Oh wait, Paystack Kenya mobile money requires '07...' or '254...'? 
// The manager code:
// if (str_starts_with($phone, '254')) {
//     return '0' . substr($phone, 3);
// }
// if (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
//    return '0' . $phone;
// }
// So it uses '0710775313'.

$data = [
    'email' => config('services.paystack.merchant_email') ?? 'jilhashtama@gmail.com',
    'amount' => 1000,
    'currency' => 'KES',
    'mobile_money' => [
        'phone' => '254710775313',
        'provider' => 'mpesa'
    ],
    'reference' => 'TEST-' . strtoupper(\Illuminate\Support\Str::random(8)),
];

try {
    echo "Attempting to charge...\n";
    $response = $paystackService->charge($data);
    print_r($response);
} catch (\Exception $e) {
    echo "Exception Caught!\n";
    echo $e->getMessage() . "\n";
}
