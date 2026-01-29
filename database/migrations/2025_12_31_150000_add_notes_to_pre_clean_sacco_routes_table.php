<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('route_fare');
        });
    }

    public function down(): void
    {
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};