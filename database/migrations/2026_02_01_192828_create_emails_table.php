<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sender_email');
            $table->string('recipient_email');
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('message_id')->nullable()->index(); // Mailgun Message ID
            $table->enum('type', ['incoming', 'outgoing']);
            $table->timestamp('read_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
