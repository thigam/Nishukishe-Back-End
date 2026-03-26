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
        Schema::create('direction_threads', function (Blueprint $table) {
            $table->id();
            $table->string('origin_slug');
            $table->string('destination_slug');
            $table->timestamps();

            $table->unique(['origin_slug', 'destination_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('direction_threads');
    }
};
