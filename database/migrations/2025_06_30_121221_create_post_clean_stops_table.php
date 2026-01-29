<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_clean_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pre_clean_id');
            $table->string('stop_id');
            $table->string('stop_name');
            $table->decimal('stop_lat', 10, 7);
            $table->decimal('stop_long', 10, 7);
            $table->string('county_id')->nullable();
            $table->string('direction_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_clean_stops');
    }
};
