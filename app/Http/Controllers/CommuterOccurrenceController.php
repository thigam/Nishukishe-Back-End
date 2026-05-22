<?php

namespace App\Http\Controllers;

use App\Services\VehicleTracking\IncidentService;
use App\Models\Incident;
use Illuminate\Http\Request;

class CommuterOccurrenceController extends Controller
{
    protected $incidentService;

    public function __construct(IncidentService $incidentService)
    {
        $this->incidentService = $incidentService;
    }

    public function index(Request $request)
    {
        // Include:
        // 1. Incidents reported in the last 6 hours with no end time (regular temporary incidents)
        // 2. Active scheduled or long-running incidents (end_time in the future)
        // 3. Upcoming scheduled incidents (start_time in the future)
        $query = Incident::with('user')
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('reported_at', '>=', now()->subHours(6))
                        ->whereNull('end_time');
                })
                ->orWhere('end_time', '>=', now())
                ->orWhere('start_time', '>=', now());
            });

        // Geographic Bounding Filter
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = (float) $request->lat;
            $lng = (float) $request->lng;
            $radiusKm = (float) $request->input('radius', 5); // default 5km

            $latDelta = $radiusKm / 111.32;
            $lngDelta = $radiusKm / (111.32 * cos(deg2rad($lat)));

            $query->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
                ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta]);
        }

        // We pull chronologically (oldest first) so that the oldest report becomes the cluster representative
        $incidents = $query->orderBy('reported_at', 'asc')->get();

        // Spatial Clustering (group similar types within ~500m)
        $clustered = [];
        $mergedIds = [];

        foreach ($incidents as $incident) {
            if (in_array($incident->id, $mergedIds))
                continue;

            $clusterGroup = [$incident];
            $mergedIds[] = $incident->id;

            foreach ($incidents as $sibling) {
                if ($incident->id === $sibling->id || in_array($sibling->id, $mergedIds))
                    continue;
                if ($incident->type !== $sibling->type)
                    continue;

                $latDiff = abs($incident->lat - $sibling->lat);
                $lngDiff = abs($incident->lng - $sibling->lng);

                // ~500m logic
                if ($latDiff <= 0.005 && $lngDiff <= 0.005) {
                    $clusterGroup[] = $sibling;
                    $mergedIds[] = $sibling->id;
                }
            }

            $parent = clone $incident;
            $parent->reports_count = count($clusterGroup);
            $parent->upvotes = collect($clusterGroup)->sum('upvotes');
            $parent->downvotes = collect($clusterGroup)->sum('downvotes');
            $parent->is_verified = collect($clusterGroup)->contains('is_verified', true) || $parent->reports_count >= 5;

            // Gravity Algorithm: Weight primarily by reports scaling and upvotes, penalize by age and downvotes.
            $hoursOld = now()->diffInHours($parent->reported_at);
            $parent->gravity_score = ($parent->reports_count * 5) + ($parent->upvotes * 2) - ($parent->downvotes * 2) - ($hoursOld * 3);

            $clustered[] = $parent;
        }

        // Output array sorted strictly by Gravity score DESC
        usort($clustered, function ($a, $b) {
            return $b->gravity_score <=> $a->gravity_score;
        });

        return response()->json($clustered);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'                => 'required|string',
            'lat'                 => 'required|numeric',
            'lng'                 => 'required|numeric',
            'description'         => 'nullable|string',
            'incident_sub_type'   => 'nullable|string|max:255',
            'path_coordinates'    => 'nullable|array',
            'path_coordinates.*'  => 'array|size:2',
            'start_time'          => 'nullable|date',
            'end_time'            => 'nullable|date',
        ]);

        // Support anonymous reporting if user is not authenticated
        $userId = $request->user() ? $request->user()->id : null;

        // Build the payload for the IncidentService
        $payload = [
            'type'              => $data['type'],
            'lat'               => $data['lat'],
            'lng'               => $data['lng'],
            'description'       => $data['description'] ?? null,
            'incident_sub_type' => $data['incident_sub_type'] ?? null,
            'path_coordinates'  => $data['path_coordinates'] ?? null,
            'start_time'        => $data['start_time'] ?? null,
            'end_time'          => $data['end_time'] ?? null,
        ];

        $incident = $this->incidentService->report(null, null, $userId, $payload);

        return response()->json($incident, 201);
    }

    public function upvote(Request $request, Incident $incident)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }
        try {
            $this->incidentService->upvote($incident, $request->user()->id);
            return response()->json(['message' => 'Upvoted successfully', 'incident' => $incident]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function downvote(Request $request, Incident $incident)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }
        try {
            $this->incidentService->downvote($incident, $request->user()->id);
            return response()->json(['message' => 'Downvoted/Cleared successfully', 'incident' => $incident]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
