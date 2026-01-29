<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->string('type')->default('image');
            $table->string('url');
            $table->string('title')->nullable();
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['bookable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
