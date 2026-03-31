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
        Schema::create('custom_station_polygons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('polygon'); // Array of [lat, lng]
            $table->string('station_id')->nullable(); // Optional fixed station ID
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_station_polygons');
    }
};
