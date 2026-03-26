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
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->string('route_id', 36)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->change();
        });
    }
};
