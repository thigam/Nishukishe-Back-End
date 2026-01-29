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
        Schema::table('directions', function (Blueprint $table) {
            $table->string('h3_index', 191)->nullable()->index(); 
            $table->unsignedBigInteger('nearest_node_id')->nullable()->index();
        });

        Schema::create('route_stop', function (Blueprint $table) {
            $table->string('route_id');
            $table->string('stop_id');
            $table->unsignedInteger('sequence');
            $table->primary(['route_id', 'stop_id']);
        });

        Schema::create('transfer_edges', function (Blueprint $table) {
            $table->id();
            $table->string('from_stop_id');
            $table->string('to_stop_id');
            $table->unsignedInteger('walk_time_seconds');
            $table->index(['from_stop_id', 'to_stop_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directions', function (Blueprint $table) {
            $table->dropColumn(['h3_index', 'nearest_node_id']);
        });

        Schema::dropIfExists('route_stop');
        Schema::dropIfExists('transfer_edges');
    }
};
