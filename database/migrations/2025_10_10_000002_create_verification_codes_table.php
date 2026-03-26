<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->string('driver_code', 5);
            $table->string('receiver_code', 5)->nullable();
            $table->unsignedInteger('driver_mismatches')->default(0);
            $table->unsignedInteger('receiver_mismatches')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
