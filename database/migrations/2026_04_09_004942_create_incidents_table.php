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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('cascade');
            $table->enum('type', ['accident', 'traffic', 'delay']);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->text('description')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamps();

            $table->index(['lat', 'lng', 'type', 'is_verified']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
