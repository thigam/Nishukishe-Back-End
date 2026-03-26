<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // --- Add columns ONLY if the table exists ---
        if (Schema::hasTable('pre_clean_trips') && ! Schema::hasColumn('pre_clean_trips', 'sacco_route_id')) {
            Schema::table('pre_clean_trips', function (Blueprint $table) {
                $table->string('sacco_route_id')->nullable()->after('id');
                $table->index('sacco_route_id', 'pre_clean_trips_sacco_route_id_index');
            });
        }

        if (Schema::hasTable('post_clean_trips') && ! Schema::hasColumn('post_clean_trips', 'sacco_route_id')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->string('sacco_route_id')->nullable()->after('sacco_id');
                $table->index('sacco_route_id', 'post_clean_trips_sacco_route_id_index');
            });
        }

        // --- Backfill pre_clean_trips from pre_clean_sacco_routes via pre_clean_sacco_route_id ---
        if (Schema::hasTable('pre_clean_trips') && Schema::hasTable('pre_clean_sacco_routes')) {
            DB::table('pre_clean_trips')
                ->orderBy('id')
                ->whereNull('sacco_route_id')
                ->chunkById(100, function ($trips) {
                    foreach ($trips as $trip) {
                        $saccoRouteId = null;

                        if (! empty($trip->pre_clean_sacco_route_id)) {
                            $saccoRouteId = DB::table('pre_clean_sacco_routes')
                                ->where('id', $trip->pre_clean_sacco_route_id)
                                ->value('sacco_route_id');
                        }

                        if ($saccoRouteId) {
                            DB::table('pre_clean_trips')
                                ->where('id', $trip->id)
                                ->update(['sacco_route_id' => $saccoRouteId]);
                        }
                    }
                });
        }

        // --- Backfill post_clean_trips ---
        if (Schema::hasTable('post_clean_trips')) {
            DB::table('post_clean_trips')
                ->orderBy('id')
                ->whereNull('sacco_route_id')
                ->chunkById(100, function ($trips) {
                    foreach ($trips as $trip) {
                        $saccoRouteId = null;

                        if (! empty($trip->pre_clean_id) && Schema::hasTable('pre_clean_trips')) {
                            $saccoRouteId = DB::table('pre_clean_trips')
                                ->where('id', $trip->pre_clean_id)
                                ->value('sacco_route_id');
                        }

                        if (! $saccoRouteId && isset($trip->route_id) && is_string($trip->route_id)) {
                            if (preg_match('/^[A-Z0-9]+_\d+_\d{3}$/', $trip->route_id)) {
                                $saccoRouteId = $trip->route_id;
                            }
                        }

                        if (
                            ! $saccoRouteId &&
                            Schema::hasTable('post_clean_sacco_routes') &&
                            isset($trip->sacco_id, $trip->route_id)
                        ) {
                            $saccoRouteId = DB::table('post_clean_sacco_routes')
                                ->where('sacco_id', $trip->sacco_id)
                                ->where('route_id', $trip->route_id)
                                ->value('sacco_route_id');
                        }

                        if ($saccoRouteId) {
                            DB::table('post_clean_trips')
                                ->where('id', $trip->id)
                                ->update(['sacco_route_id' => $saccoRouteId]);
                        }
                    }
                });
        }

        // --- Tighten NOT NULL only when safe, and never on SQLite ---
        if ($driver !== 'sqlite') {
            if (Schema::hasTable('pre_clean_trips')) {
                $preTripsHasNulls = DB::table('pre_clean_trips')->whereNull('sacco_route_id')->exists();
                if (! $preTripsHasNulls) {
                    Schema::table('pre_clean_trips', function (Blueprint $table) {
                        $table->string('sacco_route_id')->nullable(false)->change();
                    });
                }
            }

            if (Schema::hasTable('post_clean_trips')) {
                $postTripsHasNulls = DB::table('post_clean_trips')->whereNull('sacco_route_id')->exists();
                if (! $postTripsHasNulls) {
                    Schema::table('post_clean_trips', function (Blueprint $table) {
                        $table->string('sacco_route_id')->nullable(false)->change();
                    });
                }
            }
        }

        // --- Drop FK+column only when present and safe (and not on SQLite) ---
        if (
            $driver !== 'sqlite' &&
            Schema::hasTable('pre_clean_trips') &&
            Schema::hasColumn('pre_clean_trips', 'pre_clean_sacco_route_id')
        ) {
            $preTripsHasNulls = DB::table('pre_clean_trips')->whereNull('sacco_route_id')->exists();
            if (! $preTripsHasNulls) {
                Schema::table('pre_clean_trips', function (Blueprint $table) {
                    // If FK name is custom, adjust here; otherwise Laravel will infer.
                    $table->dropForeign(['pre_clean_sacco_route_id']);
                });

                Schema::table('pre_clean_trips', function (Blueprint $table) {
                    $table->dropColumn('pre_clean_sacco_route_id');
                });
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite' && Schema::hasTable('pre_clean_trips') && ! Schema::hasColumn('pre_clean_trips', 'pre_clean_sacco_route_id')) {
            Schema::table('pre_clean_trips', function (Blueprint $table) {
                $table->unsignedBigInteger('pre_clean_sacco_route_id')->nullable()->after('id');
            });
            if (Schema::hasTable('pre_clean_sacco_routes')) {
                Schema::table('pre_clean_trips', function (Blueprint $table) {
                    $table->foreign('pre_clean_sacco_route_id')
                        ->references('id')
                        ->on('pre_clean_sacco_routes')
                        ->cascadeOnDelete();
                });
            }
        }

        if (Schema::hasTable('post_clean_trips') && Schema::hasColumn('post_clean_trips', 'sacco_route_id')) {
            Schema::table('post_clean_trips', function (Blueprint $table) {
                $table->dropIndex('post_clean_trips_sacco_route_id_index');
                $table->dropColumn('sacco_route_id');
            });
        }

        if (Schema::hasTable('pre_clean_trips') && Schema::hasColumn('pre_clean_trips', 'sacco_route_id')) {
            Schema::table('pre_clean_trips', function (Blueprint $table) {
                $table->dropIndex('pre_clean_trips_sacco_route_id_index');
                $table->dropColumn('sacco_route_id');
            });
        }
    }
};

