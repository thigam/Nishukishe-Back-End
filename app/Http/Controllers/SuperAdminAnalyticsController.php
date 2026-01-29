<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'interval' => ['nullable', 'in:day,week,month,year'],
        ]);

        $data = $this->analyticsService->summarize(
            $validated['start'] ?? null,
            $validated['end'] ?? null,
            $validated['interval'] ?? null
        );

        return response()->json($data);
    }
    public function logDirectionSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_slug' => ['required', 'string'],
            'destination_slug' => ['required', 'string'],
            'has_result' => ['required', 'boolean'],
            'query' => ['nullable'], // Relaxed validation to accept object/array or null
            'origin_lat' => ['nullable', 'numeric'],
            'origin_lng' => ['nullable', 'numeric'],
            'destination_lat' => ['nullable', 'numeric'],
            'destination_lng' => ['nullable', 'numeric'],
        ]);

        $this->analyticsService->logDirectionSearch(
            $validated['origin_slug'],
            $validated['destination_slug'],
            $validated['has_result'],
            $validated['query'] ?? null,
            $validated['origin_lat'] ?? null,
            $validated['origin_lng'] ?? null,
            $validated['destination_lat'] ?? null,
            $validated['destination_lng'] ?? null
        );

        return response()->json(['message' => 'Logged successfully']);
    }

    public function deadGuides(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $data = $this->analyticsService->getDeadGuides(
            $validated['start'] ?? null,
            $validated['end'] ?? null
        );

        return response()->json($data);
    }
    public function zeroResultSearches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $data = $this->analyticsService->getZeroResultSearches(
            $validated['start'] ?? null,
            $validated['end'] ?? null,
            20,
            $request->input('page', 1)
        );

        return response()->json($data);
    }
}
