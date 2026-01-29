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
        Schema::create('incoming_emails', function (Blueprint $table) {
            $table->id()->autoIncrement();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            
            // Thread parsing enhancements
            $table->string('message_hash', 32)->nullable(); // For deduplication
            $table->integer('thread_position')->default(1); // Position in thread
            
            $table->string('sender')->nullable();
            $table->string('sender_name', 255)->nullable(); // Display name of sender
            $table->string('recipient')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_spam')->default(false);
            $table->json('attachments')->nullable();
            
            // Foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for better performance
            $table->index('message_hash', 'idx_message_hash');
            $table->index(['sender', 'recipient', 'subject'], 'idx_thread_lookup');
            $table->index(['subject', 'received_at'], 'idx_subject_received');
            $table->index('user_id', 'idx_user_id');
            $table->index('is_read', 'idx_is_read');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incoming_emails');
    }
};