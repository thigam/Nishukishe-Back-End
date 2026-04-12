<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parcel_events', function (Blueprint $table) {
            // Drop the vague string columns added earlier
            if (Schema::hasColumn('parcel_events', 'hub_name')) {
                $table->dropColumn('hub_name');
            }
            if (Schema::hasColumn('parcel_events', 'vehicle_registration')) {
                $table->dropColumn('vehicle_registration');
            }
            // Add concrete FK and vehicle text field
            $table->foreignId('depot_id')->nullable()->constrained('parcel_depots')->nullOnDelete();
            $table->string('vehicle_registration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_events', function (Blueprint $table) {
            $table->dropForeign(['depot_id']);
            $table->dropColumn(['depot_id', 'vehicle_registration']);
            $table->string('hub_name')->nullable();
        });
    }
};
