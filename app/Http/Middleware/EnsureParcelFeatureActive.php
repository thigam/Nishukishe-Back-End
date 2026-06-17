<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\ParcelAgent;

class EnsureParcelFeatureActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $saccoId = $request->route('saccoId') ?? $request->input('sacco_id');

        // If saccoId not directly provided, look up from user's agent/manager record
        if (!$saccoId && $user) {
            $agent = ParcelAgent::where('user_id', $user->id)->first();
            $manager = $agent ? null : SaccoManager::where('user_id', $user->id)->first();
            $saccoId = $agent?->sacco_id ?? $manager?->sacco_id;
        }

        if (!$saccoId) {
            return response()->json(['message' => 'Could not determine Sacco for this user.'], 403);
        }

        $sacco = Sacco::with('tier')->where('sacco_id', $saccoId)->first();

        if (!$sacco || !$sacco->hasParcelFeature()) {
            return response()->json([
                'message' => 'Parcel Management requires the Sacco Premium subscription. Please upgrade your tier.'
            ], 403);
        }

        return $next($request);
    }
}
