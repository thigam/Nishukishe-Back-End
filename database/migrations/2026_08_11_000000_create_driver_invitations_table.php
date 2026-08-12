<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_id');
            $table->string('email');
            $table->string('phone');
            $table->string('name');
            $table->string('vehicle_registration');
            $table->string('token')->unique();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_invitations');
    }
};
