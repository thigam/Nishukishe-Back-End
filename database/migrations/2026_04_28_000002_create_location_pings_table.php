<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            // Intentionally NO user_id — this table is fully anonymous by design.
            // device_id is a client-generated UUID stored in the app's local storage.
            $table->string('device_id', 64)->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // Accuracy in metres — lets us filter out poor-quality pings for heatmaps
            $table->unsignedSmallInteger('accuracy_meters')->nullable();
            // Speed in km/h — useful for traffic inference (stopped vs moving)
            $table->unsignedSmallInteger('speed_kmh')->nullable();
            // When the position was recorded on-device (may differ from server received_at)
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            // Spatial index for geofence & heatmap queries
            $table->index(['lat', 'lng']);
            // Time-series index for analytics
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pings');
    }
};
