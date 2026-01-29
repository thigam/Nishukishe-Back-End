<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_clean_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pre_clean_id');
            $table->unsignedBigInteger('route_id');
            $table->string('sacco_id'); // UUID or string identifier
            $table->json('trip_times'); // Stores trip times in JSON format
            $table->timestamps();

            // Optional foreign keys (uncomment if related tables exist)
            // $table->foreign('pre_clean_id')->references('id')->on('pre_cleans')->onDelete('cascade');
            // $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_clean_trips');
    }
};
