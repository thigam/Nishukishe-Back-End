<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('price', 8, 2)->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_tiers');
    }
};
