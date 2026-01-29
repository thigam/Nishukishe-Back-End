<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('routes', function (Blueprint $table) {
            $table->string('route_id')->primary();
            $table->string('route_number');
            $table->string('route_start_stop');
            $table->string('route_end_stop');
            $table->timestamps();
        });
    }

    public function down() {
        Schema::dropIfExists('routes');
    }
};

