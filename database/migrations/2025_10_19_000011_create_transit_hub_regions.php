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

// database/migrations/xxxx_create_transit_hub_regions.php
Schema::create('transit_hub_regions', function (Blueprint $t) {
    $t->string('region_id')->primary();
    $t->string('name');
    $t->string('level');        // county|city|cbd|custom
    $t->unsignedTinyInteger('h3_res')->nullable();
    $t->json('h3_cells')->nullable();
    $t->json('polygon')->nullable();
    $t->timestamps();
});

    }};
