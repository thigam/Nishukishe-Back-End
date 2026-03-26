<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('route_id');
            $table->string('sacco_id');
            $table->json('sacco_route_ids')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verified_role')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'sacco_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_verifications');
    }
};
