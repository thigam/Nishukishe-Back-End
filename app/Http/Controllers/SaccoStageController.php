<?php

namespace App\Http\Controllers;

use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\SaccoStage;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaccoStageController extends Controller
{
    public function index(string $saccoId): JsonResponse
    {
        $sacco = Sacco::where('sacco_id', $saccoId)->first();

        if (!$sacco) {
            return response()->json(['message' => 'Sacco not found'], 404);
        }

        $stages = $sacco->stages()->orderBy('name')->get();

        return response()->json($stages);
    }

    public function show(string $saccoId, string $stageId): JsonResponse
    {
        $stage = SaccoStage::with('sacco:sacco_id,sacco_name')
            ->where('sacco_id', $saccoId)
            ->where('id', $stageId)
            ->first();

        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        return response()->json($stage);
    }

    public function store(Request $request, string $saccoId): JsonResponse
    {
        $sacco = Sacco::where('sacco_id', $saccoId)->first();

        if (!$sacco) {
            return response()->json(['message' => 'Sacco not found'], 404);
        }

        $authCheck = $this->ensureUserCanManage($request, $sacco);
        if ($authCheck !== null) {
            return $authCheck;
        }

        $data = $this->validatePayload($request);
        $data['sacco_id'] = $sacco->sacco_id;

        $stage = SaccoStage::create($data);

        return response()->json($stage, 201);
    }

    public function update(Request $request, string $saccoId, string $stageId): JsonResponse
    {
        $stage = SaccoStage::where('sacco_id', $saccoId)
            ->where('id', $stageId)
            ->first();

        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        $sacco = Sacco::where('sacco_id', $saccoId)->first();
        if (!$sacco) {
            return response()->json(['message' => 'Sacco not found'], 404);
        }

        $authCheck = $this->ensureUserCanManage($request, $sacco);
        if ($authCheck !== null) {
            return $authCheck;
        }

        $data = $this->validatePayload($request);

        $stage->fill($data);
        $stage->save();

        return response()->json($stage->fresh());
    }

    public function destroy(Request $request, string $saccoId, string $stageId): JsonResponse
    {
        $stage = SaccoStage::where('sacco_id', $saccoId)
            ->where('id', $stageId)
            ->first();

        if (!$stage) {
            return response()->json(['message' => 'Stage not found'], 404);
        }

        $sacco = Sacco::where('sacco_id', $saccoId)->first();
        if (!$sacco) {
            return response()->json(['message' => 'Sacco not found'], 404);
        }

        $authCheck = $this->ensureUserCanManage($request, $sacco);
        if ($authCheck !== null) {
            return $authCheck;
        }

        $stage->delete();

        return response()->json(['message' => 'Stage deleted successfully.']);
    }

    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'destinations' => ['nullable', 'array'],
            'destinations.*' => ['string', 'max:255'],
        ]);

        $data['name'] = trim($data['name']);

        if ($data['name'] === '') {
            throw ValidationException::withMessages([
                'name' => 'Stage name is required.',
            ]);
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $data['description'] = $description !== '' ? $description : null;
        }

        if (array_key_exists('image_url', $data)) {
            $image = trim((string) $data['image_url']);
            $data['image_url'] = $image !== '' ? $image : null;
        }

        if (array_key_exists('destinations', $data)) {
            $data['destinations'] = is_array($data['destinations']) ? $data['destinations'] : [];
        }



        return $data;
    }

    private function ensureUserCanManage(Request $request, Sacco $sacco): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->role === UserRole::SUPER_ADMIN) {
            return null;
        }

        if ($user->role === UserRole::SERVICE_PERSON) {
            return null;
        }

        if ($user->role !== UserRole::SACCO) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $managesSacco = SaccoManager::where('user_id', $user->id)
            ->where('sacco_id', $sacco->sacco_id)
            ->exists();

        $matchesSaccoEmail = strcasecmp($user->email ?? '', $sacco->sacco_email ?? '') === 0;

        if (!$managesSacco && !$matchesSaccoEmail) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }
}
