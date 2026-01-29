<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Throwable;

class HealthDashboardService
{
    /**
     * Area definitions describing how suites and logs are grouped.
     *
     * @var array<string, array<string, mixed>>
     */
    public const AREA_DEFINITIONS = [
        'trip_planning' => [
            'name' => 'Trip Planning & Search',
            'suite_names' => [],
            'suite_name_contains' => ['Route', 'Search', 'Stop', 'Directions'],
            'report_contains' => [],
            'log_names' => [],
        ],
        'booking_flow' => [
            'name' => 'Booking & Payments',
            'suite_names' => [],
            'suite_name_contains' => ['Booking', 'Payment', 'Fare', 'Checkout'],
            'report_contains' => [],
            'log_names' => [],
        ],
        'sacco_portal' => [
            'name' => 'Sacco Operations',
            'suite_names' => [],
            'suite_name_contains' => ['Sacco', 'Tembea', 'Operator', 'Fleet'],
            'report_contains' => [],
            'log_names' => [],
        ],
        'admin_panel' => [
            'name' => 'Admin Control Panel',
            'suite_names' => [],
            'suite_name_contains' => ['Admin', 'Analytics', 'Logs', 'Health'],
            'report_contains' => [],
            'log_names' => [],
        ],
        'content_delivery' => [
            'name' => 'Content & Blogs',
            'suite_names' => [],
            'suite_name_contains' => ['Blog', 'Comment', 'PageLoad'],
            'report_contains' => [],
            'log_names' => [],
        ],
        'system_integrity' => [
            'name' => 'System Integrity',
            'suite_names' => [],
            'suite_name_contains' => ['Unit', 'General', 'Example', 'Security'],
            'report_contains' => [],
            'log_names' => [],
        ],
    ];

