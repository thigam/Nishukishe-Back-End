<?php

namespace App\Console\Commands;

use App\Services\Social\SocialIngestionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SocialIngestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'social:ingest {platform? : The social media platform identifier or "all"} {--since= : Only pull metrics recorded after this datetime (Y-m-d or ISO8601)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull social metrics from configured providers and persist them.';

    public function __construct(private readonly SocialIngestionManager $manager)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $platformArg = $this->argument('platform');
        $sinceInput = $this->option('since');

        $since = $sinceInput ? Carbon::parse($sinceInput) : Carbon::now()->subDay();

        $platforms = $this->resolvePlatforms((string) ($platformArg ?? ''));
        if ($platforms->isEmpty()) {
            $this->warn('No social providers configured.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Fetching metrics since %s', $since->toDateTimeString()));

        $successCount = 0;

        foreach ($platforms as $platform) {
            $this->line(sprintf('→ %s', ucfirst($platform)));

            try {
                $account = $this->manager->ingest($platform, $since);
            } catch (\Throwable $exception) {
                $this->error(sprintf('  Failed: %s', $exception->getMessage()));
                continue;
            }

            $snapshot = $account->latestSnapshot ?: $account->snapshots()->latest('collected_at')->first();
            $followers = $snapshot?->followers ?? $account->follower_count;
            $interaction = $snapshot?->interaction_score ?? null;

            $this->info(sprintf(
                '  Synced %s — followers: %s, interaction score: %s',
                $account->display_name ?? $account->username ?? $account->external_id,
                $followers,
                $interaction !== null ? number_format($interaction, 2) : 'n/a'
            ));

            $successCount++;
        }

        $this->info(sprintf('Completed ingestion for %d/%d providers.', $successCount, $platforms->count()));

        return self::SUCCESS;
    }

    protected function resolvePlatforms(string $platform): Collection
    {
        $available = collect(array_keys($this->manager->allProviders()));

        if ($platform === '' || strtolower($platform) === 'all') {
            return $available;
        }

        $platform = strtolower($platform);

        return $available->filter(fn ($value) => strtolower($value) === $platform);
    }
}
