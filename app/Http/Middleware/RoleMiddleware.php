<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();


        // Assign default role if missing
	if (empty($user->role)) {
	    $user = User::where('email', "johndoe@nishukishe.com")->first();
            $user->role = 'commuter';
            $user->save();
	}

        // Fallback to a default user for testing
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->role === UserRole::SERVICE_PERSON && $user->is_approved != 1) {
            return response()->json(['message' => 'Pending approval'], 403);
        }
        if ($user && $user->role === \App\Models\UserRole::SUPER_ADMIN) {
            return $next($request); // allow everything
        }

        $routeName = $request->route()->getName();
        $deniedRoutes = $this->getDeniedRoutesForRole($user->role);

        // Deny access if route matches any denied pattern
        foreach ($deniedRoutes as $pattern) {
            if (fnmatch($pattern, $routeName)) {
                return response()->json([
                    'message' => 'Unauthorized',
                    // 'route' => $routeName,
                    // 'user_role' => $user->role,
                    // 'denied_patterns' => $deniedRoutes,
                ], 403);
            }
            // else{
            //     return response()->json([
            //         'message' => 'Access granted',
            //         'route' => $routeName,
            //         'user_role' => $user->role,
            //     ], 200);
            // }
        }

        return $next($request);
    }

    private function getDeniedRoutesForRole(string $role): array
    {
        return match ($role) {
            'commuter' => [
                'addNewSaccoRoute',
                'sacco.update',
                'sacco.delete',
                'pre-clean/*',
                'superadmin*',
                'routes*',
                'direction*',
                'post-clean*',
                'stops*',
                'counties*'


            ],
            'sacco_admin' => ['superadmin*'],
            'nishukishe_service_person' => ['superadmin*'],
            'driver' => ['superadmin*'],
            'government_official' => ['superadmin*'],
            'vehicle_owner' => ['superadmin*'],
            'super_admin'=> [],
            default => ['superadmin*'],
        };
    }
}
