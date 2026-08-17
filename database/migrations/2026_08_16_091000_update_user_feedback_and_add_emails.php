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
        Schema::table('user_feedback', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pending');
        });

        Schema::table('suggested_routes', function (Blueprint $table) {
            $table->string('email')->nullable()->after('user_id');
        });

        Schema::table('reported_wrong_info', function (Blueprint $table) {
            $table->string('email')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_feedback', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'name', 'email', 'subject', 'message', 'status']);
        });

        Schema::table('suggested_routes', function (Blueprint $table) {
            $table->dropColumn('email');
        });

        Schema::table('reported_wrong_info', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
