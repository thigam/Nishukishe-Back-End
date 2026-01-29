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
        Schema::create('sacco_stages', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('image_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
            $table->index('sacco_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_stages');
    }
};
