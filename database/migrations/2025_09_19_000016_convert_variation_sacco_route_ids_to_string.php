<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // This migration is MySQL-specific (uses INFORMATION_SCHEMA).
        // Skip it for non-MySQL drivers, unless it is SQLite (testing).
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'sqlite') {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->ensureUniqueIndexIfPossible('sacco_routes', 'sacco_route_id');
            $this->ensureUniqueIndexIfPossible('pre_clean_sacco_routes', 'sacco_route_id');
            $this->ensureUniqueIndexIfPossible('post_clean_sacco_routes', 'sacco_route_id');

            // Recreate pre_clean_variations
            Schema::dropIfExists('pre_clean_variations');
            Schema::create('pre_clean_variations', function (Blueprint $table) {
                $table->id();
                $table->string('sacco_route_id')->nullable();
                $table->json('coordinates')->nullable();
                $table->json('stop_ids')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();

                $table->foreign('sacco_route_id')->references('sacco_route_id')->on('pre_clean_sacco_routes')->cascadeOnDelete();
            });

            // Recreate post_clean_variations
            Schema::dropIfExists('post_clean_variations');
            Schema::create('post_clean_variations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pre_clean_id');
                $table->string('sacco_route_id')->nullable();
                $table->json('coordinates')->nullable();
                $table->json('stop_ids')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();

                $table->foreign('pre_clean_id')->references('id')->on('pre_clean_variations')->cascadeOnDelete();
                $table->foreign('sacco_route_id')->references('sacco_route_id')->on('post_clean_sacco_routes')->cascadeOnDelete();
            });

            return;
        }
        // Ensure unique indexes on the referenced string columns (needed for FK)
        $this->ensureUniqueIndexIfPossible('sacco_routes', 'sacco_route_id');
        $this->ensureUniqueIndexIfPossible('pre_clean_sacco_routes', 'sacco_route_id');
        $this->ensureUniqueIndexIfPossible('post_clean_sacco_routes', 'sacco_route_id');

        // Convert both variation tables from INT/STRING mix -> STRING FK (composite)
        $this->convertToStringFk(
            variationTable: 'pre_clean_variations',
            routeTable: 'pre_clean_sacco_routes'
        );

        $this->convertToStringFk(
            variationTable: 'post_clean_variations',
            routeTable: 'post_clean_sacco_routes'
        );
    }

    public function down(): void
    {
        // This migration is MySQL-specific (uses INFORMATION_SCHEMA).
        // Skip it for SQLite (testing) and any non-MySQL drivers.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        // Revert both variation tables back to BIGINT FK to route PK (id)
        $this->convertToUnsignedFk(
            variationTable: 'pre_clean_variations',
            routeTable: 'pre_clean_sacco_routes'
        );

        $this->convertToUnsignedFk(
            variationTable: 'post_clean_variations',
            routeTable: 'post_clean_sacco_routes'
        );

        // Optionally drop uniques we created (keep if other FKs rely on them)
        $this->dropUniqueIndexIfExists('sacco_routes', 'sacco_route_id');
        $this->dropUniqueIndexIfExists('pre_clean_sacco_routes', 'sacco_route_id');
        $this->dropUniqueIndexIfExists('post_clean_sacco_routes', 'sacco_route_id');
    }

    /* ============================================================
       UP direction: to STRING FK (references route.sacco_route_id)
       ============================================================ */

    private function convertToStringFk(string $variationTable, string $routeTable): void
    {
        if (!Schema::hasTable($variationTable) || !Schema::hasTable($routeTable)) {
            return;
        }
        if (!Schema::hasColumn($variationTable, 'sacco_route_id')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        // 1) Drop any FK on variations.sacco_route_id
        $this->dropForeignIfExists($variationTable, 'sacco_route_id');

        // 2) Add a temp string column to hold the composite
        if (!Schema::hasColumn($variationTable, 'sacco_route_id_str')) {
            Schema::table($variationTable, function (Blueprint $t) {
                $t->string('sacco_route_id_str', 191)->nullable()->after('sacco_route_id');
            });
        }

        // 3) Backfill: support both legacy INT (route.id) and already composite strings
        $idToComposite = DB::table($routeTable)->pluck('sacco_route_id', 'id')->toArray();
        $composites = DB::table($routeTable)->pluck('sacco_route_id')->toArray();
        $compositeSet = array_fill_keys($composites, true);

        $missingIds = [];

        DB::table($variationTable)
            ->orderBy('id')
            ->select('id', 'sacco_route_id')
            ->chunkById(1000, function ($rows) use ($variationTable, $idToComposite, $compositeSet, &$missingIds) {
                foreach ($rows as $row) {
                    $orig = $row->sacco_route_id;

                    if ($orig === null) {
                        continue; // leave null if allowed
                    }

                    $candidate = null;

                    if (is_numeric($orig)) {
                        // legacy INT FK -> map to composite
                        $candidate = $idToComposite[$orig] ?? null;
                    } else {
                        // already composite? keep if exists in route table
                        if (isset($compositeSet[$orig])) {
                            $candidate = $orig;
                        }
                    }

                    if ($candidate !== null) {
                        DB::table($variationTable)
                            ->where('id', $row->id)
                            ->update(['sacco_route_id_str' => $candidate]);
                    } else {
                        $missingIds[] = (int) $row->id;
                    }
                }
            });

        // 4) If any rows couldn’t be mapped, fail with details
        $missing = DB::table($variationTable)
            ->whereNotNull('sacco_route_id')      // had some value
            ->whereNull('sacco_route_id_str')     // couldn’t map
            ->count();

        if ($missing > 0) {
            Schema::enableForeignKeyConstraints();
            $sample = implode(',', array_slice($missingIds, 0, 10));
            throw new \RuntimeException(
                "Failed to backfill sacco_route_id for {$variationTable} ({$missing} missing). " .
                "Example variation IDs: {$sample}"
            );
        }

        // 5) Drop old column and create final string column (no rename/change)
        DB::statement("ALTER TABLE `{$variationTable}` DROP COLUMN `sacco_route_id`");

        Schema::table($variationTable, function (Blueprint $t) {
            $t->string('sacco_route_id', 191)->nullable();
        });

        DB::table($variationTable)->update([
            'sacco_route_id' => DB::raw('sacco_route_id_str')
        ]);

        // 6) Make NOT NULL (adjust to NULL if your data needs it)
        DB::statement("ALTER TABLE `{$variationTable}` MODIFY `sacco_route_id` VARCHAR(191) NOT NULL");

        // 7) Drop temp column
        Schema::table($variationTable, function (Blueprint $t) {
            $t->dropColumn('sacco_route_id_str');
        });

        // 8) Ensure unique index exists on routeTable.sacco_route_id (for FK) and add FK
        $this->ensureUniqueIndexIfPossible($routeTable, 'sacco_route_id');

        Schema::table($variationTable, function (Blueprint $t) use ($routeTable) {
            $t->foreign('sacco_route_id')
                ->references('sacco_route_id')
                ->on($routeTable)
                ->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    /* ==============================================================
       DOWN direction: to UNSIGNED BIGINT FK (references route.id)
       ============================================================== */

    private function convertToUnsignedFk(string $variationTable, string $routeTable): void
    {
        if (!Schema::hasTable($variationTable) || !Schema::hasTable($routeTable)) {
            return;
        }
        if (!Schema::hasColumn($variationTable, 'sacco_route_id')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        // 1) Drop FK if present
        $this->dropForeignIfExists($variationTable, 'sacco_route_id');

        // 2) Add temp unsigned column
        if (!Schema::hasColumn($variationTable, 'sacco_route_id_tmp')) {
            Schema::table($variationTable, function (Blueprint $t) {
                $t->unsignedBigInteger('sacco_route_id_tmp')->nullable()->after('sacco_route_id');
            });
        }

        // 3) Backfill temp from string composite -> route.id
        $compositeToId = DB::table($routeTable)->pluck('id', 'sacco_route_id')->toArray();
        $missingIds = [];

        DB::table($variationTable)
            ->orderBy('id')
            ->select('id', 'sacco_route_id')
            ->chunkById(1000, function ($rows) use ($variationTable, $compositeToId, &$missingIds) {
                foreach ($rows as $row) {
                    $orig = $row->sacco_route_id;

                    if ($orig === null) {
                        continue;
                    }

                    $id = $compositeToId[$orig] ?? null;

                    if ($id !== null) {
                        DB::table($variationTable)
                            ->where('id', $row->id)
                            ->update(['sacco_route_id_tmp' => $id]);
                    } else {
                        $missingIds[] = (int) $row->id;
                    }
                }
            });

        // 4) Fail if unmapped rows remain
        $missing = DB::table($variationTable)
            ->whereNotNull('sacco_route_id')
            ->whereNull('sacco_route_id_tmp')
            ->count();

        if ($missing > 0) {
            Schema::enableForeignKeyConstraints();
            $sample = implode(',', array_slice($missingIds, 0, 10));
            throw new \RuntimeException(
                "Failed to backfill legacy sacco_route_id for {$variationTable} ({$missing} missing). " .
                "Example variation IDs: {$sample}"
            );
        }

        // 5) Drop old string column and create final bigint
        DB::statement("ALTER TABLE `{$variationTable}` DROP COLUMN `sacco_route_id`");

        Schema::table($variationTable, function (Blueprint $t) {
            $t->unsignedBigInteger('sacco_route_id')->nullable();
        });

        DB::table($variationTable)->update([
            'sacco_route_id' => DB::raw('sacco_route_id_tmp')
        ]);

        DB::statement("ALTER TABLE `{$variationTable}` MODIFY `sacco_route_id` BIGINT UNSIGNED NOT NULL");

        // 6) Drop temp column
        Schema::table($variationTable, function (Blueprint $t) {
            $t->dropColumn('sacco_route_id_tmp');
        });

        // 7) Recreate FK to routeTable.id
        Schema::table($variationTable, function (Blueprint $t) use ($routeTable) {
            $t->foreign('sacco_route_id')
                ->references('id')
                ->on($routeTable)
                ->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    /* =========================
       Index / FK helpers
       ========================= */

    private function dropForeignIfExists(string $table, string $column): void
    {
        $db = DB::getDatabaseName();

        $constraints = DB::table('INFORMATION_SCHEMA.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        if (!$constraints) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($constraints) {
            foreach ($constraints as $name) {
                try {
                    $t->dropForeign($name);
                } catch (\Throwable $e) {
                    // ignore if already gone
                }
            }
        });
    }

    private function ensureUniqueIndexIfPossible(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('$table')");
            foreach ($indexes as $index) {
                if ($index->unique) {
                    $info = DB::select("PRAGMA index_info('{$index->name}')");
                    if (count($info) === 1 && $info[0]->name === $column) {
                        return; // Already unique
                    }
                }
            }
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->unique($column);
            });
            return;
        }

        $db = DB::getDatabaseName();

        // See if there is already a UNIQUE index on exactly this column
        $indexes = DB::table('INFORMATION_SCHEMA.STATISTICS')
            ->select('INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->get()
            ->groupBy('INDEX_NAME');

        foreach ($indexes as $name => $parts) {
            $isUnique = (int) ($parts->first()->NON_UNIQUE ?? 1) === 0;
            $isSingle = $parts->count() === 1;
            $isOnCol = $isSingle && strcasecmp($parts->first()->COLUMN_NAME ?? '', $column) === 0;

            if ($isUnique && $isOnCol) {
                return; // already unique on this single column
            }
        }

        // Create a standard Laravel-named unique index
        Schema::table($table, function (Blueprint $t) use ($column) {
            $t->unique($column);
        });
    }

    private function dropUniqueIndexIfExists(string $table, string $column): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('$table')");
            foreach ($indexes as $index) {
                if ($index->unique) {
                    $info = DB::select("PRAGMA index_info('{$index->name}')");
                    if (count($info) === 1 && $info[0]->name === $column) {
                        Schema::table($table, function (Blueprint $t) use ($index) {
                            $t->dropUnique($index->name);
                        });
                        return;
                    }
                }
            }
            return;
        }

        $db = DB::getDatabaseName();

        $indexes = DB::table('INFORMATION_SCHEMA.STATISTICS')
            ->select('INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->get()
            ->groupBy('INDEX_NAME');

        foreach ($indexes as $name => $parts) {
            $isUnique = (int) ($parts->first()->NON_UNIQUE ?? 1) === 0;
            $isSingle = $parts->count() === 1;
            $isOnCol = $isSingle && strcasecmp($parts->first()->COLUMN_NAME ?? '', $column) === 0;

            if ($isUnique && $isOnCol) {
                Schema::table($table, function (Blueprint $t) use ($name) {
                    try {
                        $t->dropUnique($name);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
                return;
            }
        }
    }
};

