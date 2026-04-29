<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            // Nullable — anonymous sessions do NOT have a user_id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A UUID we generate on the device and persist in local storage
            $table->string('device_id', 64)->index();
            $table->string('platform', 20)->default('android'); // android | ios | web
            $table->string('app_version', 20)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            // Derived from closed_at - opened_at on session end
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
