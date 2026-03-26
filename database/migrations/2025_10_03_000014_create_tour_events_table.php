<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookable_id')->unique()->constrained('bookables')->cascadeOnDelete();
            $table->string('destination');
            $table->string('meeting_point')->nullable();
            $table->string('duration_label')->nullable();
            $table->json('path_geojson')->nullable();
            $table->json('stops')->nullable();
            $table->text('marketing_copy')->nullable();
            $table->json('highlights')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('destination');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_events');
    }
};
