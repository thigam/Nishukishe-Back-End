<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Grab the secret key directly to bypass Laravel abstractions to show raw error logs
$secretKey = env('PAYSTACK_SECRET_KEY');
if (!$secretKey) {
    die("No secret key found in .env\n");
}

$payload = [
    'email' => 'jilhashtama@gmail.com',
    'amount' => 10000,
    'currency' => 'KES',
    'mobile_money' => [
        'phone' => '+254710775313',
        'provider' => 'mpesa'
    ],
    // 'reference' => 'TEST_STK_' . bin2hex(random_bytes(6)) // omit to let Paystack generate
];

echo "Sending payload: " . json_encode($payload) . "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/charge");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $secretKey,
    "Content-Type: application/json"
]);

$result = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "\nHTTP STATUS: " . $info['http_code'] . "\n";
echo "RESPONSE BODY: \n";
echo json_encode(json_decode($result), JSON_PRETTY_PRINT) . "\n";
