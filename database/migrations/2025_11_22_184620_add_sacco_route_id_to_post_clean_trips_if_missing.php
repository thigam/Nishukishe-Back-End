<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If table doesn't exist yet (unlikely in prod, possible in some flows), bail.
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        // Only add the column if it's missing (works for MySQL and SQLite).
        if (! Schema::hasColumn('post_clean_trips', 'sacco_route_id')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                // Keep it simple for SQLite: no "after" or collation tricks.
                $table->string('sacco_route_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        if (Schema::hasColumn('post_clean_trips', 'sacco_route_id')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->dropColumn('sacco_route_id');
            });
        }
    }
};

