<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->boolean('has_variations')->default(false)->after('scheduled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->dropColumn('has_variations');
        });
    }
};
