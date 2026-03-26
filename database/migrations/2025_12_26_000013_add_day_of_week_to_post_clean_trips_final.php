<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        if (! Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->json('day_of_week')->nullable()->after('trip_times');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('post_clean_trips')) {
            return;
        }

        if (Schema::hasColumn('post_clean_trips', 'day_of_week')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->dropColumn('day_of_week');
            });
        }
    }
};

