<?php

namespace App\Http\Controllers;

use App\Models\ReportedWrongInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportedWrongInfoController extends Controller
{
    /**
     * Store a new wrong information report.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'nullable|email|max:255',
            'search_start' => 'nullable|string|max:255',
            'search_end' => 'nullable|string|max:255',
            'legs' => 'nullable|array',
            'selected_legs' => 'required|array',
            'error_options' => 'required|array',
            'details' => 'nullable|string',
        ]);

        $report = ReportedWrongInfo::create([
            'user_id' => $request->user()?->id,
            'email' => $validated['email'] ?? null,
            'search_start' => $validated['search_start'] ?? null,
            'search_end' => $validated['search_end'] ?? null,
            'legs' => $validated['legs'] ?? null,
            'selected_legs' => $validated['selected_legs'],
            'error_options' => $validated['error_options'],
            'details' => $validated['details'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Wrong info report submitted successfully.',
            'report' => $report,
        ], 201);
    }

    /**
     * Get a list of wrong info reports (for dashboard view).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status'); // e.g. pending, resolved, all

        $query = ReportedWrongInfo::with('user')->orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->query('no_paginate') === 'true') {
            $reportsData = $query->get();
            return response()->json([
                'success' => true,
                'reports' => ['data' => $reportsData],
            ]);
        }

        $reports = $query->paginate(20);

        return response()->json([
            'success' => true,
            'reports' => $reports,
        ]);
    }

    /**
     * Resolve a wrong info report.
     */
    public function resolve(Request $request, $id): JsonResponse
    {
        $report = ReportedWrongInfo::findOrFail($id);
        $report->update(['status' => 'resolved']);

        return response()->json([
            'success' => true,
            'message' => 'Report marked as resolved successfully.',
            'report' => $report,
        ]);
    }
}
