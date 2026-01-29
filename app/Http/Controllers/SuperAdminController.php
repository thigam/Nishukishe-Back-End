<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Route as TransportRoute;
use App\Models\SharedModels\Counties;
use App\Models\UserRole;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


class SuperAdminController extends Controller
{
    /**
     * Return all users.
     */
    public function users(): JsonResponse
    {
        $users = User::all();
        return response()->json($users);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update($request->all());
        return response()->json($user);
    }

    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }

    public function logs(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('Logs request params:', $request->all());
        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $page = (int) $request->input('page', 1);

        $logs = ActivityLog::with('user:id,name,email')
            ->orderByDesc('started_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $logs->getCollection()->transform(function (ActivityLog $log) {
            $urlsVisited = array_values($log->urls_visited ?? []);
            $routesSearched = array_values($log->routes_searched ?? []);
            $routesSearched = array_map(function ($entry) {
                if (is_array($entry)) {
                    return $entry;
                }

                if (is_object($entry)) {
                    return (array) $entry;
                }

                return $entry;
            }, $routesSearched);

            return [
                'id' => $log->id,
                'session_id' => $log->session_id,
                'ip_address' => $log->ip_address,
                'device' => $log->device,
                'browser' => $log->browser,
                'started_at' => $log->started_at?->toIso8601String(),
                'ended_at' => $log->ended_at?->toIso8601String(),
                'duration_seconds' => $log->duration_seconds,
                'urls_visited' => $urlsVisited,
                'routes_searched' => $routesSearched,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
            ];
        });

        return response()->json([
            'data' => array_values($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'has_more_pages' => $logs->hasMorePages(),
            ],
        ]);
    }

    public function unverifiedAdmins(): JsonResponse
    {
        $admins = User::whereIn('role', [UserRole::SACCO, UserRole::TEMBEA])
            ->where('is_approved', false)
            ->get(['id', 'name', 'email']);

        return response()->json($admins);
    }

    public function verifyAdmin(int $id): JsonResponse
    {
        $admin = User::whereIn('role', [UserRole::SACCO, UserRole::TEMBEA])
            ->where('id', $id)
            ->firstOrFail();

        $admin->is_approved = true;
        $admin->save();

        return response()->json(['message' => 'Admin verified']);
    }

    /**
     * Manage saccos
     */
    public function saccos(): JsonResponse
    {
        return response()->json(Sacco::all());
    }

    public function updateSacco(Request $request, string $id): JsonResponse
    {
        $sacco = Sacco::findOrFail($id);

        if ($request->has('share_slug')) {
            $request->merge([
                'share_slug' => $this->normalizeShareSlug($request->input('share_slug')),
            ]);
        }

        $validated = $request->validate($this->saccoProfileRules($sacco));

        if (array_key_exists('share_slug', $validated)) {
            $validated['share_slug'] = $this->normalizeShareSlug($validated['share_slug']);
        }

        $sacco->fill($validated);
        $sacco->save();

        return response()->json($sacco->fresh());
    }

    public function deleteSacco(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $sacco = Sacco::findOrFail($id);
        $sacco->delete();
        return response()->json(['message' => 'Sacco deleted']);
    }

    public function approveSacco(string $id): JsonResponse
    {
        $sacco = Sacco::findOrFail($id);
        $sacco->is_approved = true;
        $sacco->save();

        $user = User::where('email', $sacco->sacco_email)->first();
        if ($user) {
            $user->is_approved = true;
            $user->is_verified = true; // Ensure the user is verified upon sacco approval
            $user->save();
        }


        return response()->json(['message' => 'Sacco approved']);
    }

    /**
     * Manage sacco routes
     */
    public function saccoRoutes(): JsonResponse
    {
        return response()->json(SaccoRoutes::all());
    }

    public function updateSaccoRoute(Request $request, string $id): JsonResponse
    {
        $route = SaccoRoutes::findOrFail($id);
        $route->update($request->all());
        return response()->json($route);
    }

    public function deleteSaccoRoute(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $route = SaccoRoutes::findOrFail($id);
        $route->delete();
        return response()->json(['message' => 'Sacco route deleted']);
    }

    /**
     * Manage routes
     */
    public function routes(): JsonResponse
    {
        return response()->json(TransportRoute::all());
    }

    public function updateRoute(Request $request, string $id): JsonResponse
    {
        $route = TransportRoute::findOrFail($id);
        $route->update($request->all());
        return response()->json($route);
    }

    public function deleteRoute(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $route = TransportRoute::findOrFail($id);
        $route->delete();
        return response()->json(['message' => 'Route deleted']);
    }

    /**
     * Manage counties
     */
    public function counties(): JsonResponse
    {
        return response()->json(Counties::all());
    }

    public function updateCounty(Request $request, string $id): JsonResponse
    {
        $county = Counties::findOrFail($id);
        $county->update($request->all());
        return response()->json($county);
    }

    public function deleteCounty(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $county = Counties::findOrFail($id);
        $county->delete();
        return response()->json(['message' => 'County deleted']);
    }

    private function saccoProfileRules(Sacco $sacco): array
    {
        return [
            'sacco_name' => 'sometimes|string|max:255',
            'sacco_phone' => 'sometimes|string|max:255',
            'sacco_email' => 'sometimes|email|max:255',
            'vehicle_type' => 'sometimes|string|max:255',
            'sacco_location' => 'sometimes|string|max:255',
            'till_number' => 'sometimes|nullable|string|max:20',
            'paybill_number' => 'sometimes|nullable|string|max:20',
            'sacco_logo' => 'sometimes|nullable|string|max:255',
            'sacco_website' => 'sometimes|nullable|url',
            'profile_headline' => 'sometimes|nullable|string|max:255',
            'profile_description' => 'sometimes|nullable|string',
            'share_slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('saccos', 'share_slug')->ignore($sacco->sacco_id, 'sacco_id'),
            ],
            'profile_contact_name' => 'sometimes|nullable|string|max:255',
            'profile_contact_phone' => 'sometimes|nullable|string|max:50',
            'profile_contact_email' => 'sometimes|nullable|email|max:255',
        ];
    }

    private function normalizeShareSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = Str::slug($value);

        return $slug !== '' ? $slug : null;
    }

    public function impersonate(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'password' => 'required|string',
        ]);

        $superAdmin = $request->user();

        // Verify super admin password
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $superAdmin->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $targetUser = User::findOrFail($request->user_id);

        // Create token for target user
        $token = $targetUser->createToken('impersonation_token')->plainTextToken;

        // Logout the current user (Super Admin) to clear cookies
        // This ensures the frontend relies on the new token and doesn't conflict with the session cookie
        // Logout the current user (Super Admin) to clear cookies
        // This ensures the frontend relies on the new token and doesn't conflict with the session cookie
        if (\Illuminate\Support\Facades\Auth::guard('web')->check()) {
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Determine redirect URL based on role
        $redirectUrl = '/';
        $saccoId = null;

        if ($targetUser->role === UserRole::SACCO) {
            $redirectUrl = '/saccos/dashboard';
            $manager = \App\Models\SaccoManager::where('user_id', $targetUser->id)->first();
            $saccoId = $manager?->sacco_id;
        } elseif ($targetUser->role === UserRole::TEMBEA) {
            $redirectUrl = '/tembea/admin';
        } elseif ($targetUser->role === UserRole::SERVICE_PERSON) {
            $redirectUrl = '/service/dashboard';
        }

        return response()->json([
            'token' => $token,
            'redirect_url' => $redirectUrl,
            'user' => $targetUser,
            'sacco_id' => $saccoId,
        ]);
    }
}

