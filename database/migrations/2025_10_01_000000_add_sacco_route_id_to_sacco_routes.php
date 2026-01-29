<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('sacco_routes', function (Blueprint $table) {
                if (!Schema::hasColumn('sacco_routes', 'sacco_route_id')) {
                    $table->string('sacco_route_id')->nullable()->unique();
                }
            });

            Schema::table('trips', function (Blueprint $table) {
                if (!Schema::hasColumn('trips', 'sacco_route_id')) {
                    $table->string('sacco_route_id')->nullable();
                }
            });

            return;
        }

        // Step 1: Drop composite FK from `trips`
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign('trips_sacco_id_route_id_foreign');
        });

        // Step 2: Modify `sacco_routes`
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->dropForeign(['sacco_id']);
            $table->dropPrimary(['route_id', 'sacco_id']);

            $table->string('sacco_route_id')->after('sacco_id');
            $table->primary('sacco_route_id');
        });

        // Step 3: Add `sacco_route_id` to `trips` + FK
        Schema::table('trips', function (Blueprint $table) {
            $table->string('sacco_route_id')->nullable()->after('route_id');
            $table->foreign('sacco_route_id')
                ->references('sacco_route_id')
                ->on('sacco_routes')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('trips', function (Blueprint $table) {
                if (Schema::hasColumn('trips', 'sacco_route_id')) {
                    $table->dropColumn('sacco_route_id');
                }
            });

            Schema::table('sacco_routes', function (Blueprint $table) {
                if (Schema::hasColumn('sacco_routes', 'sacco_route_id')) {
                    $table->dropColumn('sacco_route_id');
                }
            });

            return;
        }

        // Rollback `trips` foreign key + column
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['sacco_route_id']);
            $table->dropColumn('sacco_route_id');
        });

        // Restore original PK and FK on `sacco_routes`
        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->dropPrimary(['sacco_route_id']);
            $table->dropColumn('sacco_route_id');

            $table->primary(['route_id', 'sacco_id']);
            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
        });

        // Re-add composite FK on `trips` using raw SQL
        Schema::table('trips', function (Blueprint $table) {
            DB::statement("
                ALTER TABLE trips
                ADD CONSTRAINT trips_sacco_id_route_id_foreign
                FOREIGN KEY (sacco_id, route_id)
                REFERENCES sacco_routes (sacco_id, route_id)
                ON DELETE CASCADE
            ");
        });
    }
};

