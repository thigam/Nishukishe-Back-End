<?php

namespace App\Http\Controllers;

use App\Services\MobileAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAnalyticsController extends Controller
{
    public function __construct(private readonly MobileAnalyticsService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $start = $request->query('start');
        $end = $request->query('end');

        return response()->json([
            'summary' => $this->service->getSummary($start, $end),
            'sessions' => $this->service->getSessionSeries($start, $end),
            'platforms' => $this->service->getPlatformBreakdown(),
            'heatmap' => $this->service->getLocationHeatmap(),
        ]);
    }
}
