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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('sacco_id');
            $table->string('registration_number');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route_id');
            $table->timestamps();

            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
            $table->foreign('route_id')->references('route_id')->on('routes')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
