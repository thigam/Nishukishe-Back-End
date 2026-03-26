<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->foreignId('ticket_tier_id')->nullable()->constrained('ticket_tiers');
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('qr_code')->unique();
            $table->string('status')->default('issued');
            $table->string('passenger_name')->nullable();
            $table->string('passenger_email')->nullable();
            $table->json('passenger_metadata')->nullable();
            $table->decimal('price_paid', 12, 2)->default(0);
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['bookable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
