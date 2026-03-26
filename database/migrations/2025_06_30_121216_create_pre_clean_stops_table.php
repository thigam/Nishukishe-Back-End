<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_clean_stops', function (Blueprint $table) {
            $table->id();
            $table->string('stop_name');
            $table->decimal('stop_lat', 10, 7);
            $table->decimal('stop_long', 10, 7);
            $table->string('county_id')->nullable();
            $table->string('direction_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_clean_stops');
    }
};
