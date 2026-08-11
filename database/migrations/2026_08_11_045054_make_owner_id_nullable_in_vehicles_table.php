<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Makes owner_id nullable so vehicles created via driver invitations
     * don't need a vehicle owner record (driver may own their own vehicle).
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: reverting to NOT NULL could fail if there are NULLs in the column
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
        });
    }
};
