<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SocialMetric;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SocialMetricController extends Controller
{
    /**
     * Display a listing of the social metrics with optional filtering.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'platform' => ['nullable', 'string'],
            'metric_type' => ['nullable', 'string'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $query = SocialMetric::query();

        if (! empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        if (! empty($filters['metric_type'])) {
            $query->where('metric_type', $filters['metric_type']);
        }

        if (! empty($filters['start'])) {
            $query->where('recorded_at', '>=', Carbon::parse($filters['start'])->startOfDay());
        }

        if (! empty($filters['end'])) {
            $query->where('recorded_at', '<=', Carbon::parse($filters['end'])->endOfDay());
        }

        $metrics = $query->orderByDesc('recorded_at')->get();

        return response()->json([
            'data' => $metrics,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Store a newly created social metric in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->validationRules());

        $metric = SocialMetric::create($this->preparePayload($data));

        return response()->json([
            'data' => $metric,
        ], 201);
    }

    /**
     * Update the specified social metric in storage.
     */
    public function update(Request $request, SocialMetric $socialMetric)
    {
        $data = $request->validate($this->validationRules());

        $socialMetric->fill($this->preparePayload($data));
        $socialMetric->save();

        return response()->json([
            'data' => $socialMetric,
        ]);
    }

    /**
     * Get the validation rules for storing/updating metrics.
     */
    protected function validationRules(): array
    {
        return [
            'platform' => ['required', 'string', 'max:255'],
            'metric_type' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric'],
            'recorded_at' => ['required', 'date'],
            'post_identifier' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Prepare payload for persistence.
     */
    protected function preparePayload(array $data): array
    {
        $data['metadata'] = $data['metadata'] ?? [];

        return $data;
    }
}
