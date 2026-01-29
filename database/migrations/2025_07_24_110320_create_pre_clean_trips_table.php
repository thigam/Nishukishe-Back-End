<?php


// database/migrations/xxxx_xx_xx_create_pre_clean_trips_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pre_clean_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pre_clean_sacco_route_id');
            $table->json('stop_times'); // array of { stop_id, time }
            $table->json('day_of_week')->nullable(); // e.g. ['mon', 'wed']
            $table->timestamps();

            $table->foreign('pre_clean_sacco_route_id')
                ->references('id')
                ->on('pre_clean_sacco_routes')
                ->onDelete('cascade');
        });
    }

    public function down(): void {
        Schema::dropIfExists('pre_clean_trips');
    }
};

