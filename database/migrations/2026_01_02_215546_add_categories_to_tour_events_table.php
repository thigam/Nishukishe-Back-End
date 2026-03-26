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
        Schema::table('tour_events', function (Blueprint $table) {
            $table->json('categories')->nullable()->after('meeting_point');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_events', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }
};
