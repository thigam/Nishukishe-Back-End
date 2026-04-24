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
        Schema::create('suggested_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Selected IDs (strings from existing tables)
            $table->string('start_stop_id')->nullable();
            $table->string('end_stop_id')->nullable();
            $table->string('sacco_id')->nullable();

            // Manual entries if not in dropdown
            $table->string('start_stop_manual')->nullable();
            $table->string('end_stop_manual')->nullable();
            $table->string('sacco_manual')->nullable();

            $table->text('details')->nullable();
            $table->string('status')->default('pending'); // pending, done
            $table->timestamps();

            $table->foreign('start_stop_id')->references('stop_id')->on('stops')->nullOnDelete();
            $table->foreign('end_stop_id')->references('stop_id')->on('stops')->nullOnDelete();
            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_routes');
    }
};
