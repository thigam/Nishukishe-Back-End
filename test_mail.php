<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Find the latest booking
    $booking = App\Models\Booking::latest()->first();

    echo "Pushing TicketReceiptMail to queue for Booking ID: " . $booking->id . "\n";

    // Explicitly dispatch to queue
    Illuminate\Support\Facades\Mail::to('test@example.com')->send(new \App\Mail\TicketReceiptMail($booking));

    echo "Successfully queued TicketReceiptMail\n";
} catch (\Throwable $e) {
    echo "EXCEPTION THROWN:\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
