<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('stops', function (Blueprint $table) {
        $table->renameColumn('stop_lan', 'stop_lat');
    });
}

public function down()
{
    Schema::table('stops', function (Blueprint $table) {
        $table->renameColumn('stop_lat', 'stop_lan');
    });
}

};
