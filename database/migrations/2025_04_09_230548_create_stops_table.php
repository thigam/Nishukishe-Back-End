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
        Schema::create('stops', function (Blueprint $table) {
            $table->string('stop_id')->primary();
            $table->string('stop_name');
            $table->decimal('stop_lan', 10, 7);
            $table->decimal('stop_long', 10, 7);
            $table->string('county_id')->nullable();
            $table->string('direction_id')->nullable();

            $table->foreign('county_id')->references('county_id')->on('counties')->onDelete('cascade');
            $table->foreign('direction_id')->references('direction_id')->on('directions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stops');
    }
};
