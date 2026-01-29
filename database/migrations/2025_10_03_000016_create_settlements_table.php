<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->foreignId('payout_profile_id')->nullable()->constrained('payout_profiles');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('settled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['bookable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
