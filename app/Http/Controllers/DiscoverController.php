<?php

namespace App\Http\Controllers;

use App\Models\Sacco;
use App\Models\SaccoStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q', '');

        if (empty($query)) {
            return response()->json([]);
        }

        $terms = explode(' ', $query);

        // Search Saccos
        $saccos = Sacco::query()
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(function ($sub) use ($term) {
                        $sub->where('sacco_name', 'like', "%{$term}%")
                            ->orWhere('sacco_location', 'like', "%{$term}%");
                    });
                }
            })
            ->take(5)
            ->get()
            ->map(function ($sacco) {
                return [
                    'type' => 'sacco',
                    'id' => $sacco->sacco_id,
                    'name' => $sacco->sacco_name,
                    'location' => $sacco->sacco_location,
                    'logo' => $sacco->sacco_logo,
                    'slug' => $sacco->share_slug ?? $sacco->sacco_id,
                ];
            });

        // Search Stages
        $stages = SaccoStage::query()
            ->select('sacco_stages.*')
            ->join('saccos', 'sacco_stages.sacco_id', '=', 'saccos.sacco_id')
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(function ($sub) use ($term) {
                        $sub->where('sacco_stages.name', 'like', "%{$term}%")
                            ->orWhere('saccos.sacco_name', 'like', "%{$term}%")
                            ->orWhereRaw('LOWER(sacco_stages.destinations) LIKE ?', ["%" . strtolower($term) . "%"]);
                    });
                }
            })
            ->with('sacco:sacco_id,sacco_name,sacco_logo')
            ->take(8) // Increased limit to allow for prioritization
            ->get()
            ->sortBy(function ($stage) use ($query) {
                // Custom scoring for prioritization
                $score = 0;
                $q = strtolower($query);
                $name = strtolower($stage->name);
                $saccoName = strtolower($stage->sacco->sacco_name ?? '');

                // 1. Exact Name Match (Highest Priority)
                if ($name === $q)
                    $score += 100;
                // 2. Name Contains Query
                elseif (str_contains($name, $q))
                    $score += 50;

                // 3. Sacco Name Match
                if (str_contains($saccoName, $q))
                    $score += 30;

                // 4. Destination Match
                // We can check destinations array if needed, but simple string check on JSON is faster for sorting
                // (Already filtered in query)
    
                return -$score; // Sort descending
            })
            ->map(function ($stage) {
                return [
                    'type' => 'stage',
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'description' => $stage->description,
                    'sacco_name' => $stage->sacco->sacco_name ?? 'Unknown Sacco',
                    'sacco_id' => $stage->sacco->sacco_id ?? null,
                    'sacco_logo' => $stage->sacco->sacco_logo ?? null,
                    'image' => $stage->image_url,
                ];
            });

        // Merge and Limit to 8
        $results = $saccos->concat($stages)->take(8);

        return response()->json($results->values());
    }
}
