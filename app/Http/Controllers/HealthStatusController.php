<?php

namespace App\Http\Controllers;

use App\Services\HealthDashboardService;
use App\Services\TestResultsAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

class HealthStatusController extends Controller
{
    public function __construct(
        private readonly HealthDashboardService $dashboardService,
        private readonly TestResultsAggregator $aggregator
    ) {
    }

    /**
     * Return the health dashboard payload for the latest automated test run.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->buildDashboard());
    }

    /**
     * Trigger the automated test suites and return the refreshed dashboard payload.
     */
    public function run(\Illuminate\Http\Request $request): JsonResponse
    {
        $output = new BufferedOutput();
        $params = [];
        if ($group = $request->input('group')) {
            $params['--group'] = $group;
        }

        $exitCode = Artisan::call('tests:run', $params, $output);

        $summary = $this->aggregator->readLatestSummary();
        $dashboard = $this->dashboardService->buildDashboard($summary);

        $dashboard['artisan_output'] = trim($output->fetch());
        $dashboard['exit_code'] = $exitCode;

        $statusCode = ($dashboard['status'] ?? null) === 'failed' ? 422 : 200;

        return response()->json($dashboard, $statusCode);
    }
}
