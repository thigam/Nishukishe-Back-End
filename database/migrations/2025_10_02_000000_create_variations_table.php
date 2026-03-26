<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variations', function (Blueprint $table) {
            $table->id('variation_id');
            $table->string('sacco_route_id');
            $table->json('coordinates')->nullable();
            $table->json('stop_ids')->nullable();
            $table->foreign('sacco_route_id')
                  ->references('sacco_route_id')
                  ->on('sacco_routes')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variations');
    }
};
