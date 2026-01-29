<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_routes', function (Blueprint $table) {
            // Composite PK
            $table->string('route_id');
            $table->string('sacco_id');
            $table->primary(['route_id','sacco_id']);

            // Route metadata
            // $table->string('route_number');
            $table->string('route_start_stop');
            $table->string('route_end_stop');

            // Keep fare, default 100
            $table->decimal('route_fare', 8, 2)
                  ->default(100);

            // New columns
            $table->json('coordinates')->nullable();
            $table->json('stop_ids')    ->nullable();
            $table->string('county_id')
                  ->nullable();
            $table->string('mode')      ->nullable();
            $table->integer('waiting_time')
                  ->nullable();

            // FKs
            $table->foreign('sacco_id')
                  ->references('sacco_id')
                  ->on('saccos')
                  ->onDelete('cascade');
            $table->foreign('county_id')
                  ->references('county_id')
                  ->on('counties')
                  ->nullOnDelete();
        });
      }

      public function down(): void
      {
            Schema::dropIfExists('sacco_routes');
      }
      };

