<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Sacco;
use App\Models\SocialAccount;
use App\Models\SearchLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AnalyticsService
{
    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    /**
     * Build analytics payload grouped by sections.
     */
    public function summarize(?string $start = null, ?string $end = null, ?string $interval = null): array
    {
        [$startDate, $endDate] = $this->normaliseRange($start, $end);
        $interval = $this->normaliseInterval($interval);

        $searchEvents = $this->collectSearchEvents($startDate, $endDate);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'range' => [
                'start' => $startDate?->toDateString(),
                'end' => $endDate?->toDateString(),
                'interval' => $interval,
            ],
            'search' => $this->buildSearchSection($searchEvents, $startDate, $endDate, $interval),
            'search_heatmap' => $this->buildSearchHeatmapSection($searchEvents),
            'engagement' => $this->buildEngagementSection($startDate, $endDate, $interval),
            'onboarding' => $this->buildOnboardingSection($startDate, $endDate, $interval),
            'discover_sacco' => $this->buildPageViewSection(
                $startDate,
                $endDate,
                $interval,
                fn(string $path) => $this->normaliseDiscoverSaccoPath($path),
                'Discover sacco visits',
                'Visits to the discover sacco listing and sacco profile pages.',
                'discover-frontend',
                'discover-sacco',
            ),
            'stage_pages' => $this->buildPageViewSection(
                $startDate,
                $endDate,
                $interval,
                fn(string $path) => $this->normaliseStagePath($path),
                'Stage pages visits',
                'Visits to individual sacco stage pages in the discover experience.',
                'discover-frontend',
                'stage-pages',
            ),
            'directions' => $this->buildPageViewSection(
                $startDate,
                $endDate,
                $interval,
                fn(string $path) => $this->normaliseDirectionsPath($path),
                'Directions visits',
                'How often travellers view directions pages.',
                'directions-frontend',
                'directions',
            ),
            'routes' => $this->buildRoutesSection($startDate, $endDate, $interval),
            'social' => $this->buildSocialSection(),
        ];
    }

    private function buildPageViewSection(
        ?Carbon $startDate,
        ?Carbon $endDate,
        string $interval,
        callable $pathResolver,
        string $title,
        string $description,
        ?string $source = null,
        ?string $slug = null,
    ): array {
        $logs = ActivityLog::query()
            ->when($startDate, fn($query) => $query->whereDate('started_at', '>=', $startDate->toDateString()))
            ->when($endDate, fn($query) => $query->whereDate('started_at', '<=', $endDate->toDateString()))
            ->get(['session_id', 'urls_visited', 'started_at', 'created_at']);

        $entries = collect();

        foreach ($logs as $log) {
            $defaultTimestamp = $this->resolveTimestamp(null, null, $log->started_at, $log->created_at);
            $sessionId = $log->session_id ? (string) $log->session_id : null;

            foreach ($this->normaliseIterable($log->urls_visited) as $rawEntry) {
                $entry = $this->normalisePageViewEntry($log, $rawEntry, $defaultTimestamp);

                if ($entry === null) {
                    continue;
                }

                $path = $pathResolver($entry['path']);

                if ($path === null) {
                    continue;
                }

                $entrySource = $entry['source'] ?? null;

                if ($source !== null && $entrySource !== $source) {
                    continue;
                }

                $entries->push([
                    'session_id' => $sessionId,
                    'path' => $path,
                    'timestamp' => $entry['timestamp'],
                ]);
            }
        }

        if ($entries->isEmpty()) {
            return [
                'title' => $title,
                'description' => $description,
                'stats' => [],
                'items' => [],
                'series' => [],
            ];
        }

        $totalViews = $entries->count();
        $uniquePages = $entries->pluck('path')->unique()->count();
        $sessionsWithViews = $entries->pluck('session_id')->filter()->unique()->count();

        $slug = $slug ? $this->slugify($slug) : $this->slugify($title);

        $stats = [
            $this->makeMetric($slug . '-views', $title . ' page views', $totalViews),
            $this->makeMetric($slug . '-unique-pages', 'Unique pages viewed', $uniquePages),
            $this->makeMetric($slug . '-sessions', 'Sessions viewing ' . strtolower($title), $sessionsWithViews),
        ];

        $items = $entries
            ->groupBy('path')
            ->map(function (Collection $group, string $path) use ($slug) {
                $views = $group->count();
                $uniqueSessions = $group->pluck('session_id')->filter()->unique()->count();
                $firstViewed = $group
                    ->pluck('timestamp')
                    ->filter(fn($timestamp) => $timestamp instanceof Carbon)
                    ->sort()
                    ->first();

                $description = $uniqueSessions > 0
                    ? sprintf('%d unique session%s', $uniqueSessions, $uniqueSessions === 1 ? '' : 's')
                    : null;

                $extra = $firstViewed instanceof Carbon ? $firstViewed->toDateString() : null;

                return $this->makeMetric(
                    $slug . '-page-' . $this->slugify($path),
                    $path,
                    $views,
                    null,
                    $description,
                    $extra,
                );
            })
            ->sortByDesc(fn(array $metric) => (int) ($metric['value'] ?? 0))
            ->values()
            ->all();

        $series = $entries
            ->groupBy(function (array $entry) use ($interval) {
                return $this->formatIntervalKey($entry['timestamp'] ?? null, $interval);
            })
            ->map(function (Collection $group, string $label) {
                return [
                    'label' => $label,
                    'value' => $group->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'title' => $title,
            'description' => $description,
            'stats' => $stats,
            'items' => $items,
            'series' => $series,
        ];
    }

    private function buildSearchSection(Collection $searchEvents, ?Carbon $startDate, ?Carbon $endDate, string $interval): array
    {
        $metrics = $this->dashboardService
            ->searchMetrics(null, $startDate?->toDateString(), $endDate?->toDateString())
            ->values();

        $saccoNames = Sacco::query()
            ->whereIn('sacco_id', $metrics->pluck('sacco_id')->filter()->unique())
            ->pluck('sacco_name', 'sacco_id');

        $totalSearches = $searchEvents->count();
        $successfulSearches = $searchEvents->where('has_results', true)->count();
        $successRate = $totalSearches > 0 ? $successfulSearches / $totalSearches : 0.0;

        $byInterval = $searchEvents
            ->groupBy(fn($event) => $this->formatIntervalKey($event['searched_at'], $interval))
            ->map(function (Collection $events) {
                $total = $events->count();
                $successful = $events->where('has_results', true)->count();

                return [
                    'total' => $total,
                    'successful' => $successful,
                    'rate' => $total > 0 ? $successful / $total : 0.0,
                ];
            })
            ->sortKeys();

        $series = $byInterval
            ->map(function (array $data, string $label) {
                $total = (int) ($data['total'] ?? 0);
                $successful = (int) ($data['successful'] ?? 0);
                $rate = $total > 0 ? (float) ($data['rate'] ?? 0.0) : 0.0;
                $percentage = $total > 0 ? round($rate * 100, 1) : 0.0;

                return [
                    'label' => $label,
                    'value' => $total,
                    'total' => $total,
                    'successful' => $successful,
                    'rate' => $rate,
                    'description' => sprintf('%d successful (%.1f%%)', $successful, $percentage),
                ];
            })
            ->values()
            ->all();

        $stats = [
            $this->makeMetric(
                'search-total',
                'Total searches',
                $totalSearches,
            ),
            $this->makeMetric(
                'search-successful',
                'Successful searches',
                $successfulSearches,
                description: 'Searches returning at least one result',
            ),
            $this->makeMetric(
                'search-success-rate',
                'Success rate (%)',
                $totalSearches > 0 ? round($successRate * 100, 2) : 0.0,
                description: 'Share of searches that returned any results',
            ),
        ];

        $saccoItems = $metrics
            ->values()
            ->toBase()
            ->map(function ($metric, int $index) use ($saccoNames) {
                $saccoId = $metric->sacco_id ?? 'unknown';
                $label = $saccoNames[$metric->sacco_id] ?? $metric->sacco_id ?? 'Unknown sacco';

                return $this->makeMetric(
                    'search-sacco-' . $this->slugify((string) $saccoId) . '-' . $index,
                    $label,
                    (int) $metric->appearances,
                    description: $metric->sacco_id ? 'Sacco ID: ' . $metric->sacco_id : null,
                    extra: $metric->avg_rank !== null
                    ? 'Avg rank ' . number_format((float) $metric->avg_rank, 1)
                    : null,
                );
            });

        $intervalItems = $byInterval->map(function (array $data, string $key) {
            $successful = (int) ($data['successful'] ?? 0);
            $total = (int) ($data['total'] ?? 0);
            $rate = $total > 0 ? round(((float) ($data['rate'] ?? 0)) * 100, 1) : 0.0;

            return $this->makeMetric(
                'search-interval-' . $this->slugify($key),
                $key,
                $total,
                description: sprintf('%d successful (%.1f%%)', $successful, $rate),
            );
        });

        return [
            'stats' => array_values($stats),
            'items' => $saccoItems
                ->values()
                ->merge($intervalItems->values())
                ->all(),
            'series' => $series,
        ];
    }

    private function buildSearchHeatmapSection(Collection $searchEvents): array
    {
        $precision = 3;

        $allSearchBuckets = $this->bucketSearchEvents($searchEvents, $precision);
        $noResultBuckets = $this->bucketSearchEvents(
            $searchEvents->filter(fn(array $event) => !($event['has_results'] ?? false)),
            $precision
        );

        $datasets = [
            'all_searches' => [
                'label' => 'All searches',
                'points' => $this->formatHeatmapPoints($allSearchBuckets),
            ],
            'no_result_searches' => [
                'label' => 'No-result searches',
                'points' => $this->formatHeatmapPoints($noResultBuckets),
            ],
        ];

        return [
            'metadata' => [
                'bucket_precision' => $precision,
                'total_events' => $searchEvents->count(),
                'total_no_result_events' => $searchEvents->where('has_results', false)->count(),
            ],
            'heatmap' => [
                'datasets' => $datasets,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<int, array<string, mixed>>
     */
    private function formatHeatmapPoints(array $buckets): array
    {
        return array_values(array_map(function (array $bucket): array {
            $averageLat = isset($bucket['average_lat']) ? (float) $bucket['average_lat'] : null;
            $averageLng = isset($bucket['average_lng']) ? (float) $bucket['average_lng'] : null;

            return [
                'id' => $bucket['bucket'] ?? null,
                'bucket' => $bucket['bucket'] ?? null,
                'lat' => $averageLat,
                'lng' => $averageLng,
                'average_lat' => $averageLat,
                'average_lng' => $averageLng,
                'count' => isset($bucket['count']) ? (int) $bucket['count'] : 0,
                'label' => $bucket['label'] ?? null,
                'source_counts' => $bucket['source_counts'] ?? ['origin' => 0, 'destination' => 0],
            ];
        }, $buckets));
    }

    private function collectSearchEvents(?Carbon $startDate, ?Carbon $endDate): Collection
    {
        $searchLogs = ActivityLog::query()
            ->when($startDate, fn($query) => $query->whereDate('started_at', '>=', $startDate->toDateString()))
            ->when($endDate, fn($query) => $query->whereDate('started_at', '<=', $endDate->toDateString()))
            ->get(['session_id', 'routes_searched', 'started_at', 'created_at']);

        $searchEvents = collect();

        foreach ($searchLogs as $log) {
            $entries = $this->normaliseIterable($log->routes_searched);

            foreach ($entries as $entry) {
                if (is_object($entry)) {
                    $entry = (array) $entry;
                }

                if (!is_array($entry)) {
                    continue;
                }

                $searchedAt = $this->resolveTimestamp(
                    $entry['searched_at'] ?? null,
                    $entry['timestamp'] ?? null,
                    $log->started_at,
                    $log->created_at
                );

                if ($startDate && $searchedAt && $searchedAt->lt($startDate)) {
                    continue;
                }

                if ($endDate && $searchedAt && $searchedAt->gt($endDate)) {
                    continue;
                }

                $searchEvents->push([
                    'searched_at' => $searchedAt,
                    'has_results' => (bool) ($entry['has_results'] ?? $entry['success'] ?? false),
                    'origin' => $this->normaliseCoordinatePair($entry['origin'] ?? null),
                    'destination' => $this->normaliseCoordinatePair($entry['destination'] ?? null),
                    'origin_label' => $this->normaliseLabel($entry['origin_label'] ?? null),
                    'destination_label' => $this->normaliseLabel($entry['destination_label'] ?? null),
                ]);
            }
        }

        return $searchEvents;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $searchEvents
     * @return array<string, array<string, mixed>>
     */
    private function bucketSearchEvents(Collection $searchEvents, int $precision): array
    {
        $buckets = [];

        foreach ($searchEvents as $event) {
            foreach (['origin', 'destination'] as $type) {
                $coordinates = $this->normaliseCoordinatePair($event[$type] ?? null);

                if ($coordinates === null) {
                    continue;
                }

                $lat = $coordinates['lat'];
                $lng = $coordinates['lng'];

                $bucketKey = $this->formatBucketKey($lat, $lng, $precision);

                if (!isset($buckets[$bucketKey])) {
                    $buckets[$bucketKey] = [
                        'bucket' => $bucketKey,
                        'count' => 0,
                        'lat_sum' => 0.0,
                        'lng_sum' => 0.0,
                        'labels' => [],
                        'source_counts' => [
                            'origin' => 0,
                            'destination' => 0,
                        ],
                    ];
                }

                $labelKey = $type . '_label';
                $label = $event[$labelKey] ?? null;

                if ($label !== null) {
                    $buckets[$bucketKey]['labels'][$label] = ($buckets[$bucketKey]['labels'][$label] ?? 0) + 1;
                }

                $buckets[$bucketKey]['count']++;
                $buckets[$bucketKey]['lat_sum'] += $lat;
                $buckets[$bucketKey]['lng_sum'] += $lng;
                $buckets[$bucketKey]['source_counts'][$type]++;
            }
        }

        foreach ($buckets as &$bucket) {
            $count = max(1, $bucket['count']);
            $bucket['average_lat'] = round($bucket['lat_sum'] / $count, 6);
            $bucket['average_lng'] = round($bucket['lng_sum'] / $count, 6);
            $bucket['label'] = $this->resolveDominantLabel($bucket['labels']);
            unset($bucket['lat_sum'], $bucket['lng_sum'], $bucket['labels']);
        }

        unset($bucket);

        uasort($buckets, function (array $left, array $right) {
            if ($left['count'] === $right['count']) {
                return strcmp($left['bucket'], $right['bucket']);
            }

            return $right['count'] <=> $left['count'];
        });

        return $buckets;
    }

    private function formatBucketKey(float $lat, float $lng, int $precision): string
    {
        $roundedLat = number_format(round($lat, $precision), $precision, '.', '');
        $roundedLng = number_format(round($lng, $precision), $precision, '.', '');

        return $roundedLat . ',' . $roundedLng;
    }

    private function normaliseCoordinatePair($value): ?array
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $lat = $this->toFloat($value[0] ?? null);
                $lng = $this->toFloat($value[1] ?? null);
            } else {
                $lat = $this->toFloat($value['lat'] ?? $value['latitude'] ?? null);
                $lng = $this->toFloat($value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? null);
            }
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->normaliseCoordinatePair($decoded);
            }

            $parts = array_map('trim', explode(',', $value));
            if (count($parts) === 2) {
                $lat = $this->toFloat($parts[0]);
                $lng = $this->toFloat($parts[1]);
            } else {
                $lat = $lng = null;
            }
        } else {
            $lat = $lng = null;
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];
    }

    private function toFloat($value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function normaliseLabel($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $label = trim($value);

        return $label !== '' ? $label : null;
    }

    /**
     * @param  array<string, int>  $labels
     */
    private function resolveDominantLabel(array $labels): string
    {
        if ($labels === []) {
            return 'Unknown location';
        }

        arsort($labels);

        return (string) array_key_first($labels);
    }

    private function buildEngagementSection(?Carbon $startDate, ?Carbon $endDate, string $interval): array
    {
        $logs = ActivityLog::query()
            ->when($startDate, fn($query) => $query->whereDate('started_at', '>=', $startDate->toDateString()))
            ->when($endDate, fn($query) => $query->whereDate('started_at', '<=', $endDate->toDateString()))
            ->get(['session_id', 'device', 'browser', 'urls_visited', 'started_at', 'ended_at', 'duration_seconds', 'created_at']);

        $uniqueSessions = $logs
            ->pluck('session_id')
            ->filter()
            ->unique()
            ->count();

        $deviceBreakdown = $logs
            ->groupBy(fn($log) => $log->device ?: 'Unknown')
            ->map(fn(Collection $group) => $group->pluck('session_id')->filter()->unique()->count())
            ->sortKeys();

        $browserBreakdown = $logs
            ->groupBy(fn($log) => $log->browser ?: 'Unknown')
            ->map(fn(Collection $group) => $group->pluck('session_id')->filter()->unique()->count())
            ->sortKeys();

        $pageCounts = [];
        foreach ($logs as $log) {
            $defaultTimestamp = $this->resolveTimestamp(null, null, $log->started_at, $log->created_at);
            foreach ($this->normaliseIterable($log->urls_visited) as $rawEntry) {
                $entry = $this->normalisePageViewEntry($log, $rawEntry, $defaultTimestamp);
                if ($entry === null) {
                    continue;
                }

                $path = $entry['path'];
                $pageCounts[$path] = ($pageCounts[$path] ?? 0) + 1;
            }
        }
        ksort($pageCounts);

        $byInterval = $logs
            ->groupBy(function ($log) use ($interval) {
                $timestamp = $this->resolveTimestamp(null, null, $log->started_at, $log->created_at);

                return $this->formatIntervalKey($timestamp, $interval);
            })
            ->map(function (Collection $group) {
                $sessions = $group->pluck('session_id')->filter()->unique()->count();
                $pageViews = $group->reduce(function ($carry, $log) {
                    $defaultTimestamp = $this->resolveTimestamp(null, null, $log->started_at, $log->created_at);
                    $count = 0;

                    foreach ($this->normaliseIterable($log->urls_visited) as $rawEntry) {
                        if ($this->normalisePageViewEntry($log, $rawEntry, $defaultTimestamp)) {
                            $count++;
                        }
                    }

                    return $carry + $count;
                }, 0);

                return [
                    'sessions' => $sessions,
                    'page_views' => $pageViews,
                ];
            })
            ->sortKeys();

        $series = $byInterval
            ->map(function (array $data, string $label) {
                return [
                    'label' => $label,
                    'value' => (int) ($data['sessions'] ?? 0),
                    'sessions' => (int) ($data['sessions'] ?? 0),
                    'page_views' => (int) ($data['page_views'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $totalPageViews = array_sum($pageCounts);
        $avgPagesPerSession = $uniqueSessions > 0 ? round($totalPageViews / $uniqueSessions, 2) : 0.0;
        $totalDurationSeconds = $logs->reduce(function (int $carry, $log) {
            $duration = (int) ($log->duration_seconds ?? 0);

            if ($duration <= 0 && ($log->started_at || $log->ended_at)) {
                $start = $this->resolveTimestamp(null, null, $log->started_at, null);
                $end = $this->resolveTimestamp(null, null, $log->ended_at, null);

                if ($start && $end && $end->greaterThan($start)) {
                    $duration = $end->diffInSeconds($start);
                }
            }

            return $carry + max(0, $duration);
        }, 0);
        $avgSessionMinutes = $uniqueSessions > 0
            ? round(($totalDurationSeconds / $uniqueSessions) / 60, 1)
            : 0.0;

        $stats = [
            $this->makeMetric('engagement-sessions', 'Unique sessions', $uniqueSessions),
            $this->makeMetric('engagement-page-views', 'Page views', $totalPageViews),
            $this->makeMetric(
                'engagement-pages-per-session',
                'Pages per session',
                $avgPagesPerSession,
                description: 'Average pages visited per tracked session',
            ),
            $this->makeMetric(
                'engagement-session-duration',
                'Avg session duration (min)',
                $avgSessionMinutes,
                description: 'Mean duration of tracked sessions',
            ),
        ];

        $deviceItems = $deviceBreakdown->map(function ($count, $device) {
            return $this->makeMetric(
                'engagement-device-' . $this->slugify($device),
                $device,
                (int) $count,
                description: 'Unique sessions by device',
                extra: 'Device'
            );
        });

        $browserItems = $browserBreakdown->map(function ($count, $browser) {
            return $this->makeMetric(
                'engagement-browser-' . $this->slugify($browser),
                $browser,
                (int) $count,
                description: 'Unique sessions by browser',
                extra: 'Browser'
            );
        });

        $pageItems = collect($pageCounts)
            ->sortDesc()
            ->take(10)
            ->map(function ($count, $page) {
                return $this->makeMetric(
                    'engagement-page-' . $this->slugify($page),
                    $page,
                    (int) $count,
                    description: 'Visits to this page',
                    extra: 'Page'
                );
            });

        return [
            'stats' => array_values($stats),
            'items' => $deviceItems
                ->values()
                ->merge($browserItems->values())
                ->merge($pageItems->values())
                ->all(),
            'series' => $series,
        ];
    }

    private function buildOnboardingSection(?Carbon $startDate, ?Carbon $endDate, string $interval): array
    {
        $roleLabels = [
            UserRole::SUPER_ADMIN => 'Super admins',
            UserRole::SERVICE_PERSON => 'Service personnel',
            UserRole::DRIVER => 'Drivers',
            UserRole::GOVERNMENT => 'Government officials',
            UserRole::SACCO => 'Sacco admins',
            UserRole::VEHICLE_OWNER => 'Vehicle owners',
            UserRole::USER => 'Commuters',
        ];

        $usersQuery = User::query();

        if ($startDate) {
            $usersQuery->whereDate('created_at', '>=', $startDate->toDateString());
        }

        if ($endDate) {
            $usersQuery->whereDate('created_at', '<=', $endDate->toDateString());
        }

        $users = $usersQuery
            ->whereNotNull('role')
            ->get(['id', 'name', 'email', 'role', 'is_verified', 'created_at']);

        $totalUsers = $users->count();
        $verifiedUsers = $users->where('is_verified', true)->count();
        $pendingUsers = $totalUsers - $verifiedUsers;
        $verifiedShare = $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100, 1) : null;

        $stats = [
            $this->makeMetric('onboarding-total', 'Total signups', $totalUsers),
            $this->makeMetric(
                'onboarding-verified',
                'Verified users',
                $verifiedUsers,
                description: $verifiedShare !== null
                ? sprintf('%.1f%% of signups verified', $verifiedShare)
                : null,
            ),
            $this->makeMetric(
                'onboarding-pending',
                'Pending verification',
                $pendingUsers,
                description: 'Users awaiting verification',
            ),
        ];

        $byInterval = $users
            ->groupBy(function (User $user) use ($interval) {
                $createdAt = $user->created_at instanceof Carbon ? $user->created_at : null;

                return $this->formatIntervalKey($createdAt, $interval);
            })
            ->map(fn(Collection $group) => ['count' => $group->count()])
            ->sortKeys();

        $series = $byInterval
            ->map(function (array $data, string $label) {
                return [
                    'label' => $label,
                    'value' => (int) ($data['count'] ?? 0),
                    'count' => (int) ($data['count'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $roleItems = $users
            ->groupBy(fn(User $user) => $user->role ?: 'unknown')
            ->sortByDesc(fn(Collection $group) => $group->count())
            ->map(function (Collection $group, string $role) use ($roleLabels) {
                $label = $roleLabels[$role] ?? ucwords(str_replace('_', ' ', $role ?: 'Unknown role'));
                $total = $group->count();
                $verified = $group->where('is_verified', true)->count();
                $pending = $total - $verified;
                $verifiedShare = $total > 0 ? round(($verified / $total) * 100, 1) : null;

                $extra = $verifiedShare !== null
                    ? rtrim(rtrim(number_format($verifiedShare, 1, '.', ''), '0'), '.') . '% verified'
                    : null;

                return $this->makeMetric(
                    'onboarding-role-' . $this->slugify($role ?: 'unknown'),
                    $label,
                    $total,
                    description: sprintf('%d verified, %d pending', $verified, $pending),
                    extra: $extra,
                );
            })
            ->values();

        $recentSignups = $users
            ->sortByDesc(fn(User $user) => $user->created_at instanceof Carbon ? $user->created_at->timestamp : 0)
            ->take(10)
            ->values()
            ->map(function (User $user) use ($roleLabels) {
                $label = $user->name ?: ($user->email ?: 'User #' . $user->id);
                $roleLabel = $roleLabels[$user->role] ?? ucwords(str_replace('_', ' ', $user->role ?: 'Unknown role'));
                $status = $user->is_verified ? 'Verified' : 'Pending verification';
                $createdAt = $user->created_at instanceof Carbon ? $user->created_at->toDateString() : null;

                return $this->makeMetric(
                    'onboarding-user-' . $this->slugify((string) $user->id),
                    $label,
                    1,
                    description: sprintf('%s · %s', $roleLabel, $status),
                    extra: $createdAt,
                );
            });

        return [
            'stats' => array_values($stats),
            'items' => $roleItems
                ->merge($recentSignups)
                ->all(),
            'series' => $series,
        ];
    }

    private function buildRoutesSection(?Carbon $startDate, ?Carbon $endDate, string $interval): array
    {
        $entries = collect();
        $logDirectory = storage_path('logs');
        if (is_dir($logDirectory)) {
            $files = glob($logDirectory . '/saccoroute_publish*.log') ?: [];
            foreach ($files as $file) {
                $handle = fopen($file, 'r');
                if ($handle === false) {
                    continue;
                }

                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $payload = json_decode($line, true);
                    if (!is_array($payload)) {
                        continue;
                    }

                    $timestamp = $this->resolveTimestamp(
                        $payload['published_at'] ?? null,
                        $payload['timestamp'] ?? null,
                        null,
                        null
                    );

                    if ($startDate && $timestamp && $timestamp->lt($startDate)) {
                        continue;
                    }
                    if ($endDate && $timestamp && $timestamp->gt($endDate)) {
                        continue;
                    }

                    $entries->push([
                        'sacco_route_id' => $payload['sacco_route_id'] ?? null,
                        'created_by_role' => $payload['created_by_role'] ?? 'unknown',
                        'created_by' => $payload['created_by'] ?? $payload['user'] ?? null,
                        'published_at' => $timestamp,
                        'payload' => $payload,
                    ]);
                }

                fclose($handle);
            }
        }

        $byRole = $entries
            ->groupBy(fn($entry) => $entry['created_by_role'] ?? 'unknown')
            ->map(function (Collection $group) {
                return [
                    'count' => $group->count(),
                    'routes' => $group
                        ->map(function ($entry) {
                            return [
                                'sacco_route_id' => $entry['sacco_route_id'],
                                'published_at' => $entry['published_at']?->toIso8601String(),
                                'created_by' => $entry['created_by'],
                            ];
                        })
                        ->values(),
                ];
            })
            ->sortKeys();

        $byInterval = $entries
            ->groupBy(function ($entry) use ($interval) {
                return $this->formatIntervalKey($entry['published_at'], $interval);
            })
            ->map(fn(Collection $group) => ['count' => $group->count()])
            ->sortKeys();

        $series = $byInterval
            ->map(function (array $data, string $label) {
                return [
                    'label' => $label,
                    'value' => (int) ($data['count'] ?? 0),
                    'count' => (int) ($data['count'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $total = $entries->count();
        $uniqueRoutes = $entries
            ->pluck('sacco_route_id')
            ->filter()
            ->unique()
            ->count();

        $stats = [
            $this->makeMetric('routes-total', 'Routes published', $total),
            $this->makeMetric(
                'routes-unique',
                'Unique routes',
                $uniqueRoutes,
                description: 'Distinct sacco routes published in the period',
            ),
            $this->makeMetric(
                'routes-roles',
                'Publishing roles',
                $byRole->count(),
                description: 'Distinct roles publishing routes',
            ),
        ];

        $roleItems = $byRole->map(function (array $data, string $role) {
            return $this->makeMetric(
                'routes-role-' . $this->slugify($role),
                ucfirst(str_replace('_', ' ', $role)),
                (int) ($data['count'] ?? 0),
                description: 'Routes published by this role',
                extra: 'Role'
            );
        });

        $recentRoutes = $entries
            ->sortByDesc('published_at')
            ->take(10)
            ->values()
            ->map(function ($entry, int $index) {
                $identifier = $entry['sacco_route_id'] ?? ($entry['created_by'] ?? uniqid('route', true));
                $publishedAt = $entry['published_at'] instanceof Carbon
                    ? $entry['published_at']->toIso8601String()
                    : null;

                return $this->makeMetric(
                    'routes-entry-' . $this->slugify((string) $identifier) . '-' . $index,
                    (string) ($entry['sacco_route_id'] ?? 'Route publish'),
                    1,
                    description: $entry['created_by'] ? 'Published by ' . $entry['created_by'] : null,
                    extra: $publishedAt,
                );
            });

        return [
            'stats' => array_values($stats),
            'items' => $roleItems
                ->values()
                ->merge($byInterval->map(function (array $data, string $intervalKey) {
                    return $this->makeMetric(
                        'routes-interval-' . $this->slugify($intervalKey),
                        $intervalKey,
                        (int) ($data['count'] ?? 0),
                        description: 'Routes published during this interval'
                    );
                })->values())
                ->merge($recentRoutes->values())
                ->all(),
            'series' => $series,
        ];
    }

    private function buildSocialSection(): array
    {
        $accounts = SocialAccount::query()
            ->with([
                'snapshots' => function ($query) {
                    $query->orderByDesc('collected_at')->limit(30);
                },
                'posts' => function ($query) {
                    $query
                        ->orderByDesc('published_at')
                        ->limit(10)
                        ->with([
                            'metrics' => function ($metrics) {
                                $metrics->orderByDesc('collected_at')->limit(2);
                            },
                        ]);
                },
            ])
            ->orderBy('platform')
            ->get();

        if ($accounts->isEmpty()) {
            return [
                'title' => 'Social media',
                'description' => 'Performance across social channels.',
                'stats' => [],
                'items' => [],
                'series' => [],
                'accounts' => [],
            ];
        }

        $totalFollowers = 0;
        $totalInteraction = 0.0;
        $totalPosts = 0;
        $seriesByDate = [];

        $accountPayloads = [];
        $topPosts = [];

        foreach ($accounts as $account) {
            $snapshots = $account->snapshots->sortByDesc('collected_at')->values();
            $currentSnapshot = $snapshots->first();

            if (!$currentSnapshot) {
                continue;
            }

            $previousSnapshot = $snapshots->skip(1)->first();
            $followers = (int) $currentSnapshot->followers;
            $postCount = (int) $currentSnapshot->post_count;
            $interactionScore = (float) $currentSnapshot->interaction_score;

            $totalFollowers += $followers;
            $totalPosts += $postCount;
            $totalInteraction += $interactionScore;

            foreach ($snapshots->sortBy('collected_at') as $snapshot) {
                $dateKey = $snapshot->collected_at?->toDateString();
                if (!$dateKey) {
                    continue;
                }

                $seriesByDate[$dateKey] = ($seriesByDate[$dateKey] ?? 0) + (float) $snapshot->interaction_score;
            }

            $summaryStats = [
                $this->makeMetric(
                    sprintf('social-%s-followers', $account->platform),
                    'Followers',
                    $followers,
                    $this->calculateDeltaPercentage($followers, $previousSnapshot?->followers),
                    'Latest follower count'
                ),
                $this->makeMetric(
                    sprintf('social-%s-interaction', $account->platform),
                    'Interaction score',
                    round($interactionScore, 2),
                    $currentSnapshot->interaction_score_change_pct,
                    'Weighted interaction score across posts'
                ),
                $this->makeMetric(
                    sprintf('social-%s-posts', $account->platform),
                    'Posts tracked',
                    $postCount,
                    $currentSnapshot->post_count_change_pct,
                    'Posts ingested for this sync window'
                ),
            ];

            $breakdownMetrics = $this->formatBreakdownMetrics($currentSnapshot->metrics_breakdown ?? []);
            $accountSeries = $snapshots
                ->sortBy('collected_at')
                ->map(function ($snapshot, int $index) use ($account) {
                    $date = $snapshot->collected_at?->toDateString();

                    return [
                        'id' => sprintf('social-%s-series-%d', $account->platform, $index),
                        'label' => $date ?? sprintf('Snapshot %d', $index + 1),
                        'value' => round((float) $snapshot->interaction_score, 2),
                        'description' => sprintf('Followers: %s', number_format((int) $snapshot->followers)),
                    ];
                })
                ->values()
                ->all();

            $accountPosts = [];
            foreach ($account->posts as $post) {
                $metricsCollection = $post->metrics->sortByDesc('collected_at')->values();
                $currentMetrics = $metricsCollection->first();
                if (!$currentMetrics) {
                    continue;
                }

                $postMetrics = $currentMetrics->metrics_breakdown ?? [
                    'likes' => $currentMetrics->likes,
                    'comments' => $currentMetrics->comments,
                    'shares' => $currentMetrics->shares,
                    'views' => $currentMetrics->views,
                    'saves' => $currentMetrics->saves,
                    'replies' => $currentMetrics->replies,
                    'clicks' => $currentMetrics->clicks,
                ];

                $postStats = [
                    $this->makeMetric(
                        sprintf('social-post-%s-interaction', $post->id),
                        'Interaction score',
                        round((float) $currentMetrics->interaction_score, 2),
                        $currentMetrics->interaction_score_change_pct,
                        'Weighted engagement for this post'
                    ),
                    $this->makeMetric(
                        sprintf('social-post-%s-views', $post->id),
                        'Views',
                        (int) ($postMetrics['views'] ?? $postMetrics['impressions'] ?? 0)
                    ),
                ];

                $postPayload = [
                    'id' => (string) $post->id,
                    'external_id' => $post->external_id,
                    'permalink' => $post->permalink,
                    'message' => $post->message,
                    'published_at' => $post->published_at?->toIso8601String(),
                    'summary_stats' => $postStats,
                    'metrics' => $this->formatBreakdownMetrics($postMetrics),
                    'platform' => $account->platform,
                ];

                $accountPosts[] = $postPayload;
                $topPosts[] = $postPayload;
            }

            $accountPayloads[] = [
                'id' => (string) $account->id,
                'platform' => $account->platform,
                'label' => $account->display_name ?? ucfirst($account->platform),
                'username' => $account->username,
                'profile_url' => $account->profile_url,
                'avatar_url' => $account->avatar_url,
                'last_synced_at' => ($account->last_synced_at ?? $currentSnapshot->collected_at)?->toIso8601String(),
                'summary_stats' => $summaryStats,
                'breakdown' => $breakdownMetrics,
                'series' => $accountSeries,
                'posts' => $accountPosts,
            ];
        }

        $topPostsCollection = collect($topPosts)
            ->sortByDesc(fn($post) => Arr::get($post, 'summary_stats.0.value', 0))
            ->take(5)
            ->values();

        $series = collect($seriesByDate)
            ->sortKeys()
            ->map(function ($value, $date) {
                return [
                    'id' => 'social-series-' . $date,
                    'label' => $date,
                    'value' => round((float) $value, 2),
                    'description' => 'Total interaction score across all channels',
                ];
            })
            ->values()
            ->all();

        return [
            'title' => 'Social media',
            'description' => 'Follower growth and engagement performance for Nishukishe social accounts.',
            'stats' => [
                $this->makeMetric('social-total-followers', 'Total followers', $totalFollowers),
                $this->makeMetric('social-total-interaction', 'Interaction score', round($totalInteraction, 2)),
                $this->makeMetric('social-total-posts', 'Posts tracked', $totalPosts),
            ],
            'items' => $topPostsCollection
                ->map(function ($post) {
                    $value = Arr::get($post, 'summary_stats.0.value', 0);
                    $delta = Arr::get($post, 'summary_stats.0.delta');

                    return $this->makeMetric(
                        sprintf('social-top-%s', $post['id']),
                        sprintf('%s • %s', ucfirst($post['platform'] ?? 'social'), $post['external_id'] ?? 'post'),
                        $value,
                        is_numeric($delta) ? (float) $delta : null,
                        $post['message'] ? mb_substr($post['message'], 0, 120) : null,
                        $post['permalink'] ?? null
                    );
                })
                ->all(),
            'series' => $series,
            'accounts' => $accountPayloads,
        ];
    }

    private function calculateDeltaPercentage(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if (abs($previous) < 0.00001) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<int, array<string, mixed>>
     */
    private function formatBreakdownMetrics(array $metrics): array
    {
        $labels = [
            'likes' => 'Likes',
            'comments' => 'Comments',
            'shares' => 'Shares',
            'views' => 'Views',
            'impressions' => 'Impressions',
            'saves' => 'Saves',
            'replies' => 'Replies',
            'reposts' => 'Reposts',
            'quotes' => 'Quotes',
            'clicks' => 'Clicks',
            'engagement' => 'Engagement',
            'reactions' => 'Reactions',
        ];

        $formatted = [];
        foreach ($metrics as $key => $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $slug = strtolower((string) $key);
            $label = $labels[$slug] ?? ucwords(str_replace('_', ' ', (string) $key));

            $formatted[] = [
                'id' => 'metric-' . $slug,
                'label' => $label,
                'value' => $value + 0,
            ];
        }

        return $formatted;
    }

    /**
     * @param  iterable<mixed>|null  $value
     * @return array<int, mixed>
     */
    private function normaliseIterable($value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return [];
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return Arr::wrap($value);
    }

    private function normalisePageViewEntry(ActivityLog $log, $rawEntry, ?Carbon $defaultTimestamp): ?array
    {
        $pathValue = null;
        $timestamp = $defaultTimestamp;
        $source = null;

        if (is_string($rawEntry)) {
            $pathValue = $rawEntry;
        } elseif (is_object($rawEntry)) {
            $rawEntry = (array) $rawEntry;
        }

        if (is_array($rawEntry)) {
            $pathValue = $rawEntry['path'] ?? $rawEntry['url'] ?? $rawEntry['href'] ?? $pathValue;

            $sourceValue = $rawEntry['source'] ?? null;
            if (is_string($sourceValue)) {
                $sourceValue = trim($sourceValue);
                $source = $sourceValue !== '' ? $sourceValue : null;
            }

            $timestamp = $this->resolveTimestamp(
                $rawEntry['viewed_at'] ?? null,
                $rawEntry['timestamp'] ?? ($rawEntry['seen_at'] ?? null),
                $log->started_at,
                $log->created_at
            ) ?? $timestamp;
        }

        if (!is_string($pathValue) || $pathValue === '') {
            return null;
        }

        $path = parse_url($pathValue, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $pathValue;
        }

        if ($path === '') {
            return null;
        }

        $path = '/' . ltrim($path, '/');

        return [
            'path' => $path,
            'timestamp' => $timestamp,
            'source' => $source,
        ];
    }

    private function normaliseDirectionsPath(string $path): ?string
    {
        if (!Str::startsWith($path, ['/directions', '/direction'])) {
            return null;
        }

        if (
            (Str::startsWith($path, '/direction/') || $path === '/direction')
            && !Str::startsWith($path, '/directions')
        ) {
            $suffix = Str::of($path)->after('/direction');
            $path = (string) Str::of('/directions' . $suffix)->replaceMatches('/\/\/+/', '/');
        }

        if (Str::startsWith($path, '/directions/search')) {
            return null;
        }

        return $path;
    }

    private function normaliseDiscoverSaccoPath(string $path): ?string
    {
        if (!Str::startsWith($path, '/discover')) {
            return null;
        }

        if (Str::contains($path, '/stages/')) {
            return null;
        }

        return $path;
    }

    private function normaliseStagePath(string $path): ?string
    {
        if (!Str::startsWith($path, '/discover/')) {
            return null;
        }

        if (!Str::contains($path, '/stages/')) {
            return null;
        }

        return $path;
    }

    private function normaliseRange(?string $start, ?string $end): array
    {
        $startDate = $start ? Carbon::parse($start)->startOfDay() : null;
        $endDate = $end ? Carbon::parse($end)->endOfDay() : null;

        if ($startDate && $endDate && $startDate->greaterThan($endDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [$startDate, $endDate];
    }

    private function normaliseInterval(?string $interval): string
    {
        return in_array($interval, ['day', 'week', 'month', 'year'], true) ? $interval : 'day';
    }

    private function formatIntervalKey(?Carbon $date, string $interval): string
    {
        if (!$date instanceof Carbon) {
            return 'unknown';
        }

        return match ($interval) {
            'week' => $date->isoFormat('GGGG-[W]WW'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->toDateString(),
        };
    }

    private function resolveTimestamp(?string $primary, ?string $fallback, $default1, $default2): ?Carbon
    {
        foreach ([$primary, $fallback] as $value) {
            if ($value) {
                try {
                    return Carbon::parse($value);
                } catch (\Exception) {
                    continue;
                }
            }
        }

        foreach ([$default1, $default2] as $default) {
            if ($default instanceof Carbon) {
                return $default->copy();
            }
            if ($default) {
                try {
                    return Carbon::parse($default);
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }

    private function makeMetric(
        string $id,
        string $label,
        int|float|null $value = null,
        int|float|null $delta = null,
        ?string $description = null,
        ?string $extra = null
    ): array {
        $metric = [
            'id' => $id,
            'label' => $label,
        ];

        if ($value !== null) {
            $metric['value'] = $value;
        }

        if ($delta !== null) {
            $metric['delta'] = $delta;
        }

        if ($description !== null && $description !== '') {
            $metric['description'] = $description;
        }

        if ($extra !== null && $extra !== '') {
            $metric['extra'] = $extra;
        }

        return $metric;
    }

    private function slugify(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized);

        if ($normalized === null) {
            $normalized = '';
        }

        return trim($normalized, '-') ?: 'item';
    }

    public function logDirectionSearch(
        string $originSlug,
        string $destSlug,
        bool $hasResult,
        ?array $query = null,
        ?float $originLat = null,
        ?float $originLng = null,
        ?float $destLat = null,
        ?float $destLng = null
    ): void {
        SearchLog::create([
            'source' => 'directions_guide',
            'origin_slug' => $originSlug,
            'destination_slug' => $destSlug,
            'has_result' => $hasResult,
            'query' => $query,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'destination_lat' => $destLat,
            'destination_lng' => $destLng,
        ]);
    }

    public function getDeadGuides(?string $start = null, ?string $end = null): array
    {
        $query = SearchLog::query()
            ->where('source', 'directions_guide')
            ->where('has_result', false);

        if ($start) {
            $query->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $query->whereDate('created_at', '<=', $end);
        }

        return $query->selectRaw('origin_slug, destination_slug, count(*) as count')
            ->groupBy('origin_slug', 'destination_slug')
            ->orderByDesc('count')
            ->limit(50)
            ->get()
            ->map(fn($log) => [
                'origin' => $log->origin_slug,
                'destination' => $log->destination_slug,
                'count' => $log->count,
            ])
            ->toArray();
    }

    public function getZeroResultSearches(?string $start = null, ?string $end = null, int $perPage = 20, ?int $page = null)
    {
        [$startDate, $endDate] = $this->normaliseRange($start, $end);

        return SearchLog::query()
            ->where('has_result', false)
            ->when($startDate, fn($q) => $q->whereDate('created_at', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('created_at', '<=', $endDate))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
