<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns (nullable) if missing
        Schema::table('pre_clean_stops', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_clean_stops', 'sacco_route_id')) {
                $table->string('sacco_route_id')->nullable()->after('id');
            }
        });

        Schema::table('post_clean_stops', function (Blueprint $table) {
            if (! Schema::hasColumn('post_clean_stops', 'sacco_route_id')) {
                $table->string('sacco_route_id')->nullable()->after('stop_id');
            }
        });

        // Backfills
        $this->backfillPreCleanStops();
        $this->backfillPostCleanStops();

        // Enforce NOT NULL only where safe
        $preStopsHasNulls  = DB::table('pre_clean_stops')->whereNull('sacco_route_id')->exists();
        $postStopsHasNulls = DB::table('post_clean_stops')->whereNull('sacco_route_id')->exists();

        Schema::table('pre_clean_stops', function (Blueprint $table) use ($preStopsHasNulls) {
            if (Schema::hasColumn('pre_clean_stops', 'sacco_route_id') && ! $preStopsHasNulls) {
                $table->string('sacco_route_id')->nullable(false)->change();
            }
        });

        Schema::table('post_clean_stops', function (Blueprint $table) use ($postStopsHasNulls) {
            if (Schema::hasColumn('post_clean_stops', 'sacco_route_id') && ! $postStopsHasNulls) {
                $table->string('sacco_route_id')->nullable(false)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('post_clean_stops', function (Blueprint $table) {
            if (Schema::hasColumn('post_clean_stops', 'sacco_route_id')) {
                $table->dropColumn('sacco_route_id');
            }
        });

        Schema::table('pre_clean_stops', function (Blueprint $table) {
            if (Schema::hasColumn('pre_clean_stops', 'sacco_route_id')) {
                $table->dropColumn('sacco_route_id');
            }
        });
    }

    protected function backfillPreCleanStops(): void
    {
        if (! Schema::hasTable('pre_clean_sacco_routes') || ! Schema::hasTable('pre_clean_stops')) {
            return;
        }

        $routes = DB::table('pre_clean_sacco_routes')
            ->select('sacco_route_id', 'stop_ids')
            ->whereNotNull('sacco_route_id')
            ->get();

        foreach ($routes as $route) {
            $stopIds = $this->decodeIds($route->stop_ids);

            if (! $stopIds) {
                continue;
            }

            foreach ($stopIds as $stopId) {
                if ($stopId === null || $stopId === '') {
                    continue;
                }

                DB::table('pre_clean_stops')
                    ->where('id', $stopId)
                    ->update(['sacco_route_id' => $route->sacco_route_id]);
            }
        }
    }

    protected function backfillPostCleanStops(): void
    {
        if (! Schema::hasTable('post_clean_sacco_routes') || ! Schema::hasTable('post_clean_stops')) {
            return;
        }

        $routes = DB::table('post_clean_sacco_routes')
            ->select('sacco_route_id', 'stop_ids')
            ->whereNotNull('sacco_route_id')
            ->get();

        foreach ($routes as $route) {
            $stopIds = $this->decodeIds($route->stop_ids);

            if (! $stopIds) {
                continue;
            }

            foreach ($stopIds as $stopId) {
                if ($stopId === null || $stopId === '') {
                    continue;
                }

                DB::table('post_clean_stops')
                    ->where('stop_id', (string) $stopId)
                    ->update(['sacco_route_id' => $route->sacco_route_id]);
            }
        }
    }

    protected function decodeIds($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
};

