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
            if (!Schema::hasColumn('search_logs', 'source')) {
                $table->string('source')->nullable()->after('id');
            }
            if (!Schema::hasColumn('search_logs', 'origin_slug')) {
                $table->string('origin_slug')->nullable()->after('source');
            }
            if (!Schema::hasColumn('search_logs', 'destination_slug')) {
                $table->string('destination_slug')->nullable()->after('origin_slug');
            }
            if (!Schema::hasColumn('search_logs', 'has_result')) {
                $table->boolean('has_result')->default(false)->after('destination_slug');
            }
            if (!Schema::hasColumn('search_logs', 'query')) {
                $table->json('query')->nullable()->after('has_result');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropColumn(['source', 'origin_slug', 'destination_slug', 'has_result', 'query']);
        });
    }
};
