<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ParcelDepot;
use App\Models\SaccoManager;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;

class ParcelDepotController extends Controller
{
    private function canManageDepots($user, $saccoId): bool
    {
        $isManager = SaccoManager::where('user_id', $user->id)->where('sacco_id', $saccoId)->exists();
        if ($isManager)
            return true;
        return UserPermission::where('user_id', $user->id)->where('permission', 'parcel_admin')->exists();
    }

    public function index(Request $request, string $saccoId): JsonResponse
    {
        $depots = ParcelDepot::where('sacco_id', $saccoId)->get();
        return response()->json($depots);
    }

    public function store(Request $request, string $saccoId): JsonResponse
    {
        if (!$this->canManageDepots($request->user(), $saccoId)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:10',
            'location_details' => 'nullable|string',
        ]);

        $depot = ParcelDepot::create([...$validated, 'sacco_id' => $saccoId]);
        return response()->json($depot, 201);
    }

    public function update(Request $request, string $saccoId, ParcelDepot $depot): JsonResponse
    {
        if ($depot->sacco_id !== $saccoId)
            abort(404);
        if (!$this->canManageDepots($request->user(), $saccoId)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:10',
            'location_details' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $depot->update($validated);
        return response()->json($depot);
    }

    public function destroy(Request $request, string $saccoId, ParcelDepot $depot): JsonResponse
    {
        if ($depot->sacco_id !== $saccoId)
            abort(404);
        if (!$this->canManageDepots($request->user(), $saccoId)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $depot->delete();
        return response()->json(['message' => 'Depot deleted.']);
    }

    // Assign/unassign an agent to a depot
    public function assignAgent(Request $request, string $saccoId, ParcelDepot $depot): JsonResponse
    {
        if ($depot->sacco_id !== $saccoId)
            abort(404);
        if (!$this->canManageDepots($request->user(), $saccoId)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate(['user_id' => 'required|exists:users,id']);
        $depot->agents()->syncWithoutDetaching([$request->user_id]);

        return response()->json(['message' => 'Agent assigned to depot.']);
    }

    public function removeAgent(Request $request, string $saccoId, ParcelDepot $depot): JsonResponse
    {
        if ($depot->sacco_id !== $saccoId)
            abort(404);
        if (!$this->canManageDepots($request->user(), $saccoId)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate(['user_id' => 'required|exists:users,id']);
        $depot->agents()->detach($request->user_id);

        return response()->json(['message' => 'Agent removed from depot.']);
    }
}
