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
        Schema::table('search_logs', function (Blueprint $table) {
            $table->decimal('origin_lat', 10, 8)->nullable();
            $table->decimal('origin_lng', 11, 8)->nullable();
            $table->decimal('destination_lat', 10, 8)->nullable();
            $table->decimal('destination_lng', 11, 8)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropColumn(['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng']);
        });
    }
};
