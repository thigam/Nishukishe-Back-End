<?php

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use App\Models\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class OwnerInvitationController extends Controller
{
    /**
     * Invite a new Owner (Called by Sacco Manager)
     */
    public function inviteOwner(Request $request)
    {
        $request->validate([
            'sacco_id' => 'required|exists:saccos,sacco_id',
            'email' => 'required|email|unique:users,email',
        ]);

        $token = Str::random(40);

        $invitation = UserInvitation::create([
            'email' => $request->email,
            'token' => $token,
            'role' => UserRole::VEHICLE_OWNER,
            'sacco_id' => $request->sacco_id,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        // Place holder for: Mail::to($request->email)->send(new OwnerInvitationMail($invitation));

        return response()->json(['message' => 'Owner invitation sent successfully.', 'token' => $token]);
    }

    /**
     * Invite a new Driver (Called by Vehicle Owner)
     */
    public function inviteDriver(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'sacco_id' => 'required|exists:saccos,sacco_id',
        ]);

        $token = Str::random(40);

        $invitation = UserInvitation::create([
            'email' => $request->email,
            'token' => $token,
            'role' => UserRole::DRIVER,
            'sacco_id' => $request->sacco_id,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        // Place holder for: Mail::to($request->email)->send(new DriverInvitationMail($invitation));

        return response()->json(['message' => 'Driver invitation sent successfully.', 'token' => $token]);
    }

    /**
     * Accept invitation and signup
     */
    public function signup(Request $request, $token)
    {
        $invitation = UserInvitation::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('accepted_at')
            ->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'phone' => 'required|string',
        ]);

        return DB::transaction(function () use ($invitation, $request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $invitation->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => $invitation->role,
                'is_verified' => true,
                'email_verified_at' => now(),
            ]);

            $invitation->update(['accepted_at' => now()]);

            return response()->json([
                'message' => 'Account created successfully!',
                'user' => $user
            ]);
        });
    }
}
