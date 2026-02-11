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
        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('emails')->onDelete('cascade');
            $table->string('in_reply_to_message_id')->nullable()->index();
            $table->text('references')->nullable(); // Can be long, so text
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'in_reply_to_message_id', 'references']);
        });
    }
};
