<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('KES');
            $table->decimal('price', 10, 2);
            $table->decimal('service_fee_rate', 8, 5)->nullable();
            $table->decimal('service_fee_flat', 10, 2)->nullable();
            $table->unsignedInteger('total_quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->unsignedInteger('min_per_order')->default(1);
            $table->unsignedInteger('max_per_order')->nullable();
            $table->timestamp('sales_start')->nullable();
            $table->timestamp('sales_end')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['bookable_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tiers');
    }
};
