<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Sacco;

class VerifySaccoTier
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $sacco = $request->user()->sacco ?? $request->route('sacco');

        if (!$sacco instanceof Sacco) {
            $saccoId = $request->user()->sacco_id ?? null;
            $sacco = $saccoId ? Sacco::find($saccoId) : null;
        }

        if (!$sacco || !$sacco->tier) {
            return response()->json(['message' => 'Sacco tier not found'], 403);
        }

        if (!$sacco->tier->is_active) {
            return response()->json(['message' => 'Sacco tier inactive'], 403);
        }

        $features = $sacco->tier->features ?? [];
        if (!($features[$feature] ?? false)) {
            return response()->json(['message' => 'Feature unavailable for your tier'], 403);
        }

        return $next($request);
    }
}
