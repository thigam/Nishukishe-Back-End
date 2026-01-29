<?php
namespace App\Http\Middleware;

use App\Models\SearchMetric;
use App\Models\SaccoRoutes;
use Closure;
use Illuminate\Http\Request;

class RecordSearchMetrics
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if (!$request->routeIs('multileg.route')) {
            return;
        }

        $data = json_decode($response->getContent(), true);
        if (!$data) {
            return;
        }

        $paths = array_merge($data['single_leg'] ?? [], $data['multi_leg'] ?? []);
        $rank = 1;
        foreach ($paths as $path) {
            $saccoRouteId = $this->extractRouteId($path);
            if (!$saccoRouteId) {
                $rank++;
                continue;
            }
            $saccoId = SaccoRoutes::where('sacco_route_id', $saccoRouteId)->value('sacco_id');
            if ($saccoId) {
                SearchMetric::create([
                    'sacco_id' => $saccoId,
                    'sacco_route_id' => $saccoRouteId,
                    'rank' => $rank,
                ]);
            }
            $rank++;
        }
    }

    private function extractRouteId(array $path): ?string
    {
        foreach ($path as $step) {
            $label = $step['label'] ?? '';
            if (str_starts_with($label, 'bus via ')) {
                return substr($label, strlen('bus via '));
            }
        }
        return null;
    }
}
