<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            $table->unsignedBigInteger('tier_id')->nullable()->after('is_approved');
            $table->foreign('tier_id')->references('id')->on('sacco_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            $table->dropForeign(['tier_id']);
            $table->dropColumn('tier_id');
        });
    }
};
