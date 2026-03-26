<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_id');
            $table->string('sacco_route_id');
            $table->unsignedInteger('rank');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_metrics');
    }
};
