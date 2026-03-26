<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directions', function (Blueprint $table) {
            $table->json('nearest_node_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('directions', function (Blueprint $table) {
            $table->dropColumn('nearest_node_ids');
        });
    }
};
