<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->foreignId('origin_depot_id')->nullable()->constrained('parcel_depots')->nullOnDelete()->after('sacco_id');
            $table->foreignId('destination_depot_id')->nullable()->constrained('parcel_depots')->nullOnDelete()->after('origin_depot_id');
            $table->foreignId('current_depot_id')->nullable()->constrained('parcel_depots')->nullOnDelete()->after('destination_depot_id');
        });
    }

    public function down(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->dropForeign(['origin_depot_id']);
            $table->dropForeign(['destination_depot_id']);
            $table->dropForeign(['current_depot_id']);
            $table->dropColumn(['origin_depot_id', 'destination_depot_id', 'current_depot_id']);
        });
    }
};
