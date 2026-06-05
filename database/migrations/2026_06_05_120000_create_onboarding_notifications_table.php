<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('onboarding_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_token_id')->constrained('device_tokens')->cascadeOnDelete();
            $table->string('tip_key', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('clicked_at')->nullable();

            $table->index(['device_token_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_notifications');
    }
};
