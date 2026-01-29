<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\ActivityLog;
use App\Models\Sacco;
use App\Models\SearchMetric;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $logDirectory = storage_path('logs');
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }

        foreach (glob($logDirectory . '/saccoroute_publish*.log') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_requires_super_admin_authentication(): void
    {
        $response = $this->getJson('/superadmin/analytics');

        $response->assertStatus(401);
    }

    public function test_returns_analytics_payload(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();
        $this->seedAnalyticsData();

        $response = $this->getJson('/superadmin/analytics?start=2024-01-01&end=2024-01-31&interval=day');

        $response->assertOk();
        $response->assertJsonStructure([
            'generated_at',
            'range' => ['start', 'end', 'interval'],
            'search' => ['stats', 'items', 'series'],
            'search_heatmap' => [
                'metadata' => ['bucket_precision', 'total_events', 'total_no_result_events'],
                'heatmap' => [
                    'datasets' => [
                        'all_searches' => ['label', 'points'],
                        'no_result_searches' => ['label', 'points'],
                    ],
                ],
            ],
            'engagement' => ['stats', 'items', 'series'],
            'onboarding' => ['stats', 'items', 'series'],
            'discover_sacco' => ['stats', 'items', 'series'],
            'stage_pages' => ['stats', 'items', 'series'],
            'directions' => ['stats', 'items', 'series'],
            'routes' => ['stats', 'items', 'series'],
            'social' => ['stats', 'items'],
        ]);

        $this->assertEquals('2024-01-01', $response->json('range.start'));
        $this->assertEquals('2024-01-31', $response->json('range.end'));
        $this->assertEquals('day', $response->json('range.interval'));

        $searchStats = $response->json('search.stats');
        $this->assertEquals(3, $this->getMetricValue($searchStats, 'search-total'));
        $this->assertEquals(2, $this->getMetricValue($searchStats, 'search-successful'));
        $this->assertEqualsWithDelta(66.67, $this->getMetricValue($searchStats, 'search-success-rate'), 0.01);

        $searchItems = collect($response->json('search.items'));
        $this->assertNotNull($searchItems->firstWhere('label', 'City Sacco'));
        $this->assertNotNull($searchItems->firstWhere('label', 'Metro Sacco'));
        $this->assertNotNull($searchItems->firstWhere('label', '2024-01-05'));

        $searchSeries = collect($response->json('search.series'));
        $this->assertNotEmpty($searchSeries);
        $this->assertTrue(
            $searchSeries->contains(fn(array $point) => (int) ($point['value'] ?? 0) > 0),
            'Expected at least one search series point to have a non-zero value.'
        );

        $heatmapMetadata = $response->json('search_heatmap.metadata');
        $this->assertEquals(3, $heatmapMetadata['total_events']);
        $this->assertEquals(1, $heatmapMetadata['total_no_result_events']);
        $this->assertEquals(3, $heatmapMetadata['bucket_precision']);

        $heatmap = $response->json('search_heatmap.heatmap');
        $this->assertArrayHasKey('datasets', $heatmap);

        $allPoints = collect($response->json('search_heatmap.heatmap.datasets.all_searches.points'));
        $this->assertNotEmpty($allPoints, 'Expected all_searches dataset to contain points');
        $industrialBucket = $allPoints->firstWhere('bucket', '-1.300,36.801');
        $this->assertNotNull($industrialBucket);
        $this->assertEquals('Industrial Area', $industrialBucket['label']);
        $this->assertEquals(1, $industrialBucket['count']);
        $this->assertEquals(1, $industrialBucket['source_counts']['origin']);
        $this->assertEquals(0, $industrialBucket['source_counts']['destination']);

        $cbdBucket = $allPoints->firstWhere('label', 'CBD');
        $this->assertNotNull($cbdBucket);
        $this->assertEqualsWithDelta(-1.286389, $cbdBucket['average_lat'], 0.000001);
        $this->assertEqualsWithDelta(36.817223, $cbdBucket['average_lng'], 0.000001);

        $noResultPoints = collect($response->json('search_heatmap.heatmap.datasets.no_result_searches.points'));
        $this->assertCount(2, $noResultPoints);
        $this->assertNotNull($noResultPoints->firstWhere('label', 'Industrial Area'));
        $this->assertNotNull($noResultPoints->firstWhere('label', 'Airport'));

        $engagementStats = $response->json('engagement.stats');
        $this->assertEquals(3, $this->getMetricValue($engagementStats, 'engagement-sessions'));
        $this->assertEquals(12, $this->getMetricValue($engagementStats, 'engagement-page-views'));
        $this->assertEquals(11.7, $this->getMetricValue($engagementStats, 'engagement-session-duration'));

        $engagementItems = collect($response->json('engagement.items'));
        $this->assertEquals(2, $this->getMetricValue($engagementItems->toArray(), 'engagement-device-desktop'));
        $this->assertEquals(1, $this->getMetricValue($engagementItems->toArray(), 'engagement-device-mobile'));
        $this->assertEquals(2, $this->getMetricValue($engagementItems->toArray(), 'engagement-page-dashboard'));
        $engagementSeries = collect($response->json('engagement.series'));
        $this->assertNotEmpty($engagementSeries);
        $this->assertTrue(
            $engagementSeries->contains(fn(array $point) => (int) ($point['value'] ?? 0) > 0),
            'Expected at least one engagement series point to have a non-zero value.'
        );

        $onboardingStats = $response->json('onboarding.stats');
        $this->assertEquals(2, $this->getMetricValue($onboardingStats, 'onboarding-total'));
        $this->assertEquals(1, $this->getMetricValue($onboardingStats, 'onboarding-verified'));
        $this->assertEquals(1, $this->getMetricValue($onboardingStats, 'onboarding-pending'));

        $onboardingItems = collect($response->json('onboarding.items'));
        $this->assertEquals(1, $this->getMetricValue($onboardingItems->toArray(), 'onboarding-role-commuter'));
        $this->assertEquals(1, $this->getMetricValue($onboardingItems->toArray(), 'onboarding-role-sacco-admin'));
        $this->assertNotNull($onboardingItems->firstWhere('id', 'onboarding-user-2'));
        $this->assertNotNull($onboardingItems->firstWhere('id', 'onboarding-user-3'));
        $onboardingSeries = collect($response->json('onboarding.series'));
        $this->assertNotEmpty($onboardingSeries);
        $this->assertTrue(
            $onboardingSeries->contains(fn(array $point) => (int) ($point['value'] ?? 0) > 0),
            'Expected at least one onboarding series point to have a non-zero value.'
        );

        $routesStats = $response->json('routes.stats');
        $this->assertEquals(1, $this->getMetricValue($routesStats, 'routes-total'));
        $this->assertEquals(1, $this->getMetricValue($routesStats, 'routes-roles'));

        $routesItems = collect($response->json('routes.items'));
        $this->assertEquals(1, $this->getMetricValue($routesItems->toArray(), 'routes-role-super-admin'));
        $this->assertNotNull($routesItems->firstWhere('label', '2024-01-08'));
        $routesSeries = collect($response->json('routes.series'));
        $this->assertNotEmpty($routesSeries);

        $directionsStats = $response->json('directions.stats');
        $this->assertEquals(3, $this->getMetricValue($directionsStats, 'directions-views'));
        $this->assertEquals(3, $this->getMetricValue($directionsStats, 'directions-unique-pages'));
        $this->assertEquals(3, $this->getMetricValue($directionsStats, 'directions-sessions'));

        $directionsItems = collect($response->json('directions.items'));
        $this->assertTrue(
            $directionsItems->contains(fn(array $metric) => str_contains($metric['label'] ?? '', '/directions/cbd/to/westlands')),
            'Expected directions items to include /directions/cbd/to/westlands'
        );
        $this->assertTrue(
            $directionsItems->contains(fn(array $metric) => str_contains($metric['label'] ?? '', '/directions/kikuyu/to/cbd')),
            'Expected directions items to include /directions/kikuyu/to/cbd'
        );
        $this->assertTrue(
            $directionsItems->contains(fn(array $metric) => ($metric['label'] ?? null) === '/directions'),
            'Expected directions items to include the legacy /direction landing page.'
        );
        $this->assertFalse(
            $directionsItems->contains(fn(array $metric) => str_contains($metric['label'] ?? '', '/directions/search')),
            'Expected directions items to exclude the /directions/search API endpoint.'
        );

        $directionsSeries = collect($response->json('directions.series'));
        $this->assertNotEmpty($directionsSeries);
        $this->assertTrue(
            $directionsSeries->contains(fn(array $point) => ($point['label'] ?? null) === '2024-01-05' && (int) ($point['value'] ?? 0) === 1),
            'Expected a directions series point for 2024-01-05 with one view.'
        );

        $discoverStats = $response->json('discover_sacco.stats');
        $this->assertEquals(3, $this->getMetricValue($discoverStats, 'discover-sacco-views'));
        $this->assertEquals(3, $this->getMetricValue($discoverStats, 'discover-sacco-unique-pages'));
        $this->assertEquals(2, $this->getMetricValue($discoverStats, 'discover-sacco-sessions'));

        $stageStats = $response->json('stage_pages.stats');
        $this->assertEquals(2, $this->getMetricValue($stageStats, 'stage-pages-views'));
        $this->assertEquals(2, $this->getMetricValue($stageStats, 'stage-pages-unique-pages'));
        $this->assertEquals(2, $this->getMetricValue($stageStats, 'stage-pages-sessions'));
        $this->assertTrue(
            $routesSeries->contains(fn(array $point) => (int) ($point['value'] ?? 0) > 0),
            'Expected at least one routes series point to have a non-zero value.'
        );
    }

    public function test_heatmap_includes_numeric_string_coordinates(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        Carbon::setTestNow(Carbon::parse('2024-03-01 08:00:00', 'UTC'));
        ActivityLog::create([
            'session_id' => 'numeric-string-session',
            'device' => 'Desktop',
            'browser' => 'Chrome',
            'urls_visited' => ['/search'],
            'started_at' => Carbon::parse('2024-03-01 08:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2024-03-01 08:10:00', 'UTC'),
            'duration_seconds' => 600,
            'routes_searched' => [
                [
                    'origin' => ['lat' => '-1.276543', 'lng' => '36.812345'],
                    'destination' => ['lat' => '-1.280123', 'lng' => '36.820987'],
                    'origin_label' => 'Numeric Origin',
                    'destination_label' => 'Numeric Destination',
                    'searched_at' => '2024-03-01T08:05:00+00:00',
                    'has_results' => true,
                ],
            ],
        ]);
        Carbon::setTestNow(null);

        $response = $this->getJson('/superadmin/analytics');

        $response->assertOk();

        $datasets = collect($response->json('search_heatmap.heatmap.datasets'));
        $datasetsWithPoints = $datasets->filter(fn(array $dataset) => !empty($dataset['points'] ?? []));

        $this->assertTrue(
            $datasetsWithPoints->isNotEmpty(),
            'Expected at least one heatmap dataset to include points for numeric string coordinates.'
        );
    }

    public function test_date_filters_limit_results(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();
        $this->seedAnalyticsData();

        $response = $this->getJson('/superadmin/analytics?start=2024-01-06&end=2024-01-06&interval=day');

        $response->assertOk();
        $searchStats = $response->json('search.stats');
        $this->assertEquals(1, $this->getMetricValue($searchStats, 'search-total'));
        $this->assertEquals(1, $this->getMetricValue($searchStats, 'search-successful'));

        $heatmapMetadata = $response->json('search_heatmap.metadata');
        $this->assertEquals(1, $heatmapMetadata['total_events']);
        $this->assertEquals(0, $heatmapMetadata['total_no_result_events']);
        $this->assertEmpty($response->json('search_heatmap.heatmap.datasets.no_result_searches.points'));

        $engagementStats = $response->json('engagement.stats');
        $this->assertEquals(1, $this->getMetricValue($engagementStats, 'engagement-sessions'));

        $onboardingStats = $response->json('onboarding.stats');
        $this->assertEquals(1, $this->getMetricValue($onboardingStats, 'onboarding-total'));
        $this->assertEquals(0, $this->getMetricValue($onboardingStats, 'onboarding-verified'));
        $this->assertEquals(1, $this->getMetricValue($onboardingStats, 'onboarding-pending'));

        $onboardingItems = collect($response->json('onboarding.items'));
        $this->assertEquals(1, $this->getMetricValue($onboardingItems->toArray(), 'onboarding-role-sacco-admin'));
        $this->assertNotNull($onboardingItems->firstWhere('id', 'onboarding-user-3'));

        $routesStats = $response->json('routes.stats');
        $this->assertEquals(0, $this->getMetricValue($routesStats, 'routes-total'));

        $searchItems = collect($response->json('search.items'));
        $this->assertNotNull($searchItems->firstWhere('label', '2024-01-06'));
        $this->assertNull($searchItems->firstWhere('label', '2024-01-05'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     */
    private function getMetricValue(array $metrics, string $id): mixed
    {
        foreach ($metrics as $metric) {
            if (($metric['id'] ?? null) === $id) {
                return $metric['value'] ?? null;
            }
        }

        $this->fail("Metric with id {$id} not found.");
    }

    private function authenticateSuperAdmin(): User
    {
        $previousNow = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2023-12-15 09:00:00', 'UTC'));

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super.admin@example.com',
            'phone' => '254700000000',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Carbon::setTestNow($previousNow);

        Sanctum::actingAs($superAdmin, ['*']);
        $this->actingAs($superAdmin, 'sanctum');

        return $superAdmin;
    }

    private function seedAnalyticsData(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-05 09:00:00', 'UTC'));
        SearchMetric::create([
            'sacco_id' => 'SACCO1',
            'sacco_route_id' => 'SACCO1_R001',
            'rank' => 1,
        ]);

        Carbon::setTestNow(Carbon::parse('2024-01-12 10:00:00', 'UTC'));
        SearchMetric::create([
            'sacco_id' => 'SACCO2',
            'sacco_route_id' => 'SACCO2_R001',
            'rank' => 2,
        ]);

        Carbon::setTestNow(null);

        Carbon::setTestNow(Carbon::parse('2024-01-05 08:30:00', 'UTC'));
        User::create([
            'name' => 'Commuter One',
            'email' => 'commuter.one@example.com',
            'phone' => '254700000001',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'email_verified_at' => Carbon::now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2024-01-06 09:45:00', 'UTC'));
        User::create([
            'name' => 'Sacco Admin Two',
            'email' => 'sacco.admin.two@example.com',
            'phone' => '254700000002',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2024-02-03 09:15:00', 'UTC'));
        User::create([
            'name' => 'Driver Three',
            'email' => 'driver.three@example.com',
            'phone' => '254700000003',
            'password' => bcrypt('password'),
            'role' => UserRole::DRIVER,
            'is_verified' => true,
            'email_verified_at' => Carbon::now(),
        ]);

        Carbon::setTestNow(null);

        Carbon::setTestNow(Carbon::parse('2024-01-05 09:00:00', 'UTC'));
        ActivityLog::create([
            'session_id' => 'session-1',
            'device' => 'Desktop',
            'browser' => 'Chrome',
            'urls_visited' => [
                '/dashboard',
                '/reports',
                [
                    'path' => '/directions/cbd/to/westlands',
                    'source' => 'directions-frontend',
                    'viewed_at' => '2024-01-05T09:15:00+00:00',
                ],
                [
                    'path' => '/directions/search',
                    'viewed_at' => '2024-01-05T09:16:00+00:00',
                ],
                [
                    'path' => '/discover',
                    'source' => 'discover-frontend',
                    'viewed_at' => '2024-01-05T09:02:00+00:00',
                ],
                [
                    'path' => '/discover/SACCO1',
                    'source' => 'discover-frontend',
                    'viewed_at' => '2024-01-05T09:03:00+00:00',
                ],
            ],
            'started_at' => Carbon::parse('2024-01-05 09:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2024-01-05 09:20:00', 'UTC'),
            'duration_seconds' => 1200,
            'routes_searched' => [
                [
                    'origin' => [-1.286389, 36.817223],
                    'destination' => [-1.292001, 36.821321],
                    'origin_label' => 'CBD',
                    'destination_label' => 'Westlands',
                    'searched_at' => '2024-01-05T09:05:00+00:00',
                    'has_results' => true,
                ],
                [
                    'origin' => [-1.3001, 36.8009],
                    'destination' => [-1.3152, 36.7813],
                    'origin_label' => 'Industrial Area',
                    'destination_label' => 'Airport',
                    'searched_at' => '2024-01-05T09:10:00+00:00',
                    'has_results' => false,
                ],
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2024-01-06 11:00:00', 'UTC'));
        ActivityLog::create([
            'session_id' => 'session-2',
            'device' => 'Mobile',
            'browser' => 'Safari',
            'urls_visited' => [
                '/dashboard',
                [
                    'path' => '/directions/kikuyu/to/cbd',
                    'source' => 'directions-frontend',
                    'viewed_at' => '2024-01-06T11:05:00+00:00',
                ],
                [
                    'path' => '/discover/SACCO2',
                    'source' => 'discover-frontend',
                    'viewed_at' => '2024-01-06T11:06:00+00:00',
                ],
                [
                    'path' => '/discover/SACCO1/stages/STAGE-1',
                    'source' => 'discover-frontend',
                    'viewed_at' => '2024-01-06T11:07:00+00:00',
                ],
            ],
            'started_at' => Carbon::parse('2024-01-06 11:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2024-01-06 11:10:00', 'UTC'),
            'duration_seconds' => 600,
            'routes_searched' => [
                [
                    'origin' => [-1.2802, 36.8204],
                    'destination' => [-1.2705, 36.8307],
                    'origin_label' => 'Parklands',
                    'destination_label' => 'Thika Road',
                    'searched_at' => '2024-01-06T11:05:00+00:00',
                    'has_results' => true,
                ],
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2024-01-07 07:45:00', 'UTC'));
        ActivityLog::create([
            'session_id' => 'session-4',
            'device' => 'Desktop',
            'browser' => 'Edge',
            'urls_visited' => [
                [
                    'path' => '/direction',
                    'source' => 'directions-frontend',
                    'viewed_at' => '2024-01-07T07:45:00+00:00',
                ],
                [
                    'path' => '/discover/SACCO1/stages/STAGE-2',
                    'source' => 'discover-frontend',
                    'viewed_at' => '2024-01-07T07:46:00+00:00',
                ],
            ],
            'started_at' => Carbon::parse('2024-01-07 07:45:00', 'UTC'),
            'ended_at' => Carbon::parse('2024-01-07 07:50:00', 'UTC'),
            'duration_seconds' => 300,
        ]);

        Carbon::setTestNow(Carbon::parse('2024-02-10 12:00:00', 'UTC'));
        ActivityLog::create([
            'session_id' => 'session-3',
            'device' => 'Desktop',
            'browser' => 'Firefox',
            'urls_visited' => ['/out-of-range'],
            'started_at' => Carbon::parse('2024-02-10 12:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2024-02-10 12:05:00', 'UTC'),
            'duration_seconds' => 300,
            'routes_searched' => [
                [
                    'origin' => [-1.3003, 36.8005],
                    'destination' => [-1.3155, 36.7812],
                    'origin_label' => 'Industrial Area',
                    'destination_label' => 'Airport',
                    'searched_at' => '2024-02-10T12:01:00+00:00',
                    'has_results' => false,
                ],
            ],
        ]);

        Carbon::setTestNow(null);

        Sacco::create([
            'sacco_id' => 'SACCO1',
            'sacco_name' => 'City Sacco',
            'vehicle_type' => 'matatu',
            'join_date' => '2024-01-07',
        ]);

        Sacco::create([
            'sacco_id' => 'SACCO2',
            'sacco_name' => 'Metro Sacco',
            'vehicle_type' => 'bus',
            'join_date' => '2024-02-01',
        ]);

        $logFile = storage_path('logs/saccoroute_publish.log');
        $lines = [
            json_encode([
                'sacco_route_id' => 'SACCO1_R001',
                'created_by_role' => 'super_admin',
                'created_by' => 'super.admin@example.com',
                'published_at' => '2024-01-08T09:00:00+00:00',
            ]),
            json_encode([
                'sacco_route_id' => 'SACCO2_R001',
                'created_by_role' => 'sacco_admin',
                'created_by' => 'sacco.admin@example.com',
                'published_at' => '2024-02-03T09:00:00+00:00',
            ]),
        ];
        file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
