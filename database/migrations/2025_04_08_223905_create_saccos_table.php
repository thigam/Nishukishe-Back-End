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
        Schema::create('saccos', function (Blueprint $table) {
            $table->string('sacco_id')->primary();
            $table->string('sacco_name');
            $table->string('vehicle_type');
            $table->timestamp('join_date'); // default current timestamp
            $table->string('sacco_logo')->nullable();
            $table->string('sacco_location')->nullable();
            $table->string('sacco_phone')->nullable();
            $table->string('sacco_email')->nullable();
            $table->string('sacco_website')->nullable();
            $table->string('sacco_address')->nullable();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saccos');
    }
};
