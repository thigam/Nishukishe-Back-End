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
        $cutoff = now()->subHours(72);

        $managers = User::where('users.role', UserRole::SACCO)
            ->join('sacco_managers', 'users.id', '=', 'sacco_managers.user_id')
            ->join('saccos', 'sacco_managers.sacco_id', '=', 'saccos.sacco_id')
            ->where(function ($query) use ($cutoff) {
                $query->where('users.is_approved', false)
                    ->orWhere('users.is_verified', false)
                    ->orWhere(function ($sub) use ($cutoff) {
                        $sub->whereNotNull('users.email_verified_at')
                            ->where('users.email_verified_at', '>=', $cutoff);
                    });
            })
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
        // If not already verified, set verified_at to now for the 72h window
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        Log::info('Sacco Manager approved via Service UI', ['user_id' => $user->id, 'email' => $user->email]);

        return response()->json([
            'message' => 'Sacco Manager approved successfully.'
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

    /**
     * List all service persons and their permissions
     */
    public function servicePersons()
    {
        $servicePersons = User::where('role', UserRole::SERVICE_PERSON)
            ->with('permissions')
            ->get();

        return response()->json($servicePersons);
    }
}
