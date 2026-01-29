<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckServiceAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user || !$user->permissions()->where('permission', $permission)->exists()) {
            // Super admins bypass this check
            if ($user && $user->role === \App\Models\UserRole::SUPER_ADMIN) {
                return $next($request);
            }

            abort(403, 'Unauthorized access.');
        }

        return $next($request);
    }
}
