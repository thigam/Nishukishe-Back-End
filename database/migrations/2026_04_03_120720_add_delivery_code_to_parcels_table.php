<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->string('delivery_code')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->dropColumn('delivery_code');
        });
    }
};
