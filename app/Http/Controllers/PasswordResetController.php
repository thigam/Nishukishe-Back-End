<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Validator;

class PasswordResetController extends Controller
{
    public function forgot($email)
    {
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $user = User::where('email', $email)->first();
        \Log::info('Password reset request for email: ' . $email);

        if (!$user) {
            return response()->json(['message' => 'Email not found'], 404);
        }

        $token = Password::createToken($user);
        $frontendUrl = config('app.frontend_url');
        $resetUrl = $frontendUrl . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);

        \Log::info('Password reset link: ' . $resetUrl);

        event(new PasswordResetLinkSent($email, $resetUrl));

        return response()->json(['message' => 'Sent reset link'], 200);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'token' => $validated['token'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();

                \Log::info('Password reset successful for user: ' . $user->email);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully'], 200);
        }

        return response()->json(['message' => __($status)], 400);
    }

    //verification
    public function verify(Request $request, $id, $hash)
    {
        $data = [
            'id' => $id,
            'hash' => $hash,
            'expires' => $request->query('expires'),
        ];

        $validator = Validator::make($data, [
            'id' => 'required|integer|exists:users,id',
            'hash' => 'required|string|size:40',
            'expires' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid verification link',
                'id' => $id,
                'hash' => $hash,
                'expires' => $data['expires'],
                'errors' => $validator->errors(),
            ], 400);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->markEmailAsVerified();
        $user->is_verified = true;
        $user->save();

        return response()->json([
            'message' => 'Link validated successfully.',
            'id' => $id,
            // 'hash' => $hash,
            // 'expires' => $data['expires'],
        ]);
    }

    public function sendNotification(Request $request)
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('message', 'Verification link sent!');
    }

    public function notice(Request $request)
    {
        return response()->json([
            'message' => 'Please verify your email address.',
            'id' => $request->id,
        ]);
    }
}
