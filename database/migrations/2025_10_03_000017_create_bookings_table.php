<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('currency', 3)->default('KES');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('service_fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained('settlements');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['bookable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
