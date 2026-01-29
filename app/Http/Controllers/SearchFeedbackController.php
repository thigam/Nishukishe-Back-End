<?php

namespace App\Http\Controllers;

use App\Models\SearchFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SearchFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_start' => ['required', 'string'],
            'user_end' => ['required', 'string'],
            'sacco_route_id' => ['nullable', 'string', 'exists:sacco_routes,sacco_route_id'],
            'sacco_id' => ['nullable', 'integer', 'exists:saccos,id'],
            'grade' => ['required', 'string', Rule::in(['good', 'moderate', 'bad'])],
            'session_id' => ['nullable', 'string'],
        ]);

        $feedback = SearchFeedback::create([
            ...$validated,
            'ip_address' => $request->ip(),
        ]);

        return response()->json($feedback, 201);
    }

    public function analytics(Request $request): JsonResponse
    {
        // Aggregate by Sacco
        $bySacco = SearchFeedback::select('sacco_id', 'grade', DB::raw('count(*) as count'))
            ->with('sacco:id,name')
            ->groupBy('sacco_id', 'grade')
            ->get()
            ->groupBy('sacco_id')
            ->map(function ($items) {
                $saccoName = $items->first()->sacco->name ?? 'Unknown Sacco';
                $counts = $items->pluck('count', 'grade');
                return [
                    'sacco' => $saccoName,
                    'good' => $counts['good'] ?? 0,
                    'moderate' => $counts['moderate'] ?? 0,
                    'bad' => $counts['bad'] ?? 0,
                    'total' => $items->sum('count'),
                ];
            })->values();

        // Aggregate by Route (Sacco Route)
        $byRoute = SearchFeedback::select('sacco_route_id', 'grade', DB::raw('count(*) as count'))
            ->with(['saccoRoute.route', 'saccoRoute.sacco'])
            ->whereNotNull('sacco_route_id')
            ->groupBy('sacco_route_id', 'grade')
            ->get()
            ->groupBy('sacco_route_id')
            ->map(function ($items) {
                $sr = $items->first()->saccoRoute;
                $routeName = $sr ? "{$sr->sacco->name}: {$sr->route->name} ({$sr->route_number})" : 'Unknown Route';
                $counts = $items->pluck('count', 'grade');
                return [
                    'route' => $routeName,
                    'good' => $counts['good'] ?? 0,
                    'moderate' => $counts['moderate'] ?? 0,
                    'bad' => $counts['bad'] ?? 0,
                    'total' => $items->sum('count'),
                ];
            })->values();

        // Recent Feedback
        $recent = SearchFeedback::with(['sacco:id,name', 'saccoRoute.route'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'by_sacco' => $bySacco,
            'by_route' => $byRoute,
            'recent' => $recent,
        ]);
    }
}
