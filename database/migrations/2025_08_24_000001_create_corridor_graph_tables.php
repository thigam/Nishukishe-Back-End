<?php
// database/migrations/2025_08_24_000001_create_corridor_graph_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    // NOTE: No versions table and no version_id columns. We overwrite data each build.

    // L2 stations (clustered stops)
    Schema::create('corr_stations', function (Blueprint $t) {
      $t->string('station_id')->primary();          // e.g. "st:8:892a…"
      $t->double('lat'); 
      $t->double('lng');
      $t->string('l1_cell')->index();
      $t->string('l0_cell')->index();
      $t->unsignedSmallInteger('route_degree')->default(0);
      $t->timestamps();
    });

    // Members (stop_ids inside each station)
    Schema::create('corr_station_members', function (Blueprint $t) {
      $t->id();
      $t->string('station_id')->index();
      $t->string('stop_id')->index();
      $t->timestamps();

      $t->foreign('station_id')->references('station_id')->on('corr_stations')->cascadeOnDelete();
      $t->unique(['station_id','stop_id']);
    });

    // L1/L0 cells
    Schema::create('corr_cells', function (Blueprint $t) {
      $t->string('cell_id');              // H3 id
      $t->unsignedTinyInteger('level');   // 1 or 0
      $t->double('lat'); 
      $t->double('lng');                  // center
      $t->string('l0_parent')->nullable()->index(); // set for level=1 rows
      $t->timestamps();

      // Primary key without versioning
      $t->primary(['cell_id','level']);
      $t->index(['level']);
    });

    // Cell neighbors (undirected edges stored both ways)
    Schema::create('corr_cell_neighbors', function (Blueprint $t) {
      $t->id();
      $t->unsignedTinyInteger('level'); // 1 or 0
      $t->string('cell_a'); 
      $t->string('cell_b');
      $t->timestamps();

      $t->index(['level','cell_a']);
      $t->index(['level','cell_b']);
    });

    // Portals: top-K stations per cell (for both L1 and L0)
    Schema::create('corr_cell_portals', function (Blueprint $t) {
      $t->id();
      $t->unsignedTinyInteger('level'); // 1 or 0
      $t->string('cell_id')->index();
      $t->string('station_id')->index();
      $t->float('score')->default(0);
      $t->unsignedTinyInteger('rank')->default(0);
      $t->timestamps();

      $t->unique(['level','cell_id','station_id']);
    });

    // Portal-to-portal summaries for neighbor cells (keep top M by minutes)
    Schema::create('corr_cell_edge_summaries', function (Blueprint $t) {
      $t->id();
      $t->unsignedTinyInteger('level'); // 1 or 0
      $t->string('from_cell')->index();
      $t->string('to_cell')->index();
      $t->string('from_station');
      $t->string('to_station');
      $t->float('minutes'); // lower bound
      $t->unsignedTinyInteger('rank')->default(0);
      $t->timestamps();

      $t->index(['level','from_cell','to_cell']);
    });
  }

  public function down(): void {
    Schema::dropIfExists('corr_cell_edge_summaries');
    Schema::dropIfExists('corr_cell_portals');
    Schema::dropIfExists('corr_cell_neighbors');
    Schema::dropIfExists('corr_cells');
    Schema::dropIfExists('corr_station_members');
    Schema::dropIfExists('corr_stations');
  }
};

