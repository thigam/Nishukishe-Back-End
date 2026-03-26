<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->longText('content');
            $table->text('editor_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['blog_post_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_versions');
    }
};
