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
    Schema::create('bookables', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('organizer_id')->constrained('users');

    // CHANGE THIS BLOCK ↓
    // from: $table->foreignId('sacco_id')->nullable()->constrained('saccos');
    $table->string('sacco_id')->nullable(); // consider ->index() if you query it often
    $table->foreign('sacco_id')
          ->references('sacco_id')
          ->on('saccos')
          ->nullOnDelete(); // or ->cascadeOnDelete() if you prefer

    $table->string('type');
    $table->string('title');
    $table->string('slug')->unique();
    $table->string('subtitle')->nullable();
    $table->text('description')->nullable();
    $table->string('status')->default('draft');
    $table->string('currency', 3)->default('KES');
    $table->decimal('service_fee_rate', 8, 5)->default(0.00000);
    $table->decimal('service_fee_flat', 10, 2)->default(0);
    $table->timestamp('terms_accepted_at')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->boolean('is_featured')->default(false);
    $table->json('metadata')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->index(['type', 'status']);
    // $table->index('sacco_id'); // add if you query by this often
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookables');
    }
};
