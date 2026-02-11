<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNishukisheEmail
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission = 'access_emails'): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        // 1. Check Domain
        if (!str_ends_with($user->email, '@nishukishe.com')) {
            return response()->json(['message' => 'Access denied. You must use a company email address.'], 403);
        }

        // 2. Check Permission (Super Admins bypass, others need specific permission)
        if ($user->role !== 'super_admin') {
            // Assuming permissions relation exists as per your description
            // or CheckServiceAccess middleware logic
            if (!$user->permissions()->where('permission', $permission)->exists()) {
                return response()->json(['message' => 'Access denied. You do not have permission to access emails.'], 403);
            }
        }

        return $next($request);
    }
}
