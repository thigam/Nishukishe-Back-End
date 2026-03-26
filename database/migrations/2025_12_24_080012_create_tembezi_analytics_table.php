<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tembezi_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tembezi_id')->constrained('tour_events')->cascadeOnDelete();
            $table->string('event_type'); // 'visit', 'contact_open', 'contact_click'
            $table->json('metadata')->nullable(); // e.g., { "channel": "whatsapp" }
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tembezi_analytics');
    }
};
