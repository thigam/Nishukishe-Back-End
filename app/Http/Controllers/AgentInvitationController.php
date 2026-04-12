<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AgentInvitation;
use App\Models\User;
use App\Models\ParcelAgent;
use App\Models\UserPermission;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\AgentInvitationMail;

class AgentInvitationController extends Controller
{
    public function invite(Request $request)
    {
        $request->validate([
            'sacco_id' => 'required|exists:saccos,sacco_id',
            'email' => 'required|email'
        ]);

        $token = Str::random(40);

        $invitation = AgentInvitation::create([
            'sacco_id' => $request->sacco_id,
            'email' => $request->email,
            'token' => $token,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($request->email)->send(new AgentInvitationMail($invitation));

        return response()->json(['message' => 'Invitation sent successfully.']);
    }

    public function verify($token)
    {
        $invitation = AgentInvitation::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('accepted_at')
            ->firstOrFail();

        return response()->json([
            'email' => $invitation->email,
            'sacco_id' => $invitation->sacco_id,
            'sacco_name' => $invitation->sacco->sacco_name,
            'inviter_name' => $invitation->inviter->name
        ]);
    }

    public function signup(Request $request, $token)
    {
        $invitation = AgentInvitation::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('accepted_at')
            ->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'phone' => 'required|string',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $invitation->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'parcel_agent',
            'is_verified' => true, // verified email from invite token
            'is_approved' => false, // requires manager approval
            'email_verified_at' => now(),
        ]);

        ParcelAgent::create([
            'user_id' => $user->id,
            'sacco_id' => $invitation->sacco_id,
            'is_active' => true, // but account is unapproved
        ]);

        $invitation->update(['accepted_at' => now()]);

        return response()->json(['message' => 'Account created! Waiting for Sacco Manager approval.']);
    }
}
