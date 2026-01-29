<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // $this->artisan('migrate:fresh', ['--path' => 'database/testing-migrations']);
        $this->withoutMiddleware(LogUserActivity::class);

        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'https://api.test/auth/google/callback',
            'services.google.frontend_redirect' => 'https://frontend.test/login/google/callback',
        ]);
    }

    public function test_google_callback_issues_token_for_existing_user(): void
    {
        $user = User::create([
            'name' => 'Existing User',
            'email' => 'google@example.com',
            'phone' => 'google-123',
            'password' => Hash::make('secret'),
            'role' => UserRole::USER,
            'google_id' => 'google-123',
            'is_verified' => true,
            'is_approved' => true,
        ]);

        $socialiteUser = $this->makeSocialiteUser([
            'id' => 'google-123',
            'email' => 'google@example.com',
        ]);

        $this->mockSocialite($socialiteUser);

        $state = $this->makeState(['mode' => 'login', 'role' => UserRole::USER]);

        $response = $this->get('/auth/google/callback?state=' . urlencode($state));

        $response->assertRedirect();
        $params = $this->parseRedirectQuery($response);

        $this->assertSame($user->role, $params['role']);
        $this->assertArrayHasKey('token', $params);
        $this->assertNotEmpty($params['token']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_google_callback_requires_verified_email(): void
    {
        $socialiteUser = $this->makeSocialiteUser(['verified_email' => false]);
        $this->mockSocialite($socialiteUser);

        $state = $this->makeState(['mode' => 'login', 'role' => UserRole::USER]);

        $response = $this->get('/auth/google/callback?state=' . urlencode($state));
        $params = $this->parseRedirectQuery($response);

        $this->assertSame('unverified_email', $params['error']);
    }

    public function test_google_callback_errors_when_role_missing_for_new_user(): void
    {
        $socialiteUser = $this->makeSocialiteUser(['id' => 'google-999']);
        $this->mockSocialite($socialiteUser);

        $state = $this->makeState(['mode' => 'login']);

        $response = $this->get('/auth/google/callback?state=' . urlencode($state));
        $params = $this->parseRedirectQuery($response);

        $this->assertSame('missing_role', $params['error']);
        $this->assertDatabaseCount('users', 0);
    }

    protected function mockSocialite(SocialiteUser $socialiteUser): void
    {
        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);
    }

    protected function makeSocialiteUser(array $overrides = []): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->id = $overrides['id'] ?? 'google-123';
        $user->name = $overrides['name'] ?? 'Google User';
        $user->email = $overrides['email'] ?? 'google@example.com';
        $user->user = array_merge([
            'verified_email' => $overrides['verified_email'] ?? true,
        ], $overrides['extra'] ?? []);

        return $user;
    }

    protected function makeState(array $payload): string
    {
        $json = json_encode($payload);
        $signature = hash_hmac('sha256', $json, config('app.key'));

        return base64_encode(json_encode([
            'payload' => $payload,
            'signature' => $signature,
        ]));
    }

    protected function parseRedirectQuery($response): array
    {
        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $query = parse_url($target, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);

        return $params;
    }
}
