<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roads', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->index();
            $table->string('type')->nullable();
            $table->json('geometry');
            $table->decimal('lat_min', 10, 8)->index();
            $table->decimal('lat_max', 10, 8)->index();
            $table->decimal('lng_min', 11, 8)->index();
            $table->decimal('lng_max', 11, 8)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roads');
    }
};
