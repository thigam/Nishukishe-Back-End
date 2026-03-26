<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_clean_sacco_routes', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_id');
            $table->string('route_id');
            $table->string('route_start_stop');
            $table->string('route_end_stop');
            $table->integer('direction_index')->nullable()->default(1);
            $table->json('coordinates')->nullable();
            $table->json('stop_ids')->nullable();
            $table->json('route_stop_times')->nullable();
            $table->decimal('route_fare', 8, 2)->default(100);
            $table->unsignedBigInteger('county_id')->nullable();
            $table->string('mode')->nullable();
            $table->integer('waiting_time')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_clean_sacco_routes');
    }
};
