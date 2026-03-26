<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use App\Events\UserRegistered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SaccoManagerApprovalController extends Controller
{
    /**
     * List Sacco Managers
     */
    public function index(Request $request)
    {
        $query = User::where('role', UserRole::SACCO)
            ->with(['tembeaOperatorProfile', 'permissions'])
            ->with('tembeaOperatorProfile.sacco'); // Assuming SaccoManager relates to Sacco

        // Better join with SaccoManager to get Sacco details
        $managers = User::where('role', UserRole::SACCO)
            ->join('sacco_managers', 'users.id', '=', 'sacco_managers.user_id')
            ->join('saccos', 'sacco_managers.sacco_id', '=', 'saccos.sacco_id')
            ->select('users.*', 'saccos.sacco_name', 'saccos.sacco_id as linked_sacco_id')
            ->orderBy('users.created_at', 'desc')
            ->get();

        return response()->json($managers);
    }

    /**
     * Approve a Sacco Manager
     */
    public function approve(User $user)
    {
        if ($user->role !== UserRole::SACCO) {
            return response()->json(['message' => 'User is not a Sacco Manager.'], 400);
        }

        $user->is_approved = true;
        $user->is_verified = true;
        $user->email_verified_at = $user->email_verified_at ?? now();

        // Generate a temporary password if they don't have one (or just reset it to be safe like AuthController does)
        $temporaryPassword = Str::random(8);
        $user->password = Hash::make($temporaryPassword);
        $user->save();

        Log::info('Sacco Manager approved via Service UI', ['user_id' => $user->id, 'email' => $user->email]);

        // In a real scenario, we might want to send an email with the temporary password here.
        // Reusing AuthController's logic if possible or dispatching an event.

        return response()->json([
            'message' => 'Sacco Manager approved successfully.',
            'temp_password' => $temporaryPassword // Return this so the service person can share it if needed, or send via email
        ]);
    }

    /**
     * Resend verification email
     */
    public function resendVerification(User $user)
    {
        if ($user->is_verified) {
            return response()->json(['message' => 'User is already verified.'], 400);
        }

        event(new UserRegistered($user));

        Log::info('Verification email resent via Service UI', ['user_id' => $user->id, 'email' => $user->email]);

        return response()->json(['message' => 'Verification email sent.']);
    }

    /**
     * Manually verify email
     */
    public function manualVerify(User $user)
    {
        $user->is_verified = true;
        $user->email_verified_at = now();
        $user->save();

        Log::info('User manually verified via Service UI', ['user_id' => $user->id, 'email' => $user->email]);

        return response()->json(['message' => 'User email verified manually.']);
    }
}
