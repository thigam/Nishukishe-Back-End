<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DriverInvitation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\UserRole;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\DriverInvitationMail;

class DriverInvitationController extends Controller
{
    public function invite(Request $request)
    {
        $request->validate([
            'sacco_id' => 'required|exists:saccos,sacco_id',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'name' => 'required|string|max:255',
            'vehicle_registration' => 'required|string|max:50',
        ]);

        $token = Str::random(40);

        $invitation = DriverInvitation::create([
            'sacco_id' => $request->sacco_id,
            'email' => $request->email,
            'phone' => $request->phone,
            'name' => $request->name,
            'vehicle_registration' => $request->vehicle_registration,
            'token' => $token,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($request->email)->send(new DriverInvitationMail($invitation));

        return response()->json(['message' => 'Invitation sent successfully.']);
    }

    public function verify($token)
    {
        $invitation = DriverInvitation::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('accepted_at')
            ->firstOrFail();

        return response()->json([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'sacco_id' => $invitation->sacco_id,
            'sacco_name' => $invitation->sacco->sacco_name,
            'vehicle_registration' => $invitation->vehicle_registration,
            'inviter_name' => $invitation->inviter->name
        ]);
    }

    public function signup(Request $request, $token)
    {
        $invitation = DriverInvitation::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('accepted_at')
            ->firstOrFail();

        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Create approved & verified user driver
        $user = User::create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'password' => Hash::make($request->password),
            'role' => UserRole::DRIVER,
            'is_verified' => true,
            'is_approved' => true,
            'email_verified_at' => now(),
        ]);

        // Associate vehicle
        $vehicle = Vehicle::where('registration_number', $invitation->vehicle_registration)->first();
        if ($vehicle) {
            $vehicle->update([
                'driver_id' => $user->id,
                'sacco_id' => $invitation->sacco_id,
            ]);
        } else {
            Vehicle::create([
                'sacco_id' => $invitation->sacco_id,
                'registration_number' => $invitation->vehicle_registration,
                'driver_id' => $user->id,
                'share_location_with_sacco' => true,
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        return response()->json(['message' => 'Driver account created successfully! You can now log in.']);
    }
}
