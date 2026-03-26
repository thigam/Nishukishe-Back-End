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

Schema::create('transit_hubs', function (Blueprint $t) {
    $t->string('hub_id')->primary();
    $t->string('region_id');
    $t->string('stop_id');
    $t->unsignedInteger('rank');
    $t->double('score');
    $t->json('metrics')->nullable();
    $t->timestamps();

    $t->foreign('region_id')->references('region_id')->on('transit_hub_regions')->cascadeOnDelete();
});
    }};

