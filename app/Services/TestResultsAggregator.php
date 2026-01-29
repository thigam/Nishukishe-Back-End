<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class TestResultsAggregator
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function directory(): string
    {
        return storage_path('test-results');
    }

    public function ensureDirectoryExists(): void
    {
        $directory = $this->directory();
        if (!$this->files->exists($directory)) {
            $this->files->makeDirectory($directory, 0775, true);
        }
    }

    public function cleanupOldArtifacts(int $days = 7): void
    {
        $directory = $this->directory();
        if (!$this->files->exists($directory)) {
            return;
        }

        $threshold = Carbon::now()->subDays($days)->getTimestamp();
        $paths = $this->files->glob($directory . '/*') ?? [];

        foreach ($paths as $path) {
            $modified = $this->files->lastModified($path);
            if ($modified !== false && $modified < $threshold) {
                if ($this->files->isDirectory($path)) {
                    $this->files->deleteDirectory($path);
                } else {
                    $this->files->delete($path);
                }
            }
        }
    }

    public function clearCurrentArtifacts(): void
    {
        $directory = $this->directory();
        if (!$this->files->exists($directory)) {
            return;
        }

        foreach ($this->files->glob($directory . '/*') ?? [] as $path) {
            if (str_ends_with($path, 'latest.json')) {
                continue;
            }

            if ($this->files->isDirectory($path)) {
                $this->files->deleteDirectory($path);
            } else {
                $this->files->delete($path);
            }
        }
    }

    public function collectSuites(): array
    {
        $directory = $this->directory();
        if (!$this->files->exists($directory)) {
            return [];
        }

        $results = [];

        foreach (glob($directory . '/*.json') as $file) {
            if (basename($file) === 'latest.json') {
                continue;
            }

            $decoded = json_decode($this->files->get($file), true);
            if (is_array($decoded)) {
                $results[] = $decoded;
            }
        }

        foreach (glob($directory . '/*.xml') as $file) {
            $xml = @simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }

            if ($xml->getName() === 'testsuite') {
                $results[] = $this->suiteSummary($xml, basename($file));
            } elseif ($xml->getName() === 'testsuites') {
                foreach ($xml->testsuite as $suite) {
                    $results[] = $this->suiteSummary($suite, basename($file));
                }
            }
        }

        return $results;
    }

    public function determineStatus(array $suites, array $logs = [], array $dependencies = []): string
    {
        foreach ($dependencies as $dependency) {
            if (!(bool) Arr::get($dependency, 'success', false)) {
                return 'failed';
            }
        }

        foreach ($logs as $log) {
            if (Arr::get($log, 'exit_code', 0) !== 0) {
                return 'failed';
            }
        }

        foreach ($suites as $suite) {
            if (Arr::get($suite, 'failed', 0) > 0) {
                return 'failed';
            }
        }

        return 'passed';
    }

    public function readLatestSummary(): array
    {
        $summaryFile = $this->directory() . '/latest.json';
        $suites = $this->collectSuites();

        if ($this->files->exists($summaryFile)) {
            $decoded = json_decode($this->files->get($summaryFile), true);
            if (is_array($decoded)) {
                $decoded['suites'] = $decoded['suites'] ?? $suites;
                $decoded['dependencies'] = $decoded['dependencies'] ?? [];
                $decoded['status'] = $decoded['status'] ?? $this->determineStatus(
                    $decoded['suites'],
                    $decoded['logs'] ?? [],
                    $decoded['dependencies']
                );
                return $decoded;
            }
        }

        return [
            'status' => $this->determineStatus($suites),
            'suites' => $suites,
            'started_at' => null,
            'finished_at' => null,
            'logs' => [],
            'dependencies' => [],
        ];
    }

    public function writeLatestSummary(array $summary): void
    {
        $this->ensureDirectoryExists();
        $path = $this->directory() . '/latest.json';
        $this->files->put($path, json_encode($summary, JSON_PRETTY_PRINT));
    }

    protected function suiteSummary(\SimpleXMLElement $suite, string $fileName): array
    {
        $name = (string) ($suite['name'] ?? $fileName);
        $tests = (int) ($suite['tests'] ?? 0);
        $failures = (int) ($suite['failures'] ?? 0);
        $errors = (int) ($suite['errors'] ?? 0);
        $failed = $failures + $errors;
        $passed = $tests - $failed;

        $testCases = [];
        foreach ($suite->testcase as $case) {
            $testCases[] = [
                'name' => (string) $case['name'],
                'class' => (string) ($case['class'] ?? $case['classname'] ?? ''),
                'time' => (float) $case['time'],
                'status' => isset($case->failure) ? 'failed' : (isset($case->error) ? 'error' : 'passed'),
                'failure_message' => isset($case->failure) ? (string) $case->failure : (isset($case->error) ? (string) $case->error : null),
            ];
        }

        return [
            'name' => $name,
            'passed' => $passed,
            'failed' => $failed,
            'reportUrl' => $this->findHtmlReport($fileName),
            'test_cases' => $testCases,
        ];
    }

    protected function findHtmlReport(string $xmlFile): ?string
    {
        $directory = $this->directory();
        $base = pathinfo($xmlFile, PATHINFO_FILENAME);

        $directFile = $directory . '/' . $base . '.html';
        if ($this->files->exists($directFile)) {
            return url('storage/test-results/' . basename($directFile));
        }

        $possibleDirectories = [$directory . '/' . $base];
        foreach (glob($directory . '/' . $base . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $possibleDirectories[] = $path;
        }
        // Common alternate folder name for Playwright reports
        $playwrightCandidate = $directory . '/' . str_replace(['results', 'xml'], ['report', ''], $base);
        $possibleDirectories[] = $playwrightCandidate;

        foreach ($possibleDirectories as $dir) {
            if (!$this->files->isDirectory($dir)) {
                continue;
            }

            $indexFile = rtrim($dir, '/\\') . '/index.html';
            if ($this->files->exists($indexFile)) {
                return url('storage/test-results/' . basename($dir) . '/index.html');
            }
        }

        return null;
    }
}
