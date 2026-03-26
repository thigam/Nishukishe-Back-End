<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::table('sacco_safari_instances', function (Blueprint $table) {
            $table->json('seating_configuration')->nullable()->after('available_seats');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('sacco_safari_instances', function (Blueprint $table) {
            //
        });
    }
};
