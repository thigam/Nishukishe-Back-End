<?php

namespace App\Services\Social\Providers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinkedInProvider extends AbstractProvider
{
    public function platform(): string
    {
        return 'linkedin';
    }

    protected function doFetch(CarbonInterface $since): array
    {
        $organizationId = $this->config['organization_id'] ?? config('services.linkedin.organization_id');
        $accessToken = $this->config['access_token'] ?? config('services.linkedin.access_token');
        $baseUrl = $this->config['base_url'] ?? 'https://api.linkedin.com';

        if (! $organizationId || ! $accessToken) {
            throw new RuntimeException('Missing LinkedIn credentials.');
        }

        $urn = sprintf('urn:li:organization:%s', $organizationId);

        $client = Http::baseUrl($baseUrl)
            ->withToken($accessToken)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);

        $followers = $client
            ->get('/v2/organizationFollowerStatistics', [
                'q' => 'organizationalEntity',
                'organizationalEntity' => $urn,
            ])
            ->throw()
            ->json('elements', []);

        $followerCount = 0;
        foreach ($followers as $element) {
            $followerCount += (int) Arr::get($element, 'followerCounts.organicFollowerCount', 0);
        }

        $startRange = $since->copy()->subDays(30)->startOfDay()->getTimestampMs();
        $endRange = now()->endOfDay()->getTimestampMs();

        $pageStats = $client
            ->get('/v2/organizationPageStatistics', [
                'q' => 'organization',
                'organization' => $urn,
                'timeIntervals.timeGranularityType' => 'DAY',
                'timeIntervals.timeRange.start' => $startRange,
                'timeIntervals.timeRange.end' => $endRange,
            ])
            ->throw()
            ->json('elements', []);

        $aggregatedPageStats = [
            'clicks' => 0,
            'impressions' => 0,
        ];

        foreach ($pageStats as $stat) {
            $aggregatedPageStats['clicks'] += (int) Arr::get($stat, 'totalPageStatistics.clicks.allPageClicks', 0);
            $aggregatedPageStats['impressions'] += (int) Arr::get($stat, 'totalPageStatistics.views.pageViews.allPageViews', 0);
        }

        $postsResponse = $client
            ->get('/v2/ugcPosts', [
                'q' => 'authors',
                'authors' => sprintf('List(%s)', $urn),
                'sortBy' => 'LAST_MODIFIED',
                'count' => $this->config['limit'] ?? 20,
            ])
            ->throw()
            ->json('elements', []);

        $posts = [];
        $aggregate = [
            'followers' => $followerCount,
            'post_count' => count($postsResponse),
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'clicks' => $aggregatedPageStats['clicks'],
            'impressions' => $aggregatedPageStats['impressions'],
        ];

        foreach ($postsResponse as $element) {
            $postUrn = $element['id'] ?? null;
            if (! $postUrn) {
                continue;
            }

            $permalink = Arr::get($element, 'lifecycleState') === 'PUBLISHED'
                ? Arr::get($element, 'permalink')
                : null;

            $content = Arr::get($element, 'specificContent.ugcPostContent');
            $title = Arr::get($content, 'title.text')
                ?? Arr::get($content, 'shareCommentary.text');

            $metrics = [
                'likes' => 0,
                'comments' => 0,
                'shares' => 0,
                'clicks' => 0,
                'impressions' => 0,
            ];

            try {
                $action = $client
                    ->get('/v2/socialActions/' . rawurlencode($postUrn))
                    ->throw()
                    ->json();

                $metrics['likes'] = (int) Arr::get($action, 'likesSummary.totalLikes', 0);
                $metrics['comments'] = (int) Arr::get($action, 'commentsSummary.totalFirstLevelComments', 0);
                $metrics['shares'] = (int) Arr::get($action, 'shareSummary.totalShareStatistics.count', 0);
            } catch (\Throwable) {
                // Ignore failures and continue
            }

            $createdTime = Arr::get($element, 'created.time');

            $posts[] = [
                'external_id' => $postUrn,
                'permalink' => $permalink,
                'message' => $title,
                'published_at' => $createdTime ? Carbon::createFromTimestampMs((int) $createdTime)->toIso8601String() : null,
                'metrics' => $metrics,
            ];

            $aggregate['likes'] += $metrics['likes'];
            $aggregate['comments'] += $metrics['comments'];
            $aggregate['shares'] += $metrics['shares'];
        }

        return [
            'collected_at' => now()->toIso8601String(),
            'account' => [
                'external_id' => (string) $organizationId,
                'display_name' => $this->config['display_name'] ?? 'LinkedIn',
                'username' => $this->config['handle'] ?? null,
                'profile_url' => $this->config['profile_url'] ?? null,
                'avatar_url' => $this->config['avatar_url'] ?? null,
                'followers' => $aggregate['followers'],
            ],
            'metrics' => $aggregate,
            'posts' => $posts,
        ];
    }
}
