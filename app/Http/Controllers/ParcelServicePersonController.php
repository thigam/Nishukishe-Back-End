<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ParcelServicePersonController extends Controller
{
    public function requestRegistration(Request $request)
    {
        $request->merge(json_decode($request->getContent(), true) ?? []);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => UserRole::SERVICE_PERSON,
            'is_approved' => 0,
            'is_verified' => false,
        ]);

        return response()->json(['message' => 'Registration request submitted.'], 201);
    }

    public function pending()
    {
        $requests = User::where('role', UserRole::SERVICE_PERSON)
            ->where('is_approved', '!=', 1)
            ->get(['id','name','email','phone','is_approved']);

        return response()->json($requests);
    }

    public function approve($id)
    {
        $user = User::where('role', UserRole::SERVICE_PERSON)->findOrFail($id);
        $user->is_approved = 1;
        $user->save();

        return response()->json(['message' => 'Service person approved.']);
    }

    public function reject($id)
    {
        $user = User::where('role', UserRole::SERVICE_PERSON)->findOrFail($id);
        $user->is_approved = 2;
        $user->save();

        return response()->json(['message' => 'Service person rejected.']);
    }
}
