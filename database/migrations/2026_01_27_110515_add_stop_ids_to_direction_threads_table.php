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
        Schema::table('direction_threads', function (Blueprint $table) {
            $table->string('origin_stop_id')->nullable()->after('destination_slug');
            $table->string('destination_stop_id')->nullable()->after('origin_stop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direction_threads', function (Blueprint $table) {
            $table->dropColumn(['origin_stop_id', 'destination_stop_id']);
        });
    }
};
