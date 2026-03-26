<?php
// database/migrations/2025_08_12_000001_add_trip_index_unique_to_trips.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('trips', function (Blueprint $table) {
            if (!Schema::hasColumn('trips', 'trip_index')) {
                $table->char('trip_index', 3)->nullable()->after('sacco_route_id');
            }
            // unique per sacco_route
            $table->unique(['sacco_route_id', 'trip_index'], 'trips_srid_idx_unique');
        });
    }
    public function down(): void {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique('trips_srid_idx_unique');
            $table->dropColumn('trip_index');
        });
    }
};

