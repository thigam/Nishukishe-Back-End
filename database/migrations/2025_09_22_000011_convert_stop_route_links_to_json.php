<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->convertToJson('pre_clean_stops', 'id');
        $this->convertToJson('post_clean_stops', 'stop_id');
    }

    public function down(): void
    {
        $this->revertToString('post_clean_stops', 'stop_id');
        $this->revertToString('pre_clean_stops', 'id');
    }

    private function convertToJson(string $table, string $afterColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'sacco_route_ids')) {
            Schema::table($table, function (Blueprint $t) use ($afterColumn) {
                $t->json('sacco_route_ids')->nullable()->after($afterColumn);
            });
        }

        $key = $this->primaryKeyFor($table);

        if (Schema::hasColumn($table, 'sacco_route_id')) {
            DB::table($table)
                ->orderBy($key)
                ->select([$key, 'sacco_route_id'])
                ->chunkById(100, function ($rows) use ($table, $key) {
                    foreach ($rows as $row) {
                        $payload = ($row->sacco_route_id !== null && $row->sacco_route_id !== '')
                            ? json_encode([$row->sacco_route_id])
                            : json_encode([]);

                        DB::table($table)
                            ->where($key, $row->$key)
                            ->update(['sacco_route_ids' => $payload]);
                    }
                }, $key);
        }

        DB::table($table)
            ->whereNull('sacco_route_ids')
            ->update(['sacco_route_ids' => json_encode([])]);

        // Try to drop the old index if it exists, without using Doctrine
        $this->dropIndexIfExists($table, $table . '_sacco_route_id_index');

        if (Schema::hasColumn($table, 'sacco_route_id')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sacco_route_id');
            });
        }
    }

    private function revertToString(string $table, string $afterColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'sacco_route_id')) {
            Schema::table($table, function (Blueprint $t) use ($afterColumn) {
                $t->string('sacco_route_id')->nullable()->after($afterColumn);
            });
        }

        $key = $this->primaryKeyFor($table);

        if (Schema::hasColumn($table, 'sacco_route_ids')) {
            DB::table($table)
                ->orderBy($key)
                ->select([$key, 'sacco_route_ids'])
                ->chunkById(100, function ($rows) use ($table, $key) {
                    foreach ($rows as $row) {
                        $ids = $this->decodeIds($row->sacco_route_ids);
                        $first = $ids[0] ?? null;

                        DB::table($table)
                            ->where($key, $row->$key)
                            ->update(['sacco_route_id' => $first]);
                    }
                }, $key);

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sacco_route_ids');
            });
        }
    }

    private function decodeIds($value): array
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

    /**
     * Drop an index by name if it exists, without relying on Doctrine.
     * Safe to call even if the index is missing.
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                // Works for normal or unique indexes as long as the name matches.
                $t->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Ignore: index doesn't exist or cannot be dropped in current state.
        }
    }

    /**
     * Best-effort guess of the row key used for chunking/updates.
     */
    private function primaryKeyFor(string $table): string
    {
        if (Schema::hasColumn($table, 'id')) {
            return 'id';
        }
        if (Schema::hasColumn($table, 'stop_id')) {
            return 'stop_id';
        }
        // Fallback to 'id' (also used by chunkById default)
        return 'id';
    }
};

