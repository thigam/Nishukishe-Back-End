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
        Schema::create('trips', function (Blueprint $table) {
    $table->id('trip_id');
    $table->string('sacco_id');
    $table->string('route_id');
    $table->json('stop_times');
    $table->json('day_of_week');
    $table->time('start_time')->nullable(); // derived from stop_times
    $table->timestamps();

    $table->foreign(['sacco_id', 'route_id'])
          ->references(['sacco_id', 'route_id'])
          ->on('sacco_routes')
          ->onDelete('cascade');
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
