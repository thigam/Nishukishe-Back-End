<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('parcel_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('sacco_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
            $table->unique(['user_id', 'sacco_id']); // Ensure an agent is linked once per Sacco
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_agents');
    }
};
