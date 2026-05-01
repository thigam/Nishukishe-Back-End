<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendEngagementNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage examples:
     *   php artisan notifications:send-engagement
     *   php artisan notifications:send-engagement --type=traffic
     *   php artisan notifications:send-engagement --type=routes --title="New routes added" --body="Check out new matatu routes near you"
     *
     * @var string
     */
    protected $signature = 'notifications:send-engagement
                            {--type=traffic : Notification type: traffic|routes|custom}
                            {--title= : Override the default notification title}
                            {--body= : Override the default notification body}
                            {--link= : Deep link path to open when user taps (e.g. /occurrences)}
                            {--dry-run : Print what would be sent without actually sending}';

    protected $description = 'Send engagement push notifications to all active device tokens via FCM V1 API.';

    /**
     * Pre-written message templates. You can extend this array freely.
     */
    private array $templates = [
        'traffic' => [
            'title' => '🚗 Nairobi Traffic Update',
            'body'  => 'Check current road conditions and incidents near you before you set off today.',
        ],
        'routes' => [
            'title' => '🚌 Find Your Route on Nishukishe',
            'body'  => 'Did you know you can plan your matatu journey to any destination? Try it now!',
        ],
        'custom' => [
            'title' => 'Nishukishe',
            'body'  => 'Check the app for the latest travel updates.',
        ],
    ];

    public function handle(): int
    {
        $type   = $this->option('type');
        $isDry  = $this->option('dry-run');

        // Resolve message content — CLI overrides take priority over templates
        $template = $this->templates[$type] ?? $this->templates['custom'];
        $title    = $this->option('title') ?: $template['title'];
        $body     = $this->option('body')  ?: $template['body'];

        $this->info("Notification type: [{$type}]");
        $this->info("Title : {$title}");
        $this->info("Body  : {$body}");

        if ($isDry) {
            $this->warn('[DRY RUN] No notifications sent.');
            return self::SUCCESS;
        }

        // ── FCM V1 API credentials ─────────────────────────────────────────────
        // FCM V1 uses OAuth2 via a Service Account JSON, not a simple server key.
        // Store your service account JSON path in FCM_SERVICE_ACCOUNT_PATH in .env.
        $serviceAccountPath = config('services.fcm.service_account_path');

        if (!$serviceAccountPath || !file_exists($serviceAccountPath)) {
            $this->error('FCM service account JSON not found. Set FCM_SERVICE_ACCOUNT_PATH in .env.');
            return self::FAILURE;
        }

        $projectId = config('services.fcm.project_id');

        if (!$projectId) {
            $this->error('FCM project ID not configured. Set FCM_PROJECT_ID in .env.');
            return self::FAILURE;
        }

        // Get OAuth2 access token from the service account
        $accessToken = $this->getAccessToken($serviceAccountPath);

        if (!$accessToken) {
            $this->error('Failed to obtain FCM access token.');
            return self::FAILURE;
        }

        // ── Fetch all active tokens ────────────────────────────────────────────
        $tokens = DeviceToken::where('is_active', true)->pluck('token');

        if ($tokens->isEmpty()) {
            $this->warn('No active device tokens found. Nothing sent.');
            return self::SUCCESS;
        }

        $this->info("Sending to {$tokens->count()} device(s)...");

        $sent   = 0;
        $failed = 0;
        $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            $deepLink = $this->option('link') ?: '/';

            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    // Data payload — accessible in the app even when tapped from background
                    'data' => [
                        'link' => $deepLink,
                    ],
                    'android' => [
                        'notification' => [
                            'sound'        => 'default',
                            'click_action' => 'OPEN_ACTIVITY_1',
                            // Use your app's drawable resource name (without extension)
                            // Place ic_notification.png in android/app/src/main/res/drawable/
                            'icon'         => 'ic_notification',
                            // Brand color shown in the notification shade
                            'color'        => '#2563EB',
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->post($fcmUrl, $payload);

            if ($response->successful()) {
                $sent++;
            } else {
                $failed++;
                $responseData = $response->json();
                // Mark token as inactive if it's no longer registered
                if (($responseData['error']['status'] ?? '') === 'NOT_FOUND') {
                    DeviceToken::where('token', $token)->update(['is_active' => false]);
                    $this->warn("Token deactivated (device unregistered): " . substr($token, 0, 20) . '...');
                } else {
                    Log::warning('FCM send failed', ['token' => substr($token, 0, 20), 'error' => $responseData]);
                }
            }
        }

        $this->info("✅ Sent: {$sent}  ❌ Failed: {$failed}");
        Log::info("Engagement notification [{$type}] sent.", ['sent' => $sent, 'failed' => $failed]);

        return self::SUCCESS;
    }

    /**
     * Exchange the service account JSON for a short-lived OAuth2 bearer token
     * using Google's self-signed JWT approach.
     */
    private function getAccessToken(string $serviceAccountPath): ?string
    {
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

        if (!$serviceAccount) {
            return null;
        }

        $now    = time();
        $expiry = $now + 3600;
        $scope  = 'https://www.googleapis.com/auth/firebase.messaging';

        // Build the JWT claim set
        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims  = base64_encode(json_encode([
            'iss'   => $serviceAccount['client_email'],
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $expiry,
        ]));

        $toSign    = "{$header}.{$claims}";
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        openssl_sign($toSign, $signature, $privateKey, 'SHA256');
        $jwt = "{$toSign}." . base64_encode($signature);

        // Exchange JWT for access token
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        return $response->json('access_token');
    }
}
