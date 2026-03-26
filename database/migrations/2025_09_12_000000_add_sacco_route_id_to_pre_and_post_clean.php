<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            if (!Schema::hasColumn('pre_clean_sacco_routes', 'sacco_route_id')) {
                $table->string('sacco_route_id')->nullable()->after('route_id');
            }
            if (!Schema::hasColumn('pre_clean_sacco_routes', 'route_number')) {
                $table->string('route_number')->nullable()->after('sacco_id');
            }
        });

        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            if (!Schema::hasColumn('post_clean_sacco_routes', 'sacco_route_id')) {
                $table->string('sacco_route_id')->nullable()->after('route_id');
            }
            if (!Schema::hasColumn('post_clean_sacco_routes', 'route_number')) {
                $table->string('route_number')->nullable()->after('sacco_id');
            }
        });

        // Data fix: move any composite ids stored in post_clean.route_id into sacco_route_id
        // when they match the "SACCO_BASE_###" shape, and restore route_id to base if we
        // can find a matching pre_clean row.
        $rows = DB::table('post_clean_sacco_routes')->select('id','route_id','sacco_id')->get();
        foreach ($rows as $r) {
            if (preg_match('/^[A-Z0-9]+_\d+_\d{3}$/', $r->route_id)) {
                // Looks like a composite; try to recover base id from prefix
                // Pattern: SACCO_BASE_###  -> base is the middle token
                $parts = explode('_', $r->route_id);
                if (count($parts) >= 3) {
                    $base = $parts[1];

                    DB::table('post_clean_sacco_routes')
                        ->where('id', $r->id)
                        ->update([
                            'sacco_route_id' => $r->route_id, // move composite here
                            'route_id'       => $base,        // set base id back
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('post_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('post_clean_sacco_routes', 'sacco_route_id')) {
                $table->dropColumn('sacco_route_id');
            }
            if (Schema::hasColumn('post_clean_sacco_routes', 'route_number')) {
                $table->dropColumn('route_number');
            }
        });
        Schema::table('pre_clean_sacco_routes', function (Blueprint $table) {
            if (Schema::hasColumn('pre_clean_sacco_routes', 'sacco_route_id')) {
                $table->dropColumn('sacco_route_id');
            }
            if (Schema::hasColumn('pre_clean_sacco_routes', 'route_number')) {
                $table->dropColumn('route_number');
            }
        });
    }
};

