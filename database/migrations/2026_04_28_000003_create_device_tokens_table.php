<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            // Nullable — anonymous users can still receive broadcast notifications
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 64)->index();
            $table->string('platform', 20)->default('android'); // android | ios
            // The FCM registration token — can be up to ~4KB
            $table->text('token');
            // Tracks whether we've received any delivery errors for this token
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One token per device — upsert on conflict
            $table->unique('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
