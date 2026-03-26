<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pre_clean_sacco_routes
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('pre_clean_sacco_routes', 'route_fare')) {
                $table->dropColumn('route_fare');
            }
            if (!Schema::hasColumn('pre_clean_sacco_routes', 'peak_fare')) {
                $table->integer('peak_fare')->default(100);
            }
            if (!Schema::hasColumn('pre_clean_sacco_routes', 'off_peak_fare')) {
                $table->integer('off_peak_fare')->default(100);
            }
        });

        // post_clean_sacco_routes
        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('post_clean_sacco_routes', 'route_fare')) {
                $table->dropColumn('route_fare');
            }
            if (!Schema::hasColumn('post_clean_sacco_routes', 'peak_fare')) {
                $table->integer('peak_fare')->default(100);
            }
            if (!Schema::hasColumn('post_clean_sacco_routes', 'off_peak_fare')) {
                $table->integer('off_peak_fare')->default(100);
            }
        });

        // sacco_routes
        Schema::table('sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('sacco_routes', 'route_fare')) {
                $table->dropColumn('route_fare');
            }
            if (!Schema::hasColumn('sacco_routes', 'peak_fare')) {
                $table->integer('peak_fare')->default(100);
            }
            if (!Schema::hasColumn('sacco_routes', 'off_peak_fare')) {
                $table->integer('off_peak_fare')->default(100);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('pre_clean_sacco_routes', 'peak_fare')) {
                $table->dropColumn('peak_fare');
            }
            if (Schema::hasColumn('pre_clean_sacco_routes', 'off_peak_fare')) {
                $table->dropColumn('off_peak_fare');
            }
            if (!Schema::hasColumn('pre_clean_sacco_routes', 'route_fare')) {
                $table->integer('route_fare')->nullable();
            }
        });

        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('post_clean_sacco_routes', 'peak_fare')) {
                $table->dropColumn('peak_fare');
            }
            if (Schema::hasColumn('post_clean_sacco_routes', 'off_peak_fare')) {
                $table->dropColumn('off_peak_fare');
            }
            if (!Schema::hasColumn('post_clean_sacco_routes', 'route_fare')) {
                $table->integer('route_fare')->nullable();
            }
        });

        Schema::table('sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('sacco_routes', 'peak_fare')) {
                $table->dropColumn('peak_fare');
            }
            if (Schema::hasColumn('sacco_routes', 'off_peak_fare')) {
                $table->dropColumn('off_peak_fare');
            }
            if (!Schema::hasColumn('sacco_routes', 'route_fare')) {
                $table->integer('route_fare')->nullable();
            }
        });
    }
};
