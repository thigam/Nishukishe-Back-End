<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Directions;
use App\Services\H3Wrapper;

class BackfillH3Index extends Command
{
    protected $signature = 'directions:backfill-h3';
    protected $description = 'Populate h3_index for directions';

    public function handle()
    {
        Directions::query()->chunkById(100, function ($chunk) {
            foreach ($chunk as $direction) {
                $h3Index = H3Wrapper::latLngToCell(
                    (float) $direction->direction_latitude,
                    (float) $direction->direction_longitude,
                    9
                );
		$direction->h3_index = (string) H3Wrapper::latLngToCell(
    (float) $direction->direction_latitude,
    (float) $direction->direction_longitude,
    9
);

		$direction->save();
            }
        });

        $this->info('H3 indexes updated');
    }
}
