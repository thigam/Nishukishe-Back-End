<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number')->unique();
            $table->string('sacco_id');
            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->cascadeOnDelete();
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('sender_email')->nullable();
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->string('receiver_email')->nullable();
            $table->string('status')->default('registered'); // registered, in_transit, received, delivered
            $table->decimal('fee', 10, 2)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('parcels');
        Schema::enableForeignKeyConstraints();
    }
};
