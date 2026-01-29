<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sacco_safari_instances', function (Blueprint $table) {
            $table->id();

            // bookables.id is BIGINT - OK
            $table->foreignId('bookable_id')->unique()->constrained('bookables')->cascadeOnDelete();

            // === sacco_id: match saccos.sacco_id (assumed varchar(255) utf8mb4_unicode_ci) ===
            $saccoId = $table->string('sacco_id', 255);
            if (DB::getDriverName() === 'mysql') {
                $saccoId->collation('utf8mb4_unicode_ci');
            }
            $table->foreign('sacco_id')
                ->references('sacco_id')
                ->on('saccos')
                ->cascadeOnDelete();

            // === sacco_route_id: match sacco_routes.sacco_route_id (varchar(255) utf8mb4_unicode_ci) ===
            $saccoRouteId = $table->string('sacco_route_id', 255)->nullable();
            if (DB::getDriverName() === 'mysql') {
                $saccoRouteId->collation('utf8mb4_unicode_ci');
            }
            $table->foreign('sacco_route_id')
                ->references('sacco_route_id')
                ->on('sacco_routes')
                ->nullOnDelete();

            // === trip_id: match trips.trip_id (unsigned BIGINT) ===
            $table->unsignedBigInteger('trip_id')->nullable();
            $table->foreign('trip_id')
                ->references('trip_id')
                ->on('trips')
                ->nullOnDelete();

            // vehicle_id: keep as foreignId ONLY IF vehicles has default BIGINT id.
            // If vehicles uses a custom PK (e.g., vehicle_id string), change similarly.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');

            $table->timestamp('departure_time');
            $table->timestamp('arrival_time')->nullable();
            $table->unsignedInteger('inventory')->default(0);
            $table->unsignedInteger('available_seats')->default(0);
            $table->json('seat_map')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sacco_id', 'departure_time']);
        });


    }

    public function down(): void
    {

        Schema::dropIfExists('sacco_safari_instances');
    }
};
