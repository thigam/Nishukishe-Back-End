<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make peak_fare and off_peak_fare nullable across all route tables.
     * Previously these columns had ->default(100) with no ->nullable(),
     * causing a NOT NULL constraint violation when a service person leaves
     * fares blank (conductor-confirmed fare flow).
     */
    public function up(): void
    {
        // pre_clean_sacco_routes
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable()->default(null)->change();
            $table->integer('off_peak_fare')->nullable()->default(null)->change();
        });

        // post_clean_sacco_routes
        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable()->default(null)->change();
            $table->integer('off_peak_fare')->nullable()->default(null)->change();
        });

        // sacco_routes
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable()->default(null)->change();
            $table->integer('off_peak_fare')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Restore NOT NULL with default 100
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable(false)->default(100)->change();
            $table->integer('off_peak_fare')->nullable(false)->default(100)->change();
        });

        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable(false)->default(100)->change();
            $table->integer('off_peak_fare')->nullable(false)->default(100)->change();
        });

        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->integer('peak_fare')->nullable(false)->default(100)->change();
            $table->integer('off_peak_fare')->nullable(false)->default(100)->change();
        });
    }
};
