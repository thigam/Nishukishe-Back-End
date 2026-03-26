<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stop_times', function (Blueprint $table) {
            $table->string('sacco_route_id')->after('trip_id')->nullable();
            $table->foreign('sacco_route_id')
                ->references('sacco_route_id')
                ->on('sacco_routes')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
               Schema::table('stop_times', function (Blueprint $table) {
            $table->dropForeign(['sacco_route_id']);
            $table->dropColumn('sacco_route_id');
        });
    }
};
