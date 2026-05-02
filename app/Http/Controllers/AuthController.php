<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\TembeaOperatorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Events\UserRegistered;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use App\Events\Approved;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use App\Models\UserActionLog;
use App\Models\LocationPing;
use App\Models\ActivityLog;
use App\Models\DeviceSession;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function getUser(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(["message" => "Not authenticated"], 401);
        }
        return response()->json([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'role' => $request->user()->role,
            'permissions' => $request->user()->permissions->pluck('permission'),
        ]);
    }

    public function approveUser(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->update(['is_approved' => true]);

        $user->save();

        Log::info('User approved', ['user_id' => $user->id]);

        //create temporary password
        $temporaryPassword = bin2hex(random_bytes(4)); // 8 characters  b3cec499
        $user->password = Hash::make($temporaryPassword);
        $user->is_approved = 1;
        $user->is_verified = 1; // Ensure the user is verified upon approval
        $user->save();

        return response()->json(['message' => 'User approved successfully'], 200);
    }

    public function login(Request $request)
    {
        try {
            Log::info('Login request received', ['request' => $request->all()]);
            // Ensure the request is parsed as JSON
            $request->merge(json_decode($request->getContent(), true) ?? []);
            // Validate incoming request
            $validator = Validator::make($request->all(), [
                'email' => 'required|string',
                'password' => 'required|string',
                'role' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json(['message' => 'User not found!'], 401);
            }
            Log::info('user: ' . $user);
            if (!$user->is_verified) {
                return response()->json(['message' => 'Please verify your email'], 401);
            }
            if (!$user->is_approved && $user->role !== 'commuter') {
                return response()->json(['message' => 'Pending Approval'], 401);
            }

            // Check if the user exists and if the password is correct
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            $role = $user->role;

            $abilities = match ($user->role) {
                UserRole::SUPER_ADMIN => ['commuter.dashboard', 'sacco_admin.dashboard', 'super_admin.dashboard', 'superadmin.*'],
                UserRole::SACCO => ['commuter.dashboard', 'sacco_admin.dashboard'],
                UserRole::VEHICLE_OWNER => ['commuter.dashboard', 'vehicle_owner.dashboard'],
                UserRole::USER => ['commuter.dashboard', 'stops.index', 'stops.search'],
                UserRole::PARCEL_AGENT => ['commuter.dashboard', 'parcel_agent.dashboard'],
                default => [],
            };

            $token = $user->createToken('api-token')->plainTextToken;

            // Log the user in (sets session cookie)
            Auth::login($user, true); // true = remember me
            // Optionally regenerate session to prevent fixation
            $request->session()->regenerate();
            return response()->json([
                'message' => 'User successfully logged in!',
                'token' => $token,
                'role' => $role
            ], 200);

        } catch (\Exception $e) {
            Log::error('Login failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $this->assertGoogleConfigured();

        $role = $request->query('role');
        if ($role === UserRole::SUPER_ADMIN) {
            abort(403, 'Unauthorized role request.');
        }

        $state = $this->encodeState([
            'mode' => 'login',
            'role' => $role,
            'sacco_id' => $request->query('sacco_id'),
            'redirect' => $request->query('redirect'),
        ]);

        return $this->googleRedirectResponse($state);
    }

    public function redirectToGoogleForLinking(Request $request): RedirectResponse
    {
        $this->assertGoogleConfigured();

        $user = $request->user();
        if (!$user) {
            abort(401, 'Not authenticated');
        }

        $state = $this->encodeState([
            'mode' => 'link',
            'user_id' => $user->id,
        ]);

        return $this->googleRedirectResponse($state);
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $this->assertGoogleConfigured();

        try {
            $state = $this->decodeState($request->query('state'));
        } catch (\Throwable $e) {
            Log::warning('Invalid Google OAuth state', ['error' => $e->getMessage()]);
            return $this->redirectWithError('invalid_state', 'The Google sign-in link expired. Please try again.');
        }

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed', ['error' => $e->getMessage()]);
            return $this->redirectWithError('oauth_failed', 'Unable to complete Google sign in.');
        }

        $mode = $state['mode'] ?? 'login';

        if (!$this->googleEmailIsVerified($googleUser)) {
            return $this->redirectWithError('unverified_email', 'Verify your Google email before continuing.');
        }

        if ($mode === 'link') {
            return $this->linkGoogleAccount($state, $googleUser);
        }

        return $this->loginOrCreateFromGoogle($state, $googleUser);
    }

    public function unlinkGoogle(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $user->google_id = null;
        $user->save();

        return response()->json(['message' => 'Google account disconnected.']);
    }

    public function googleStatus(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        return response()->json([
            'linked' => (bool) $user->google_id,
            'email' => $user->email,
        ]);
    }

    public function register(Request $request)
    {
        Log::info('Registration request received', ['request' => $request->all()]);
        // Ensure the request is parsed as JSON
        $request->merge(json_decode($request->getContent(), true) ?? []);


        return $this->userSignUp($request);


    }

    //sacco sign up
    public function saccoAdminSignup(Request $request)
    {
        Log::info('Sacco registration request received');

        $roleMapping = [
            'sacco_admin' => UserRole::SACCO,
        ];
        $plainTextToken = $request->token;

        // Lookup token from DB
        $token = PersonalAccessToken::findToken($plainTextToken);

        if (!$token) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        // Retrieve user attached to token
        $user = $token->tokenable;

        // Optional: check if token expired
        if ($token->expires_at && now()->greaterThan($token->expires_at)) {
            return response()->json(['message' => 'Token expired'], 401);
        }
        $request->merge(json_decode($request->getContent(), true) ?? []);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($user) {
            $user->update([
                'name' => $request->name,
                'phone' => $request->phone,
                // 'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);


            $sacco = Sacco::where('sacco_email', $request->email)->first();
            if (!$sacco) {
                return response()->json(['message' => 'Sacco with this email does not exist. Please contact support'], 404);
            }

            $saccoManager = SaccoManager::create([
                'user_id' => $user->id,
                'sacco_id' => $sacco->sacco_id,
            ]);
            Log::info('Sacco updated successfully', ['sacco_id' => $user->id]);
            //delete the token after use
            $token->delete();
            return response()->json(['message' => 'Sacco updated successfully'], 200);
        }

        return response()->json(['message' => 'Sacco admin creation failed'], 404);
    }


    //admin sign up
    public function userSignUp(Request $request)
    {
        $roleMapping = [
            'nishukishe_service_person' => UserRole::SERVICE_PERSON,
            'driver' => UserRole::DRIVER,
            'government_official' => UserRole::GOVERNMENT,
            'sacco_admin' => UserRole::SACCO,
            'vehicle_owner' => UserRole::VEHICLE_OWNER,
            'commuter' => UserRole::USER,
            'tembea_admin' => UserRole::TEMBEA,
            'parcel_agent' => UserRole::PARCEL_AGENT,
        ];
        Log::info('Commuter sign-up request received', ['request' => $request->all()]);
        // Ensure the request is parsed as JSON
        $request->merge(json_decode($request->getContent(), true) ?? []);

        $roleKey = $request->input('role');

        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string',
            'sacco_id' => $roleKey === 'sacco_admin' ? 'required|string|exists:saccos,sacco_id' : 'nullable|string',
            'company_name' => $roleKey === UserRole::TEMBEA ? 'required|string|max:255' : 'nullable|string|max:255',
            'public_email' => 'nullable|email',
            'public_phone' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }
        if (!isset($roleMapping[$roleKey])) {
            return response()->json(['message' => 'Invalid role specified'], 400);
        }
        $roleValue = $roleMapping[$roleKey];

        // Check if the user already exists
        $existingUser = User::where('email', $request->email)
            ->orWhere('phone', $request->phone)
            ->first();
        if ($existingUser) {
            return response()->json([
                'message' => 'User with this email or phone already exists.'
            ], 409);
        }

        // Create a new commuter user
        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'role' => $roleValue,
            'password' => Hash::make($request->password),
            'is_verified' => false,
        ]);

        if ($roleValue === UserRole::SACCO) {
            SaccoManager::create([
                'user_id' => $user->id,
                'sacco_id' => $request->sacco_id,
            ]);
        }
        if ($roleValue === UserRole::TEMBEA) {
            TembeaOperatorProfile::create([
                'user_id' => $user->id,
                'company_name' => $request->company_name,
                'contact_name' => $request->name,
                'contact_email' => $request->email,
                'contact_phone' => $request->phone,
                'public_email' => $request->public_email,
                'public_phone' => $request->public_phone,
                'status' => 'pending',
            ]);
        }
        //verify user email
        \Log::info('User registered successfully', ['email' => $user->email]);
        event(new UserRegistered($user));

        \Log::info('UserRegistered event dispatched for user: ' . $user->email);

        // Return success response with user data and token
        return response()->json([
            'message' => 'Commuter successfully registered!',
        ], 201);
    }


    public function logout(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(["message" => "Not authenticated"], 401);
        }

        // If logged in via token, delete tokens
        if ($user->currentAccessToken() && !$user->currentAccessToken() instanceof TransientToken) {
            $user->tokens()->delete();
        }

        // If logged in via session (cookie), destroy session
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('User logged out successfully', ['user_id' => $user->id]);

        return response()->json(['message' => 'User successfully logged out'], 200);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(["message" => "Not authenticated"], 401);
        }

        // Ensure the request is parsed as JSON
        $request->merge(json_decode($request->getContent(), true) ?? []);

        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|unique:users,phone,' . $user->id,
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update user fields if provided
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        if ($request->has('email')) {
            $user->email = $request->email;
            $user->is_verified = false; // Mark as unverified if email changes
            // Trigger email verification process here if needed
        }
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        Log::info('User profile updated successfully', ['user_id' => $user->id]);

        return response()->json(['message' => 'Profile updated successfully'], 200);
    }

    protected function googleRedirectResponse(string $state): RedirectResponse
    {
        $redirect = config('services.google.redirect') ?? url('/api/auth/google/callback');

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account', 'state' => $state])
            ->redirectUrl($redirect)
            ->redirect();
    }

    protected function assertGoogleConfigured(): void
    {
        if (!config('services.google.client_id') || !config('services.google.client_secret')) {
            abort(500, 'Google OAuth is not configured.');
        }
    }

    protected function encodeState(array $payload): string
    {
        $json = json_encode($payload);
        $signature = hash_hmac('sha256', $json, config('app.key'));

        return base64_encode(json_encode([
            'payload' => $payload,
            'signature' => $signature,
        ]));
    }

    protected function decodeState(?string $state): array
    {
        if (!$state) {
            throw new \RuntimeException('Missing state');
        }

        $decoded = json_decode(base64_decode($state, true) ?: '', true);
        if (!is_array($decoded) || !isset($decoded['payload'], $decoded['signature'])) {
            throw new \RuntimeException('Invalid state payload');
        }

        $payloadJson = json_encode($decoded['payload']);
        $expectedSignature = hash_hmac('sha256', $payloadJson, config('app.key'));

        if (!hash_equals($expectedSignature, (string) $decoded['signature'])) {
            throw new \RuntimeException('State signature mismatch');
        }

        return $decoded['payload'];
    }

    protected function resolveRole(?string $roleKey): ?string
    {
        return match ($roleKey) {
            UserRole::USER => UserRole::USER,
            UserRole::SACCO => UserRole::SACCO,
            UserRole::SERVICE_PERSON => UserRole::SERVICE_PERSON,
            UserRole::DRIVER => UserRole::DRIVER,
            UserRole::VEHICLE_OWNER => UserRole::VEHICLE_OWNER,
            UserRole::GOVERNMENT => UserRole::GOVERNMENT,
            UserRole::TEMBEA => UserRole::TEMBEA,
            UserRole::PARCEL_AGENT => UserRole::PARCEL_AGENT,
            default => null,
        };
    }

    protected function loginOrCreateFromGoogle(array $state, SocialiteUser $googleUser): RedirectResponse
    {
        $email = $googleUser->getEmail();
        if (!$email) {
            return $this->redirectWithError('missing_email', 'Your Google account must expose an email address.');
        }

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere(function ($query) use ($email) {
                $query->where('email', $email);
            })
            ->first();

        \Log::info('Google OAuth lookup result', [
            'found' => (bool) $user,
            'email' => $email,
            'google_id' => $googleUser->getId()
        ]);

        if (!$user) {
            $roleValue = $this->resolveRole($state['role'] ?? null);

            if (!$roleValue) {
                return $this->redirectWithError('missing_role', 'Select your role before signing in with Google.');
            }

            $user = User::create([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                'phone' => sprintf('google-%s', $googleUser->getId()),
                'role' => $roleValue,
                'password' => Hash::make(Str::random(32)),
                'google_id' => $googleUser->getId(),
                'is_verified' => true,
                'is_approved' => ($roleValue === UserRole::USER),
                'email_verified_at' => now(),
            ]);

            \Log::info('Google OAuth user created', [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'is_approved' => $user->is_approved
            ]);

            // Save Sacco ID if provided for relevant roles
            $saccoId = $state['sacco_id'] ?? null;
            if ($saccoId && ($roleValue === UserRole::SACCO)) {
                SaccoManager::create([
                    'user_id' => $user->id,
                    'sacco_id' => $saccoId,
                ]);
            }

            // Dispatch registration event (triggers welcome email)
            event(new UserRegistered($user));
        } else {
            if (!$user->google_id) {
                $user->google_id = $googleUser->getId();
            }

            if (!$user->email) {
                $user->email = $email;
            }

            if (!$user->name) {
                $user->name = $googleUser->getName() ?: $email;
            }

            $user->email_verified_at = now();
            $user->is_verified = true;
            $user->save();
        }

        if ($user->role !== UserRole::USER && !$user->is_approved) {
            return $this->redirectWithError('pending_approval', 'Your account is pending approval.');
        }

        return $this->redirectWithToken($user, 'login', null, $state['redirect'] ?? null);
    }

    protected function linkGoogleAccount(array $state, SocialiteUser $googleUser): RedirectResponse
    {
        $user = isset($state['user_id']) ? User::find($state['user_id']) : null;

        if (!$user) {
            return $this->redirectWithError('link_user_missing', 'Unable to find the account to link.');
        }

        $existing = User::where('google_id', $googleUser->getId())
            ->where('id', '!=', $user->id)
            ->exists();

        if ($existing) {
            return $this->redirectWithError('google_in_use', 'That Google account is already linked to another profile.');
        }

        $user->google_id = $googleUser->getId();
        $user->email_verified_at = now();
        $user->is_verified = true;

        if (!$user->email && $googleUser->getEmail()) {
            $user->email = $googleUser->getEmail();
        }

        if (!$user->name && $googleUser->getName()) {
            $user->name = $googleUser->getName();
        }

        $user->save();

        return $this->redirectWithToken($user, 'link', 'Google account connected successfully.');
    }

    protected function redirectWithToken(User $user, string $mode = 'login', ?string $message = null, ?string $redirect = null): RedirectResponse
    {
        $token = $user->createToken('api-token')->plainTextToken;

        return $this->redirectToFrontend([
            'token' => $token,
            'role' => $user->role,
            'email' => $user->email,
            'mode' => $mode,
            'message' => $message,
            'redirect' => $redirect,
        ]);
    }

    protected function redirectWithError(string $error, ?string $message = null): RedirectResponse
    {
        return $this->redirectToFrontend([
            'error' => $error,
            'message' => $message,
        ]);
    }

    protected function redirectToFrontend(array $params): RedirectResponse
    {
        $redirect = $params['redirect'] ?? null;
        unset($params['redirect']);

        $baseUrl = config('services.google.frontend_redirect') ?? config('app.url');

        // If a redirect parameter is provided, use it as the base (e.g. for mobile deep links)
        if ($redirect) {
            if (str_starts_with($redirect, 'http')) {
                // Security: Only allow web redirects to our own domains
                $allowedDomains = [
                    parse_url(config('app.url'), PHP_URL_HOST),
                    parse_url(config('services.google.frontend_redirect'), PHP_URL_HOST)
                ];
                $targetDomain = parse_url($redirect, PHP_URL_HOST);

                if (in_array($targetDomain, array_filter($allowedDomains))) {
                    $baseUrl = $redirect;
                }
            } else {
                // Custom scheme (e.g. com.nishukishe.app://) - allow it
                $baseUrl = $redirect;
            }
        }

        $frontend = rtrim($baseUrl, '/');
        $query = http_build_query(array_filter(
            $params,
            static fn($value) => !is_null($value) && $value !== ''
        ));

        $url = $frontend . ($query ? (str_contains($frontend, '?') ? '&' : '?') . $query : '');

        return redirect()->away($url);
    }

    protected function googleEmailIsVerified(SocialiteUser $googleUser): bool
    {
        return (bool) data_get($googleUser->user ?? [], 'verified_email', true);
    }

    public function deleteProfile(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(["message" => "Not authenticated"], 401);
        }

        $mode = $request->input('mode', 'full'); // full | purge

        return DB::transaction(function () use ($user, $request, $mode) {
            if ($mode === 'full') {
                // Log the action before deleting the user
                UserActionLog::create([
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'action' => 'account_deleted',
                ]);

                // Deleting the user will:
                // 1. Delete Sanctum tokens (automatic)
                // 2. Nullify user_id in device_sessions (nullOnDelete)
                // 3. Nullify user_id in activity_logs (nullOnDelete)
                // 4. Cascade delete comments (cascadeOnDelete)
                $user->delete();

                return response()->json(['message' => 'Account deleted successfully']);
            }

            if ($mode === 'purge') {
                $deviceId = $request->input('device_id');

                // Security: Only delete pings if the device_id is valid and has been used by this user
                if ($deviceId && is_string($deviceId)) {
                    $ownsDevice = DeviceSession::where('user_id', $user->id)
                        ->where('device_id', $deviceId)
                        ->exists();

                    if ($ownsDevice) {
                        LocationPing::where('device_id', $deviceId)->delete();
                    }
                }

                // Delete activity logs for this user
                ActivityLog::where('user_id', $user->id)->delete();

                UserActionLog::create([
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'action' => 'data_purged',
                    'metadata' => ['device_id' => $deviceId]
                ]);

                return response()->json(['message' => 'Historical data purged successfully']);
            }

            return response()->json(['message' => 'Invalid mode'], 400);
        });
    }
}


