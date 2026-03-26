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
        Schema::create('stop_times', function (Blueprint $table) {
            $table->string('trip_id')->unique();
            $table->string('route_id');
            $table->string('stop_id');
            $table->time('arrival_time');
            $table->time('departure_time');
            $table->integer('stop_sequence');

            // Foreign key constraints
//            $table->foreign('route_id')->references('route_id')->on('sacco_routes')->onDelete('cascade');
            $table->foreign('stop_id')->references('stop_id')->on('stops')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stop_times');
    }
};
