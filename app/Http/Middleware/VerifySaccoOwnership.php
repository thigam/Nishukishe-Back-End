<?php

namespace App\Http\Middleware;

use App\Models\SaccoManager;
use App\Models\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySaccoOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $parcel = $request->route('parcel');

        if (!$user || !$parcel) {
            return $next($request);
        }

        if ($user->role === UserRole::SUPER_ADMIN) {
            return $next($request);
        }

        if ($user->role === UserRole::SACCO) {
            $owns = SaccoManager::where('user_id', $user->id)
                ->where('sacco_id', $parcel->sacco_id)
                ->exists();
            if ($owns) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
