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
        Schema::table('decimal', function (Blueprint $table) {
            //
	});
	Schema::table('directions', function (Blueprint $table) {
    $table->decimal('direction_latitude', 9, 6)->change();
    $table->decimal('direction_longitude', 9, 6)->change();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('decimal', function (Blueprint $table) {
            //
        });
    }
};
