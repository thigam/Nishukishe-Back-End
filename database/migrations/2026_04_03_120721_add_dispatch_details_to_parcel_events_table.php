<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parcel_events', function (Blueprint $table) {
            $table->string('vehicle_registration')->nullable()->after('location');
            $table->string('hub_name')->nullable()->after('vehicle_registration');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_events', function (Blueprint $table) {
            $table->dropColumn(['vehicle_registration', 'hub_name']);
        });
    }
};
