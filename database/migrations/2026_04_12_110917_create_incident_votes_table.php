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
        Schema::create('incident_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('incident_id');
            $table->string('type'); // 'up' or 'down'
            $table->timestamps();

            // Setup foreign keys manually in case references differ slightly or for scaling
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('cascade');

            // Unique composite index ensures 1 user = 1 vote per incident
            $table->unique(['user_id', 'incident_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_votes');
    }
};
