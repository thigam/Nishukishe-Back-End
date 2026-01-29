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
        Schema::create('ticket_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_tier_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->integer('quantity');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_holds');
    }
};
