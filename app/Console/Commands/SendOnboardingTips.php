<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\OnboardingNotification;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendOnboardingTips extends Command
{
    protected $signature = 'notifications:send-onboarding-tips {--dry-run}';
    protected $description = 'Send automated, time-delayed onboarding/usage tips to active device tokens.';

    private array $tips = [
        'tip_1' => [
            'title' => '🔔 Customize Your Alerts',
            'body'  => 'Did you know you can receive customized traffic, accident, or road closure alerts for locations or roads of your choice? Set them up in your Profile.',
            'link'  => '/profile',
        ],
        'tip_2' => [
            'title' => '📢 Share Road Incidents',
            'body'  => 'Seen traffic, an accident, or a road closure? Tap the red megaphone icon on the home screen to report it and alert the community.',
            'link'  => '/',
        ],
        'tip_3' => [
            'title' => '💡 Keep the Community Safe',
            'body'  => 'Help other commuters! Report any delays, hazards, or closures you see on your route using the report megaphone.',
            'link'  => '/',
        ],
    ];

    public function handle(FcmService $fcmService): int
    {
        $this->info("Checking device tokens for onboarding/usage tips...");
        $isDry = $this->option('dry-run');

        // Fetch all active device tokens
        $deviceTokens = DeviceToken::where('is_active', true)->get();

        if ($deviceTokens->isEmpty()) {
            $this->info("No active device tokens found.");
            return self::SUCCESS;
        }

        $sentCount = 0;
        $now = Carbon::now();
        
        // Nairobi timezone check for Tips 2 & 3
        $nairobiHour = Carbon::now('Africa/Nairobi')->hour;
        $isWithinAllowedHours = ($nairobiHour >= 9 && $nairobiHour < 17);

        foreach ($deviceTokens as $deviceToken) {
            $created = Carbon::parse($deviceToken->created_at);
            
            $minutesDiff = $created->diffInMinutes($now);
            $hoursDiff   = $created->diffInHours($now);
            $daysDiff    = $created->diffInDays($now);
            
            $sentTips = $deviceToken->sent_onboarding_tips ?? [];

            $selectedTipKey = null;

            // Enforce strict chronological progression
            if (!in_array('tip_1', $sentTips)) {
                if ($minutesDiff >= 30) {
                    $selectedTipKey = 'tip_1';
                }
            } elseif (!in_array('tip_2', $sentTips)) {
                if ($hoursDiff >= 5 && $isWithinAllowedHours) {
                    $selectedTipKey = 'tip_2';
                }
            } elseif (!in_array('tip_3', $sentTips)) {
                if ($daysDiff >= 3 && $isWithinAllowedHours) {
                    $selectedTipKey = 'tip_3';
                }
            }

            if (!$selectedTipKey) {
                continue;
            }

            $tip = $this->tips[$selectedTipKey];
            $this->info("Sending {$selectedTipKey} to Token ID: {$deviceToken->id} (Created {$minutesDiff} minutes ago)");

            if ($isDry) {
                $sentCount++;
                continue;
            }

            // Create tracking record
            $onboardingNotification = OnboardingNotification::create([
                'device_token_id' => $deviceToken->id,
                'tip_key'         => $selectedTipKey,
                'created_at'      => now(),
            ]);

            $response = $fcmService->sendNotification(
                [$deviceToken->token],
                $tip['title'],
                $tip['body'],
                $tip['link'],
                null,
                null,
                [$onboardingNotification->id]
            );

            if ($response['sent'] > 0) {
                $sentCount++;
                $sentTips[] = $selectedTipKey;
                $deviceToken->update(['sent_onboarding_tips' => $sentTips]);
            } else {
                // If FCM returned error of invalid token, deactivate it
                if (isset($response['error']) && $response['error'] === 'config_error') {
                    $this->error('FCM Service Configuration Error.');
                    return self::FAILURE;
                }
                
                // If it failed because the token was not found (unregistered), active = false is already handled by FcmService
                // but let's delete our temporary tracking record to avoid skewing analytics
                $onboardingNotification->delete();
                $this->warn("Failed to send onboarding tip to Token ID: {$deviceToken->id}");
            }
        }

        $this->info("Processed complete. Sent: {$sentCount} onboarding tips.");
        return self::SUCCESS;
    }
}
