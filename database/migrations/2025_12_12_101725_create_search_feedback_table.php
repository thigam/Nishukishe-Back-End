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
        Schema::dropIfExists('search_feedback');
        Schema::create('search_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('user_start');
            $table->string('user_end');
            $table->string('sacco_route_id')->nullable()->index();
            $table->string('sacco_id')->nullable()->index();
            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->nullOnDelete();
            $table->string('grade'); // good, moderate, bad
            $table->string('ip_address')->nullable();
            $table->string('session_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_feedback');
    }
};
