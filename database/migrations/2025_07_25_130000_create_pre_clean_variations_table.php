<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pre_clean_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sacco_route_id');
            $table->json('coordinates')->nullable();
            $table->json('stop_ids')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('sacco_route_id')
                  ->references('id')
                  ->on('pre_clean_sacco_routes')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_clean_variations');
    }
};
