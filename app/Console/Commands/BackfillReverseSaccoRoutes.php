<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\SaccoRoutes;
use Illuminate\Support\Facades\Schema;

class BackfillReverseSaccoRoutes extends Command
{
    protected $signature = 'saccoroutes:backfill-reverse
                            {--dry-run : Show what would be created without writing}
                            {--limit=0 : Only process up to N (sacco_id,route_id) pairs (0 = no limit)}';

    protected $description = 'Duplicate each forward SaccoRoute into a reverse direction (index 002), reversing stops and coordinates';

    public function handle(): int
{
    $dry   = (bool)$this->option('dry-run');
    $limit = (int)$this->option('limit');

    $pairs = SaccoRoutes::query()
        ->select('sacco_id', 'route_id', DB::raw('COUNT(*) as cnt'))
        ->groupBy('sacco_id', 'route_id')
        ->when($limit > 0, fn($q) => $q->limit($limit))
        ->get();

    $created = 0; $skipped = 0; $errors = 0;

    foreach ($pairs as $row) {
        if ((int)$row->cnt >= 2) { $skipped++; continue; }

        /** @var SaccoRoutes|null $forward */
        $forward = SaccoRoutes::where('sacco_id', $row->sacco_id)
            ->where('route_id', $row->route_id)
            ->orderBy('sacco_route_id')
            ->first();

        if (!$forward) { $skipped++; continue; }

        $stopIds = $forward->stop_ids ?? [];
        if (!is_array($stopIds) || count($stopIds) < 2) { $skipped++; continue; }

        // Replicate the model
        $reverse = $forward->replicate();
        $reverse->sacco_route_id = SaccoRoutes::generateSaccoRouteId($forward->sacco_id, $forward->route_id);

        // Reverse arrays
        $reverse->stop_ids = array_values(array_reverse($stopIds));
        $coords = $forward->coordinates ?? [];
        $reverse->coordinates = is_array($coords) ? array_values(array_reverse($coords)) : $coords;

        // IMPORTANT: remove legacy/non-existent columns (e.g., route_stop_times)
        // and, more generally, filter to real columns in the current DB schema.
        $table   = $reverse->getTable();
        $columns = Schema::getColumnListing($table);

        // Fail fast if PK column is missing from the table
        if (!in_array($reverse->getKeyName(), $columns, true)) {
            $this->error("Table '{$table}' is missing primary key column '{$reverse->getKeyName()}'. Aborting.");
            return self::FAILURE;
        }

        // Drop known-removed field explicitly (handles cases where it's still on the model)
        if (array_key_exists('route_stop_times', $reverse->getAttributes())) {
            $reverse->offsetUnset('route_stop_times');
        }

        // Keep only attributes that actually exist as columns
        $attrs = array_intersect_key($reverse->getAttributes(), array_flip($columns));
        $reverse->setRawAttributes($attrs, true);

        if ($dry) {
            $this->line(sprintf(
                '[DRY] Would create reverse for sacco_id=%s route_id=%s as sacco_route_id=%s (reversing %d stops, %d coords)',
                $forward->sacco_id,
                $forward->route_id,
                $reverse->sacco_route_id,
                count($reverse->stop_ids ?? []),
                count($reverse->coordinates ?? [])
            ));
            $created++;
            continue;
        }

        try {
            DB::transaction(function () use ($reverse) {
                $reverse->save();
            });

            $this->info(sprintf(
                'Created reverse: %s (from %s / %s)',
                $reverse->sacco_route_id,
                $reverse->sacco_id,
                $reverse->route_id
            ));
            $created++;
        } catch (\Throwable $e) {
            $this->error(sprintf(
                'Failed for sacco_id=%s route_id=%s: %s',
                $row->sacco_id,
                $row->route_id,
                $e->getMessage()
            ));
            $errors++;
        }
    }

    $this->table(
        ['Created', 'Skipped', 'Errors', 'Dry-run'],
        [[(string)$created, (string)$skipped, (string)$errors, $dry ? 'yes' : 'no']]
    );

    return $errors > 0 ? self::FAILURE : self::SUCCESS;
}
}

