<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendOnboardingTips extends Command
{
    protected $signature = 'notifications:send-onboarding-tips {--dry-run}';
    protected $description = 'Send automated, time-delayed onboarding/usage tips to active device tokens.';

    private array $tips = [
        'tip_1' => [
            'day'   => 1,
            'title' => '🔔 Get Instant Road Alerts!',
            'body'  => 'Configure your watched roads and locations in your Profile to get real-time push alerts about traffic and accidents.',
            'link'  => '/profile',
        ],
        'tip_2' => [
            'day'   => 3,
            'title' => '📍 Saved Locations Guide',
            'body'  => 'Save your daily commute locations to receive automated updates when incidents happen within 2km.',
            'link'  => '/profile',
        ],
        'tip_3' => [
            'day'   => 7,
            'title' => '🚌 Smart Journey Planning',
            'body'  => 'Use the Nishukishe Search Bar to find public transit routes, fares, and estimated travel times.',
            'link'  => '/',
        ],
    ];

    public function handle(): int
    {
        $this->info("Checking device tokens for onboarding/usage tips...");
        $isDry = $this->option('dry-run');

        $serviceAccountPath = config('services.fcm.service_account_path');
        $projectId = config('services.fcm.project_id');

        if (!$isDry) {
            if (!$serviceAccountPath || !file_exists($serviceAccountPath)) {
                $this->error('FCM service account JSON not found. Set FCM_SERVICE_ACCOUNT_PATH in .env.');
                return self::FAILURE;
            }
            if (!$projectId) {
                $this->error('FCM project ID not configured. Set FCM_PROJECT_ID in .env.');
                return self::FAILURE;
            }
        }

        $accessToken = $isDry ? 'dry-run-token' : $this->getAccessToken($serviceAccountPath);
        if (!$accessToken) {
            $this->error('Failed to obtain FCM access token.');
            return self::FAILURE;
        }

        // Fetch all active device tokens
        $deviceTokens = DeviceToken::where('is_active', true)->get();

        if ($deviceTokens->isEmpty()) {
            $this->info("No active device tokens found.");
            return self::SUCCESS;
        }

        $sentCount = 0;
        $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($deviceTokens as $deviceToken) {
            $created = Carbon::parse($deviceToken->created_at);
            $daysDiff = $created->diffInDays(Carbon::now());
            $sentTips = $deviceToken->sent_onboarding_tips ?? [];

            $selectedTipKey = null;
            if ($daysDiff >= 7) {
                if (!in_array('tip_3', $sentTips)) {
                    $selectedTipKey = 'tip_3';
                }
            } elseif ($daysDiff >= 3) {
                if (!in_array('tip_2', $sentTips)) {
                    $selectedTipKey = 'tip_2';
                }
            } elseif ($daysDiff >= 1) {
                if (!in_array('tip_1', $sentTips)) {
                    $selectedTipKey = 'tip_1';
                }
            }

            if (!$selectedTipKey) {
                continue;
            }

            $tip = $this->tips[$selectedTipKey];
            $this->info("Sending {$selectedTipKey} (Day {$tip['day']} Tip) to Token ID: {$deviceToken->id} (Created {$daysDiff} days ago)");

            if ($isDry) {
                $sentCount++;
                continue;
            }

            $payload = [
                'message' => [
                    'token' => $deviceToken->token,
                    'notification' => [
                        'title' => $tip['title'],
                        'body'  => $tip['body'],
                    ],
                    'data' => [
                        'link' => $tip['link'],
                    ],
                    'android' => [
                        'notification' => [
                            'sound'        => 'default',
                            'click_action' => 'OPEN_ACTIVITY_1',
                            'icon'         => 'ic_notification',
                            'color'        => '#2563EB',
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)->post($fcmUrl, $payload);

            if ($response->successful()) {
                $sentCount++;
                $sentTips[] = $selectedTipKey;
                $deviceToken->update(['sent_onboarding_tips' => $sentTips]);
            } else {
                $responseData = $response->json();
                if (($responseData['error']['status'] ?? '') === 'NOT_FOUND') {
                    $deviceToken->update(['is_active' => false]);
                    $this->warn("Deactivated unregistered token ID: {$deviceToken->id}");
                } else {
                    Log::warning('FCM onboarding tip send failed', [
                        'token_id' => $deviceToken->id,
                        'error'    => $responseData,
                    ]);
                }
            }
        }

        $this->info("Processed complete. Sent: {$sentCount} onboarding tips.");
        return self::SUCCESS;
    }

    private function getAccessToken(string $serviceAccountPath): ?string
    {
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
        if (!$serviceAccount) {
            return null;
        }

        $now    = time();
        $expiry = $now + 3600;
        $scope  = 'https://www.googleapis.com/auth/firebase.messaging';

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

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        return $response->json('access_token');
    }
}
