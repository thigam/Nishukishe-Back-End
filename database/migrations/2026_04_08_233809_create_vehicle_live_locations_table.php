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
        Schema::create('vehicle_live_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->unique()->constrained('vehicles')->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->string('sacco_route_id')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->smallInteger('heading')->default(0);
            $table->decimal('speed_kmh', 5, 2)->default(0);
            $table->enum('location_source', ['driver_app', 'hardware_tracker', 'partner_api'])->default('driver_app');
            $table->boolean('is_active')->default(true);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->foreign('sacco_route_id')->references('sacco_route_id')->on('sacco_routes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_live_locations');
    }
};
