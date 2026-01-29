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
        Schema::create('social_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->unsignedBigInteger('replies')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('interaction_score', 18, 4)->default(0);
            $table->decimal('interaction_score_change_pct', 9, 4)->nullable();
            $table->json('metrics_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'collected_at']);
            $table->index(['social_post_id', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_post_metrics');
    }
};
