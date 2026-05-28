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
        Schema::create('reported_wrong_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('search_start')->nullable();
            $table->string('search_end')->nullable();
            
            $table->json('legs')->nullable(); // Contextual information: all legs in the chosen route option
            $table->json('selected_legs')->nullable(); // Selected leg indices reported as wrong
            $table->json('error_options')->nullable(); // Selected error types e.g., ["wrong fare", "other"]
            $table->text('details')->nullable();
            
            $table->string('status')->default('pending'); // pending, resolved
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reported_wrong_info');
    }
};
