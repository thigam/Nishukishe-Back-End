<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            $table->dropColumn('route_number');
        });
    }

    public function down(): void
    {
        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            $table->string('route_number')->nullable(); // or remove nullable() if you want it required
        });
    }
};

