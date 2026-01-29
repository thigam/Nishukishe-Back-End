<?php

namespace App\Console\Commands;

use App\Services\TestResultsAggregator;
use App\Services\HealthDashboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

class RunAutomatedTests extends Command
{
    protected $signature = 'tests:run {--group= : The dashboard group key to run (e.g. auth_security)}';

    protected $description = 'Run backend and frontend automated test suites and persist their reports.';

    public function __construct(private readonly TestResultsAggregator $aggregator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->aggregator->ensureDirectoryExists();
        $this->aggregator->cleanupOldArtifacts();
        $this->aggregator->clearCurrentArtifacts();

        $startedAt = Carbon::now();
        $logs = [];

        $dependencies = $this->ensureDependencies();

        $dependencyFailure = false;
        foreach ($dependencies as $dependency) {
            if (!($dependency['success'] ?? false)) {
                $dependencyFailure = true;
                break;
            }
        }

        if ($dependencyFailure) {
            $this->error('Aborting automated tests due to dependency installation failures.');
        } else {
            $definitions = $this->definitions();
            $groupKey = $this->option('group');

            if ($groupKey) {
                $area = HealthDashboardService::AREA_DEFINITIONS[$groupKey] ?? null;
                if (!$area) {
                    $this->error("Unknown group: {$groupKey}");
                    return Command::FAILURE;
                }

                $this->info(sprintf('Running tests for group: %s', $area['name']));

                // Filter definitions based on the group's suite_name_contains
                $definitions = array_filter($definitions, function ($def) use ($area) {
                    foreach ($area['suite_name_contains'] as $needle) {
                        if (str_contains($def['name'], $needle)) {
                            return true;
                        }
                    }
                    return false;
                });

                if (empty($definitions)) {
                    $this->warn("No test suites found matching group: {$groupKey}");
                }
            }

            foreach ($definitions as $definition) {
                $this->info(sprintf('Running %s …', $definition['name']));
                $logs[] = $this->executeSuite($definition);
            }
        }

        $suites = $this->aggregator->collectSuites();
        $status = $this->aggregator->determineStatus($suites, $logs, $dependencies);
        $finishedAt = Carbon::now();

        $summary = [
            'status' => $status,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'dependencies' => $dependencies,
            'suites' => $suites,
            'logs' => $logs,
        ];

        $this->aggregator->writeLatestSummary($summary);

        if ($status === 'failed') {
            $this->error('Automated tests completed with failures.');
            return Command::FAILURE;
        }

        $this->info('Automated tests finished successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function definitions(): array
    {
        $frontendPath = $this->projectPath('../frontend');
        $baseCommand = 'php artisan test --no-ansi';

        return [
            [
                'name' => 'Backend - Blogs',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-blogs.xml')) . ' tests/Feature/BlogWorkflowTest.php tests/Feature/CommentWorkflowTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Auth',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-auth.xml')) . ' tests/Feature/GoogleAuthTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Sacco',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-sacco.xml')) . ' tests/Feature/SaccoProfileTest.php tests/Feature/SaccoRouteIdFlowTest.php tests/Feature/PlaceholderSaccoCreationTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Routes',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-routes.xml')) . ' tests/Feature/RoutePlannerControllerTest.php tests/Feature/RouteReviewFlowTest.php tests/Feature/PreCleanRouteWithStopsTest.php tests/Feature/PostCleanTripDayOfWeekTest.php tests/Feature/RouteSecurityTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Tembea',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-tembea.xml')) . ' tests/Feature/PublicTembeaOperatorControllerTest.php tests/Feature/TembeaOperatorProfileUpdateTest.php tests/Feature/TembeaOperatorSettlementRequestTest.php tests/Feature/SuperAdminTembeaPayoutsTest.php tests/Feature/PublicBookableControllerTest.php tests/Feature/TicketScanCountTest.php tests/Feature/SuperAdminTembeaControllerTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Admin',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-admin.xml')) . ' tests/Feature/SuperAdminAnalyticsTest.php tests/Feature/SuperAdminLogsTest.php tests/Feature/HealthDashboardTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - General',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-general.xml')) . ' tests/Feature/PageLoadTest.php tests/Feature/ExampleTest.php tests/Feature/PaymentMethodTest.php tests/Feature/SocialMetricControllerTest.php',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            [
                'name' => 'Backend - Unit',
                'command' => $baseCommand . ' --log-junit=' . escapeshellarg(storage_path('test-results/phpunit-unit.xml')) . ' --testsuite=Unit',
                'cwd' => base_path(),
                'env' => $this->testingEnvOverrides(),
            ],
            // --- Frontend: Auth ---
            [
                'name' => 'Frontend - Auth',
                'command' => 'npx playwright test tests/auth.spec.ts tests/role-dashboard.spec.ts',
                'cwd' => $frontendPath,
                'env' => [],
            ],
            // --- Frontend: Sacco ---
            [
                'name' => 'Frontend - Sacco',
                'command' => 'npm run test:unit -- src/components/saccos/SaccoProfileForm.test.tsx src/components/discover/SaccoShareCard.test.tsx',
                'cwd' => $frontendPath,
                'env' => [],
            ],
            // --- Frontend: Commuter ---
            [
                'name' => 'Frontend - Commuter',
                'command' => 'npm run test:unit -- src/components/__tests__/RouteResults.test.tsx tests/sitemap-directions.test.ts && npx playwright test tests/directions-fallback.spec.ts',
                'cwd' => $frontendPath,
                'env' => [],
            ],
            // --- Frontend: Admin ---
            [
                'name' => 'Frontend - Admin',
                'command' => 'npm run test:unit -- src/__tests__/HealthDashboardPage.test.tsx tests/analytics-normaliser.test.ts && npx playwright test tests/superadmin-analytics.spec.ts',
                'cwd' => $frontendPath,
                'env' => [],
            ],
        ];
    }


    /**
     * Ensure application dependencies required for the test suites are installed.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ensureDependencies(): array
    {
        $this->info('Checking test suite dependencies …');

        $dependencies = [];

        $dependencies[] = $this->verifyDependency(
            'composer',
            'Composer dependencies',
            fn(): bool => is_file(base_path('vendor/autoload.php')),
            'composer install --no-interaction --prefer-dist',
            base_path()
        );

        $frontendPath = $this->projectPath('../frontend');
        $nodeModulesPath = $frontendPath . DIRECTORY_SEPARATOR . 'node_modules';
        $dependencies[] = $this->verifyDependency(
            'frontend_node_modules',
            'Frontend node_modules',
            fn(): bool => is_dir($nodeModulesPath),
            $this->determineNodeInstallCommand($frontendPath),
            $frontendPath
        );

        $dependencies[] = $this->verifyDependency(
            'playwright_browsers',
            'Playwright browsers',
            fn(): bool => $this->playwrightBrowsersPresent($frontendPath),
            $this->determinePlaywrightInstallCommand($frontendPath),
            $frontendPath
        );

        return $dependencies;
    }

    /**
     * Run dependency verification and installer commands when needed.
     *
     * @param callable(): bool $check
     * @return array<string, mixed>
     */
    protected function verifyDependency(string $key, string $name, callable $check, ?string $command, string $cwd): array
    {
        $isSatisfied = $check();
        $checkedAt = Carbon::now();

        $result = [
            'key' => $key,
            'name' => $name,
            'command' => $command,
            'cwd' => $cwd,
            'ran' => false,
            'present' => $isSatisfied,
            'success' => $isSatisfied,
            'exit_code' => $isSatisfied ? 0 : null,
            'duration_seconds' => 0.0,
            'output' => '',
            'error_output' => '',
            'checked_at' => $checkedAt->toIso8601String(),
        ];

        if ($isSatisfied) {
            $this->info(sprintf('%s already installed.', $name));

            return $result;
        }

        if ($command === null) {
            $result['success'] = false;
            $result['exit_code'] = 1;
            $this->error(sprintf('No installer command configured for %s.', $name));

            return $result;
        }

        $this->info(sprintf('Installing %s …', $name));

        $started = microtime(true);
        $process = Process::fromShellCommandline($command, $cwd, ['CI' => '1']);
        $process->setTimeout(null);
        $process->run();

        $result['ran'] = true;
        $result['duration_seconds'] = round(microtime(true) - $started, 2);
        $result['exit_code'] = $process->getExitCode();
        $result['output'] = trim($process->getOutput());
        $result['error_output'] = trim($process->getErrorOutput());
        $result['checked_at'] = Carbon::now()->toIso8601String();

        $result['present'] = $check();
        $result['success'] = $process->isSuccessful() && $result['present'];

        if ($result['success']) {
            $this->info(sprintf('%s installed successfully.', $name));
        } else {
            $exitCode = $result['exit_code'] ?? 'unknown';
            $this->error(sprintf('%s installation failed (exit code %s).', $name, $exitCode));
        }

        return $result;
    }

    protected function determineNodeInstallCommand(string $frontendPath): string
    {
        $pnpmLock = $frontendPath . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml';

        if (is_file($pnpmLock)) {
            return 'pnpm install --frozen-lockfile';
        }

        return 'npm ci';
    }

    protected function determinePlaywrightInstallCommand(string $frontendPath): string
    {
        $binary = $frontendPath
            . DIRECTORY_SEPARATOR . 'node_modules'
            . DIRECTORY_SEPARATOR . '.bin'
            . DIRECTORY_SEPARATOR . 'playwright';

        $base = is_file($binary) ? escapeshellarg($binary) : 'npx playwright';

        // Windows vs POSIX env prefix
        $envPrefix = PHP_OS_FAMILY === 'Windows'
            ? 'set PLAYWRIGHT_BROWSERS_PATH=0 && '
            : 'PLAYWRIGHT_BROWSERS_PATH=0 ';

        if (PHP_OS_FAMILY === 'Windows') {
            $windowsBinary = $binary . '.cmd';
            if (is_file($windowsBinary)) {
                return $envPrefix . escapeshellarg($windowsBinary) . ' install';
            }
            return $envPrefix . 'npx playwright install';
        }

        // Prefer a plain install; fall back to with-deps only if available/needed
        $hasApt = is_file('/usr/bin/apt-get') || is_file('/bin/apt-get');
        $isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;
        $hasSudo = is_file('/usr/bin/sudo') || is_file('/bin/sudo');

        if ($hasApt && ($isRoot || $hasSudo)) {
            return $envPrefix . $base . ' install || ' . $envPrefix . $base . ' install --with-deps';
        }

        return $envPrefix . $base . ' install';
    }

    protected function projectPath(string $path): string
    {
        $candidate = base_path($path);

        return realpath($candidate) ?: $candidate;
    }

    /**
     * @return array<string, string>
     */
    protected function testingEnvOverrides(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.testing.php'),
            'BCRYPT_ROUNDS' => '4',
            'CACHE_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_FOREIGN_KEYS' => 'true',
            'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'TELESCOPE_ENABLED' => 'false',
        ];
    }
    protected function playwrightBrowsersPresent(string $frontendPath): bool
    {
        $candidates = [];

        // Project-local cache (when PLAYWRIGHT_BROWSERS_PATH=0)
        $nodeModulesPath = $frontendPath . DIRECTORY_SEPARATOR . 'node_modules';
        $candidates[] = $nodeModulesPath . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'ms-playwright';

        // Env override
        $envPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
        if ($envPath !== false) {
            if ($envPath === '0') {
                $candidates[] = $nodeModulesPath . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'ms-playwright';
            } else {
                $candidates[] = str_starts_with($envPath, DIRECTORY_SEPARATOR)
                    ? $envPath
                    : $frontendPath . DIRECTORY_SEPARATOR . $envPath;
            }
        }

        // XDG cache (Linux)
        $xdg = getenv('XDG_CACHE_HOME');
        if ($xdg) {
            $candidates[] = rtrim($xdg, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ms-playwright';
        }

        // Home-based caches
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        if ($home) {
            // Linux
            $candidates[] = $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'ms-playwright';
            // macOS
            $candidates[] = $home . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'Caches' . DIRECTORY_SEPARATOR . 'ms-playwright';
            // Windows
            $localAppData = getenv('LOCALAPPDATA');
            if ($localAppData) {
                $candidates[] = rtrim($localAppData, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ms-playwright';
            }
        }

        foreach (array_unique($candidates) as $dir) {
            if (is_dir($dir)) {
                $contents = glob($dir . DIRECTORY_SEPARATOR . '*');
                if (!empty($contents)) {
                    return true;
                }
            }
        }

        return false;
    }
    // Add helper:
    protected function writeSuiteLogs(string $slug, string $out, string $err): void
    {
        $dir = storage_path('test-results/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $slug . '.out', $out);
        file_put_contents($dir . DIRECTORY_SEPARATOR . $slug . '.err', $err);
    }

    protected function tailLines(string $text, int $lines = 200): string
    {
        $arr = preg_split('/\R/', (string) $text);
        $tail = array_slice($arr, -$lines);
        return implode(PHP_EOL, $tail);
    }

    // Replace your executeSuite() with:
    protected function executeSuite(array $definition): array
    {
        $started = microtime(true);
        $slug = Str::slug($definition['name']);

        $process = Process::fromShellCommandline(
            $definition['command'],
            $definition['cwd'],
            array_merge(['CI' => '1'], $definition['env'] ?? [])
        );
        $process->setTimeout(null);

        // Live stream
        $process->run(function (string $type, string $buffer) {
            echo $buffer;
        });

        $success = $process->isSuccessful();
        $exit = $process->getExitCode() ?? 1;
        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        // Always save logs
        $this->writeSuiteLogs($slug, $out, $err);

        if ($success) {
            $this->info(sprintf('%s succeeded.', $definition['name']));
        } else {
            $this->error(sprintf('%s failed (exit code %s).', $definition['name'], $exit));
            // Print tails so you see the reason without opening files
            $tail = $this->tailLines($out . PHP_EOL . $err, 200);
            if ($tail !== '') {
                $this->line(str_repeat('-', 80));
                $this->line($tail);
                $this->line(str_repeat('-', 80));
                $this->line('Full logs saved to storage/test-results/logs/' . $slug . '.{out,err}');
            }
        }

        return [
            'name' => $definition['name'],
            'command' => $definition['command'],
            'cwd' => $definition['cwd'],
            'exit_code' => $exit,
            'success' => $success,
            'duration_seconds' => round(microtime(true) - $started, 2),
            'output' => $out,
            'error_output' => $err,
        ];
    }
}