    /**
     * Dependency checks executed for dashboard diagnostics.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEPENDENCY_DEFINITIONS = [
        [
            'key' => 'node',
            'name' => 'Node.js',
            'command' => 'node --version',
        ],
        [
            'key' => 'npm',
            'name' => 'npm',
            'command' => 'npm --version',
        ],
        [
            'key' => 'composer',
            'name' => 'Composer',
            'command' => 'composer --version',
        ],
        [
            'key' => 'playwright',
            'name' => 'Playwright CLI',
            'command' => 'npx playwright --version',
        ],
        [
            'key' => 'playwright-browsers',
            'name' => 'Playwright browsers',
            'command' => 'npx playwright install --check',
            'degraded_on_failure' => true,
        ],
    ];

    public function __construct(private readonly TestResultsAggregator $aggregator)
    {
    }

    /**
     * Build a dashboard-friendly payload from the latest test summary.
     */
    public function buildDashboard(?array $summary = null): array
    {
        $summary ??= $this->aggregator->readLatestSummary();

        $suites = Arr::get($summary, 'suites', []);
        $logs = Arr::get($summary, 'logs', []);

        $assignedSuites = [];
        $assignedLogs = [];

        $areas = [];

        foreach (self::AREA_DEFINITIONS as $key => $definition) {
            $areaSuites = $this->extractMatches(
                $suites,
                $assignedSuites,
                fn(array $suite): bool => $this->suiteMatchesDefinition($suite, $definition)
            );

            $areaLogs = $this->extractMatches(
                $logs,
                $assignedLogs,
                fn(array $log): bool => $this->logMatchesDefinition($log, $definition)
            );

            $areas[] = $this->buildArea($key, $definition, $areaSuites, $areaLogs, $summary);
        }

        $remainingSuites = $this->remainingItems($suites, $assignedSuites);
        $remainingLogs = $this->remainingItems($logs, $assignedLogs);

        if (!empty($remainingSuites) || !empty($remainingLogs)) {
            $areas[] = $this->buildArea(
                'other',
                [
                    'name' => 'Other suites',
                    'optional' => true,
                ],
                $remainingSuites,
                $remainingLogs,
                $summary
            );
        }

        return [
            'status' => Arr::get($summary, 'status', 'unknown'),
            'started_at' => Arr::get($summary, 'started_at'),
            'finished_at' => Arr::get($summary, 'finished_at'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'areas' => $areas,
            'dependencies' => $this->checkDependencies(),
            'suites' => $suites,
            'logs' => $logs,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $definition
     * @param array<int, array<string, mixed>> $suites
     * @param array<int, array<string, mixed>> $logs
     * @param array<string, mixed>             $summary
     */
    protected function buildArea(string $key, array $definition, array $suites, array $logs, array $summary): array
    {
        $status = $this->determineAreaStatus($suites, $logs, (bool) ($definition['optional'] ?? false));
        $metrics = $this->calculateMetrics($suites, $logs);
        $lastRunAt = $this->resolveLastRunTimestamp($suites, $logs, $summary);

        return [
            'key' => $key,
            'name' => $definition['name'] ?? $key,
            'status' => $status,
            'metrics' => $metrics,
            'suites' => array_values($suites),
            'logs' => array_values($logs),
            'last_run_at' => $lastRunAt,
        ];
    }

    /**
     * Calculate aggregated metrics for an area.
     *
     * @param array<int, array<string, mixed>> $suites
     * @param array<int, array<string, mixed>> $logs
     */
    protected function calculateMetrics(array $suites, array $logs): array
    {
        $passed = 0;
        $failed = 0;

        foreach ($suites as $suite) {
            $passed += (int) Arr::get($suite, 'passed', 0);
            $failed += (int) Arr::get($suite, 'failed', 0);
        }

        $total = $passed + $failed;

        $duration = null;
        if (!empty($logs)) {
            $duration = array_reduce(
                $logs,
                fn(?float $carry, array $log): float => ($carry ?? 0.0) + (float) Arr::get($log, 'duration_seconds', 0.0),
                0.0
            );
        }

        return [
            'suite_count' => count($suites),
            'tests_total' => $total,
            'tests_passed' => $passed,
            'tests_failed' => $failed,
            'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : null,
            'execution_time_seconds' => $duration,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $suites
     * @param array<int, array<string, mixed>> $logs
     */
    protected function determineAreaStatus(array $suites, array $logs, bool $optional): string
    {
        foreach ($logs as $log) {
            if ((int) Arr::get($log, 'exit_code', 0) !== 0) {
                return 'failed';
            }
        }

        foreach ($suites as $suite) {
            if ((int) Arr::get($suite, 'failed', 0) > 0) {
                return 'failed';
            }
        }

        if (!empty($suites) || !empty($logs)) {
            return 'passed';
        }

        return $optional ? 'not_configured' : 'unknown';
    }

    /**
     * @param array<int, array<string, mixed>> $suites
     * @param array<int, array<string, mixed>> $logs
     * @param array<string, mixed>             $summary
     */
    protected function resolveLastRunTimestamp(array $suites, array $logs, array $summary): ?string
    {
        if (!empty($suites) || !empty($logs)) {
            return Arr::get($summary, 'finished_at') ?? Arr::get($summary, 'started_at');
        }

        return null;
    }

    /**
     * Determine if a suite record should be included in an area definition.
     *
     * @param array<string, mixed> $suite
     * @param array<string, mixed> $definition
     */
    protected function suiteMatchesDefinition(array $suite, array $definition): bool
    {
        $name = (string) Arr::get($suite, 'name', '');
        $report = (string) Arr::get($suite, 'reportUrl', '');

        foreach ($definition['suite_names'] ?? [] as $expected) {
            if ($name === $expected) {
                return true;
            }
        }

        foreach ($definition['suite_name_contains'] ?? [] as $needle) {
            if ($needle !== '' && str_contains(strtolower($name), strtolower($needle))) {
                return true;
            }
        }

        foreach ($definition['report_contains'] ?? [] as $needle) {
            if ($needle !== '' && str_contains(strtolower($report), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a log entry belongs to an area definition.
     *
     * @param array<string, mixed> $log
     * @param array<string, mixed> $definition
     */
    protected function logMatchesDefinition(array $log, array $definition): bool
    {
        $name = (string) Arr::get($log, 'name', '');

        foreach ($definition['log_names'] ?? [] as $expected) {
            if ($name === $expected) {
                return true;
            }
        }

        foreach ($definition['log_name_contains'] ?? [] as $needle) {
            if ($needle !== '' && str_contains(strtolower($name), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, bool>                 $assigned
     * @param callable(array<string, mixed>): bool $matcher
     * @return array<int, array<string, mixed>>
     */
    protected function extractMatches(array $items, array &$assigned, callable $matcher): array
    {
        $matches = [];

        foreach ($items as $index => $item) {
            if (($assigned[$index] ?? false) === true) {
                continue;
            }

            if ($matcher($item)) {
                $matches[$index] = $item;
                $assigned[$index] = true;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, bool>                 $assigned
     * @return array<int, array<string, mixed>>
     */
    protected function remainingItems(array $items, array $assigned): array
    {
        $remaining = [];

        foreach ($items as $index => $item) {
            if (($assigned[$index] ?? false) === true) {
                continue;
            }

            $remaining[$index] = $item;
        }

        return $remaining;
    }

    /**
     * Execute dependency diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function checkDependencies(): array
    {
        $results = [];

        foreach (self::DEPENDENCY_DEFINITIONS as $definition) {
            $results[] = $this->evaluateDependency($definition);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function evaluateDependency(array $definition): array
    {
        $checkedAt = Carbon::now();
        $status = 'unavailable';
        $output = '';
        $duration = null;

        try {
            $started = microtime(true);
            $process = Process::fromShellCommandline($definition['command'], base_path());
            $process->setTimeout($definition['timeout'] ?? 15);
            $process->run();
            $duration = round(microtime(true) - $started, 3);

            $outputBuffer = trim($process->getOutput());
            $errorBuffer = trim($process->getErrorOutput());
            $output = $outputBuffer !== '' ? $outputBuffer : $errorBuffer;

            if ($process->isSuccessful()) {
                $status = 'healthy';
            } else {
                $status = ($definition['degraded_on_failure'] ?? false) ? 'degraded' : 'unavailable';
            }
        } catch (Throwable $exception) {
            $output = $exception->getMessage();
            $status = 'unavailable';
        }

        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'status' => $status,
            'command' => $definition['command'],
            'output' => $output,
            'checked_at' => $checkedAt->toIso8601String(),
            'duration_seconds' => $duration,
        ];
    }
}
