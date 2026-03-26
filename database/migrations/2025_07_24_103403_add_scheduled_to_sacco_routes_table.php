<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->boolean('scheduled')->default(false)->after('route_fare');
        });

    }

public function down()
{
    Schema::table('sacco_routes', function (Blueprint $table) {
        $table->dropColumn('scheduled');
    });
}

};
