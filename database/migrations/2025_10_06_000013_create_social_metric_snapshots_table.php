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
        Schema::create('social_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->decimal('interaction_score', 18, 4)->default(0);
            $table->decimal('interaction_score_change_pct', 9, 4)->nullable();
            $table->decimal('followers_change_pct', 9, 4)->nullable();
            $table->decimal('post_count_change_pct', 9, 4)->nullable();
            $table->json('metrics_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'collected_at']);
            $table->index(['social_account_id', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_metric_snapshots');
    }
};
