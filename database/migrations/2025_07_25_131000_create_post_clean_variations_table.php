<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_clean_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pre_clean_id');
            $table->unsignedBigInteger('sacco_route_id');
            $table->json('coordinates')->nullable();
            $table->json('stop_ids')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('pre_clean_id')
                  ->references('id')
                  ->on('pre_clean_variations')
                  ->onDelete('cascade');

            $table->foreign('sacco_route_id')
                  ->references('id')
                  ->on('post_clean_sacco_routes')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_clean_variations');
    }
};
