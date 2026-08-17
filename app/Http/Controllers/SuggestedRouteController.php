<?php

namespace App\Http\Controllers;

use App\Models\SuggestedRoute;
use App\Models\Stops;
use App\Models\Sacco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\SuggestedRouteDoneEmail;
use Illuminate\Support\Facades\DB;

class SuggestedRouteController extends Controller
{
    /**
     * Get options for dropdowns.
     */
    public function options()
    {
        // Top 200 stops (ordered by number of routes passing through them)
        $stops = Stops::select('stops.stop_id', 'stops.stop_name')
            ->leftJoin('route_stop', 'stops.stop_id', '=', 'route_stop.stop_id')
            ->selectRaw('COUNT(route_stop.route_id) as usage_count')
            ->groupBy('stops.stop_id', 'stops.stop_name')
            ->orderBy('usage_count', 'desc')
            ->limit(200)
            ->get();

        // All saccos
        $saccos = Sacco::select('sacco_id', 'sacco_name')
            ->orderBy('sacco_name', 'asc')
            ->get();

        return response()->json([
            'stops' => $stops,
            'saccos' => $saccos,
        ]);
    }

    /**
     * Store a new suggestion.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'nullable|email|max:255',
            'start_stop_id' => 'nullable|exists:stops,stop_id',
            'end_stop_id' => 'nullable|exists:stops,stop_id',
            'sacco_id' => 'required_without:sacco_manual|nullable|exists:saccos,sacco_id',
            'start_stop_manual' => 'nullable|string|max:255',
            'end_stop_manual' => 'nullable|string|max:255',
            'sacco_manual' => 'required_without:sacco_id|nullable|string|max:255',
            'details' => 'nullable|string',
        ]);

        $suggestion = SuggestedRoute::create([
            'user_id' => auth()->id(),
            'email' => $validated['email'] ?? null,
            'start_stop_id' => $validated['start_stop_id'] ?? null,
            'end_stop_id' => $validated['end_stop_id'] ?? null,
            'sacco_id' => $validated['sacco_id'] ?? null,
            'start_stop_manual' => $validated['start_stop_manual'] ?? null,
            'end_stop_manual' => $validated['end_stop_manual'] ?? null,
            'sacco_manual' => $validated['sacco_manual'] ?? null,
            'details' => $validated['details'] ?? '',
            'status' => 'pending',
        ]);

        return response()->json($suggestion, 201);
    }

    /**
     * List suggestions with grouping and analytics for service persons.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $suggestions = SuggestedRoute::with(['user', 'startStop', 'endStop', 'sacco'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();

        // Grouping logic for analytics
        $grouped = SuggestedRoute::select(
            'start_stop_id',
            'end_stop_id',
            'sacco_id',
            'start_stop_manual',
            'end_stop_manual',
            'sacco_manual'
        )
            ->selectRaw('count(*) as count')
            ->where('status', 'pending')
            ->groupBy(
                'start_stop_id',
                'end_stop_id',
                'sacco_id',
                'start_stop_manual',
                'end_stop_manual',
                'sacco_manual'
            )
            ->orderBy('count', 'desc')
            ->get();

        // Load names for IDs in grouped results
        $grouped->each(function ($item) {
            if ($item->start_stop_id) {
                $item->start_stop_name = Stops::where('stop_id', $item->start_stop_id)->value('stop_name');
            }
            if ($item->end_stop_id) {
                $item->end_stop_name = Stops::where('stop_id', $item->end_stop_id)->value('stop_name');
            }
            if ($item->sacco_id) {
                $item->sacco_name = Sacco::where('sacco_id', $item->sacco_id)->value('sacco_name');
            }
        });

        return response()->json([
            'suggestions' => $suggestions,
            'analytics' => $grouped,
        ]);
    }

    /**
     * Mark a suggestion (and all similar ones) as done.
     */
    public function markDone(Request $request)
    {
        $request->validate([
            'start_stop_id' => 'nullable',
            'end_stop_id' => 'nullable',
            'sacco_id' => 'nullable',
            'start_stop_manual' => 'nullable',
            'end_stop_manual' => 'nullable',
            'sacco_manual' => 'nullable',
        ]);

        $query = SuggestedRoute::where('status', 'pending');

        $fields = ['start_stop_id', 'end_stop_id', 'sacco_id', 'start_stop_manual', 'end_stop_manual', 'sacco_manual'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->input($field));
            } else {
                $query->whereNull($field);
            }
        }

        $similarSuggestions = $query->with('user')->get();

        foreach ($similarSuggestions as $suggestion) {
            $suggestion->update(['status' => 'done']);

            if ($suggestion->user && $suggestion->user->email) {
                Mail::to($suggestion->user->email)->send(new SuggestedRouteDoneEmail($suggestion->user));
            }
        }

        return response()->json(['message' => 'Suggestions marked as done and emails sent.', 'count' => $similarSuggestions->count()]);
    }
}
