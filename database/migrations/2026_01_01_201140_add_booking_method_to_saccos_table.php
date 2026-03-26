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
        Schema::table('saccos', function (Blueprint $table) {
            $table->enum('booking_method', ['none', 'internal', 'redirect', 'api_buupass', 'api_other'])
                ->default('none')
                ->after('sacco_website');
            $table->string('external_booking_url')->nullable()->after('booking_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            $table->dropColumn(['booking_method', 'external_booking_url']);
        });
    }
};
