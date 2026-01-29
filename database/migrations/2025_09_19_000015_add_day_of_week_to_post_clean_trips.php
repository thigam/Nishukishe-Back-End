<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
	            // If the table doesn't exist (e.g. in test DB), do nothing.
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        // If the column already exists, don't try to add it again.
        if (Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            return;
        }
        if (! Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->json('day_of_week')->nullable()->after('trip_times');
            });
        }

        if (Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            DB::table('post_clean_trips')
                ->whereNull('day_of_week')
                ->update(['day_of_week' => json_encode([])]);
        }
    }

    public function down(): void
    {
	            // Same defensive checks for rolling back
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        if (! Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            return;
        }
        if (Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->dropColumn('day_of_week');
            });
        }
    }
};
