<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ParcelAgent;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;

class ParcelAgentController extends Controller
{
    public function index(Request $request, $saccoId)
    {
        // Return agents specific to the Sacco along with their Users and Permissions
        $agents = ParcelAgent::with(['user.permissions'])
            ->where('sacco_id', $saccoId)
            ->get();

        return response()->json($agents);
    }

    public function approve(Request $request, $saccoId, $agentId)
    {
        $agent = ParcelAgent::where('sacco_id', $saccoId)->findOrFail($agentId);

        $user = $agent->user;
        $user->is_approved = true;
        $user->save();

        return response()->json(['message' => 'Agent approved successfully.']);
    }

    public function updatePermissions(Request $request, $saccoId, $agentId)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'string|in:parcel_receive,parcel_dispatch,parcel_deliver,parcel_admin'
        ]);

        $agent = ParcelAgent::where('sacco_id', $saccoId)->findOrFail($agentId);

        DB::transaction(function () use ($agent, $request) {
            // Delete existing permissions for this user
            UserPermission::where('user_id', $agent->user_id)
                ->whereIn('permission', ['parcel_receive', 'parcel_dispatch', 'parcel_deliver', 'parcel_admin'])
                ->delete();

            // Insert new permissions
            $permissionsData = [];
            foreach ($request->permissions ?? [] as $perm) {
                $permissionsData[] = [
                    'user_id' => $agent->user_id,
                    'permission' => $perm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if (count($permissionsData) > 0) {
                UserPermission::insert($permissionsData);
            }
        });

        return response()->json(['message' => 'Agent permissions updated successfully.']);
    }

    public function assignedDepots(Request $request, $saccoId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // We fetch the depots where this user is assigned
        $depots = \App\Models\ParcelDepot::where('sacco_id', $saccoId)
            ->whereHas('agents', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        return response()->json($depots);
    }
}
