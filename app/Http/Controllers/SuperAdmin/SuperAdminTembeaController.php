<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\TembeaOperatorProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\TembeaOperatorAccessGranted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperAdminTembeaController extends Controller
{
    public function createPlaceholderOperator(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $companyName = $validated['company_name'];
        $slug = Str::slug($companyName);

        // Ensure unique slug
        $originalSlug = $slug;
        $count = 1;
        while (TembeaOperatorProfile::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        $email = sprintf('%s.%s@placeholder.local', $slug, Str::uuid());

        // Create User
        $user = User::create([
            'name' => $companyName,
            'email' => $email,
            'phone' => $validated['contact_phone'] ?? null,
            'role' => UserRole::TEMBEA,
            'password' => Hash::make(Str::random(32)),
            'is_verified' => true, // Auto-verify placeholders
            'is_approved' => true,
        ]);

        // Create Profile
        $profile = TembeaOperatorProfile::create([
            'user_id' => $user->id,
            'company_name' => $companyName,
            'slug' => $slug,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_email' => $email, // Use placeholder email initially
            'contact_phone' => $validated['contact_phone'] ?? null,
            'status' => 'approved',
            'metadata' => ['is_placeholder' => true],
        ]);

        return response()->json([
            'message' => 'Placeholder operator created successfully.',
            'operator' => $profile->load('user'),
        ], 201);
    }

    public function changeOperatorEmail(Request $request, $operatorId): JsonResponse
    {
        $profile = TembeaOperatorProfile::with('user')->findOrFail($operatorId);
        $user = $profile->user;

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $newEmail = $validated['email'];
        $oldEmail = $user->email;
        $isPlaceholder = Str::endsWith($oldEmail, '@placeholder.local');

        DB::transaction(function () use ($user, $profile, $newEmail, $oldEmail, $isPlaceholder) {
            // Update User email
            $user->email = $newEmail;
            $user->save();

            // Update Profile contact email if it matches the old user email
            if ($profile->contact_email === $oldEmail) {
                $profile->contact_email = $newEmail;
            }

            // Remove placeholder flag
            $metadata = $profile->metadata ?? [];
            if (isset($metadata['is_placeholder'])) {
                unset($metadata['is_placeholder']);
                $profile->metadata = $metadata;
            }
            $profile->save();

            // Send password reset link to new email
            $token = Password::createToken($user);
            $user->sendPasswordResetNotification($token);

            // Notify old email if not placeholder
            // TODO: Implement notification for old email if needed
        });

        return response()->json([
            'message' => 'Operator email updated. Password reset link sent to new email.',
            'operator' => $profile->fresh(['user']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = TembeaOperatorProfile::with('user');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('company_name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhereHas('user', function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%");
                });
        }

        return response()->json($query->paginate(20));
    }

    public function analytics(Request $request): JsonResponse
    {
        $totalVisits = \App\Models\TembeziAnalytics::where('event_type', 'visit')->count();
        $totalContactClicks = \App\Models\TembeziAnalytics::where('event_type', 'contact_click')->count();
        $totalContactOpens = \App\Models\TembeziAnalytics::where('event_type', 'contact_open')->count();

        // Top 5 Tembezis by visits
        $topTembezis = \App\Models\TembeziAnalytics::where('event_type', 'visit')
            ->select('tembezi_id', DB::raw('count(*) as total'))
            ->groupBy('tembezi_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('tembezi.bookable') // Assuming relationship exists
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->tembezi_id,
                    'title' => $item->tembezi->bookable->title ?? 'Unknown',
                    'visits' => $item->total,
                ];
            });

        return response()->json([
            'total_visits' => $totalVisits,
            'total_contact_clicks' => $totalContactClicks,
            'total_contact_opens' => $totalContactOpens,
            'top_tembezis' => $topTembezis,
        ]);
    }
}
