<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialMetricSnapshot;
use App\Models\SocialPost;
use App\Models\SocialPostMetric;
use App\Services\Social\Providers\SocialPlatformProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class SocialIngestionManager
{
    /** @var array<string, SocialPlatformProvider> */
    private array $providers = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InteractionScorer $scorer,
        iterable $providers = []
    ) {
        foreach ($providers as $provider) {
            $this->providers[strtolower($provider->platform())] = $provider;
        }
    }

    /**
     * @return array<string, SocialPlatformProvider>
     */
    public function allProviders(): array
    {
        return $this->providers;
    }

    public function getProvider(string $platform): ?SocialPlatformProvider
    {
        return $this->providers[strtolower($platform)] ?? null;
    }

    public function ingest(string $platform, CarbonInterface $since): ?SocialAccount
    {
        $provider = $this->getProvider($platform);
        if (! $provider) {
            throw new InvalidArgumentException(sprintf('Unknown social platform: %s', $platform));
        }

        $payload = $provider->fetchSnapshot($since);

        return $this->db->transaction(function () use ($platform, $payload) {
            $collectedAt = $this->parseDateTime($payload['collected_at'] ?? null) ?? now();
            $accountData = Arr::get($payload, 'account', []);
            $metrics = Arr::get($payload, 'metrics', []);
            $postsPayload = Arr::get($payload, 'posts', []);

            $externalId = $accountData['external_id'] ?? null;
            if (! $externalId) {
                throw new InvalidArgumentException('Social provider payload missing external_id.');
            }

            $numericMetrics = $this->filterNumericMetrics($metrics);
            if (! isset($numericMetrics['followers']) && isset($accountData['followers'])) {
                $numericMetrics['followers'] = (int) $accountData['followers'];
            }
            if (! isset($numericMetrics['post_count']) && is_array($postsPayload)) {
                $numericMetrics['post_count'] = count($postsPayload);
            }

            $account = SocialAccount::query()->firstOrNew([
                'platform' => strtolower($platform),
                'external_id' => (string) $externalId,
            ]);

            $account->fill([
                'display_name' => $accountData['display_name'] ?? $accountData['username'] ?? $account->display_name,
                'username' => $accountData['username'] ?? $account->username,
                'profile_url' => $accountData['profile_url'] ?? $account->profile_url,
                'avatar_url' => $accountData['avatar_url'] ?? $account->avatar_url,
                'follower_count' => (int) ($numericMetrics['followers'] ?? $account->follower_count ?? 0),
                'last_synced_at' => $collectedAt,
            ]);
            $account->metadata = $this->mergeMetadata($account->metadata ?? [], $accountData['metadata'] ?? []);
            $account->save();

            $previousSnapshot = $account
                ->snapshots()
                ->where('collected_at', '<', $collectedAt)
                ->orderByDesc('collected_at')
                ->first();

            $interactionScore = $this->scorer->score($account->platform, $numericMetrics);
            $snapshot = SocialMetricSnapshot::query()->updateOrCreate(
                [
                    'social_account_id' => $account->id,
                    'collected_at' => $collectedAt,
                ],
                [
                    'followers' => (int) ($numericMetrics['followers'] ?? 0),
                    'post_count' => (int) ($numericMetrics['post_count'] ?? 0),
                    'interaction_score' => $interactionScore,
                    'interaction_score_change_pct' => $this->percentChange($interactionScore, $previousSnapshot?->interaction_score),
                    'followers_change_pct' => $this->percentChange((int) ($numericMetrics['followers'] ?? 0), $previousSnapshot?->followers),
                    'post_count_change_pct' => $this->percentChange((int) ($numericMetrics['post_count'] ?? 0), $previousSnapshot?->post_count),
                    'metrics_breakdown' => $numericMetrics,
                ]
            );

            $this->ingestPosts($account, $postsPayload, $collectedAt);

            return $account->setRelation('latestSnapshot', $snapshot);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $postsPayload
     */
    protected function ingestPosts(SocialAccount $account, $postsPayload, CarbonInterface $collectedAt): void
    {
        if (! is_array($postsPayload)) {
            return;
        }

        foreach ($postsPayload as $postPayload) {
            if (! is_array($postPayload)) {
                continue;
            }

            $externalId = $postPayload['external_id'] ?? null;
            if (! $externalId) {
                continue;
            }

            $post = SocialPost::query()->firstOrNew([
                'social_account_id' => $account->id,
                'external_id' => (string) $externalId,
            ]);

            $post->fill([
                'permalink' => $postPayload['permalink'] ?? $post->permalink,
                'message' => $postPayload['message'] ?? $post->message,
                'published_at' => $this->parseDateTime($postPayload['published_at'] ?? null) ?? $post->published_at,
            ]);
            $post->metadata = $this->mergeMetadata($post->metadata ?? [], $postPayload['metadata'] ?? []);
            $post->save();

            $metrics = $this->filterNumericMetrics($postPayload['metrics'] ?? []);
            $previousMetric = $post
                ->metrics()
                ->where('collected_at', '<', $collectedAt)
                ->orderByDesc('collected_at')
                ->first();

            $interactionScore = $this->scorer->score($account->platform, $metrics);

            SocialPostMetric::query()->updateOrCreate(
                [
                    'social_post_id' => $post->id,
                    'collected_at' => $collectedAt,
                ],
                [
                    'likes' => (int) ($metrics['likes'] ?? 0),
                    'comments' => (int) ($metrics['comments'] ?? 0),
                    'shares' => (int) ($metrics['shares'] ?? 0),
                    'views' => (int) ($metrics['views'] ?? ($metrics['impressions'] ?? 0)),
                    'saves' => (int) ($metrics['saves'] ?? 0),
                    'replies' => (int) ($metrics['replies'] ?? 0),
                    'clicks' => (int) ($metrics['clicks'] ?? 0),
                    'interaction_score' => $interactionScore,
                    'interaction_score_change_pct' => $this->percentChange($interactionScore, $previousMetric?->interaction_score),
                    'metrics_breakdown' => $metrics,
                ]
            );
        }
    }

    protected function mergeMetadata(?array $existing, array $incoming): array
    {
        return array_filter(array_replace($existing ?? [], $incoming), static fn ($value) => $value !== null && $value !== '');
    }

    protected function percentChange(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if (abs($previous) < 0.00001) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 4);
    }

    protected function parseDateTime($value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, float|int>
     */
    protected function filterNumericMetrics(array $metrics): array
    {
        $filtered = [];
        foreach ($metrics as $key => $value) {
            if (is_numeric($value)) {
                $filtered[$key] = $value + 0;
            }
        }

        return $filtered;
    }
}
