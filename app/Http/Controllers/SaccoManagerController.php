<?php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\SaccoManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SaccoManagerController extends Controller
{
    public function getSaccoId(Request $request): JsonResponse
    { 
        $user = auth('sanctum')->user(); 
        \Log::info('Get sacco id', ['user' => $user]);
        if (! $user) {
            return response()->json(["message" => "Not authenticated"], 500);
        }


        $manager = SaccoManager::where('user_id', $user->id)->first();

        if (! $manager) {
            return response()->json(['message' => 'Sacco manager not found.'], 404);
        }

        return response()->json([
            'sacco_id' => $manager->sacco_id
        ]);
    }
}
