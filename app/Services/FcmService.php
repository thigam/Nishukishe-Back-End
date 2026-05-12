<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Send a notification via FCM v1 API.
     */
    public function sendNotification(array $tokens, string $title, string $body, string $link = '/', ?int $campaignId = null)
    {
        $serviceAccountPath = config('services.fcm.service_account_path');
        if (!$serviceAccountPath || !file_exists($serviceAccountPath)) {
            Log::error('FCM service account JSON not found at: ' . ($serviceAccountPath ?: 'PATH_NOT_SET'));
            return ['sent' => 0, 'failed' => 0, 'error' => 'config_error'];
        }

        // Quick check for JSON validity
        $json = json_decode(file_get_contents($serviceAccountPath), true);
        if (!$json || !isset($json['private_key'])) {
            Log::error('FCM service account JSON is invalid or missing private_key.');
            return ['sent' => 0, 'failed' => 0, 'error' => 'config_error'];
        }

        $projectId = config('services.fcm.project_id');
        if (!$projectId) {
            Log::error('FCM project ID not configured.');
            return ['sent' => 0, 'failed' => 0];
        }

        $accessToken = $this->getAccessToken($serviceAccountPath);
        if (!$accessToken) {
            Log::error('Failed to obtain FCM access token.');
            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;
        $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            $data = [
                'link' => $link,
            ];

            if ($campaignId) {
                $data['campaign_id'] = (string) $campaignId;
            }

            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data' => $data,
                    'android' => [
                        'notification' => [
                            'sound'        => 'default',
                            'icon'         => 'ic_stat_bus',
                            'color'        => '#2563EB',
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)->post($fcmUrl, $payload);

            if ($response->successful()) {
                $sent++;
            } else {
                $failed++;
                $responseData = $response->json();
                if (($responseData['error']['status'] ?? '') === 'NOT_FOUND') {
                    \App\Models\DeviceToken::where('token', $token)->update(['is_active' => false]);
                } else {
                    Log::warning('FCM send failed', ['token' => substr($token, 0, 20), 'error' => $responseData]);
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function getAccessToken(string $serviceAccountPath): ?string
    {
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
        if (!$serviceAccount) return null;

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
