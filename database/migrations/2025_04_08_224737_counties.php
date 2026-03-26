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
        Schema::create('counties', function (Blueprint $table) {
            $table->string('county_id')->primary();
            $table->string('county_name');
            $table->string('county_hq');
        });

        DB::table('counties')->insert([
            [
                'county_id' => 'C00001',
                'county_name' => 'Nairobi',
                'county_hq' => 'Nairobi City'
            ],
            [
                'county_id' => 'C00002',
                'county_name' => 'Mombasa',
                'county_hq' => 'Mombasa City'
            ],
            [
                'county_id' => 'C00003',
                'county_name' => 'Kisumu',
                'county_hq' => 'Kisumu City'
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counties');
    }
};
