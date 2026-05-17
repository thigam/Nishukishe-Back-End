<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NotificationCampaign;
use App\Models\DeviceToken;
use App\Services\FcmService;
use Carbon\Carbon;

class SendScheduledNotifications extends Command
{
    protected $signature = 'app:send-scheduled-notifications';
    protected $description = 'Checks for scheduled notification campaigns and sends them.';

    public function handle(FcmService $fcmService)
    {
        $campaigns = NotificationCampaign::where('status', 'scheduled')
            ->where('scheduled_for', '<=', Carbon::now())
            ->get();

        foreach ($campaigns as $campaign) {
            $query = DeviceToken::where('is_active', true);

            if ($campaign->target_group !== 'all') {
                $query->whereHas('user', function ($q) use ($campaign) {
                    $roleMapping = [
                        'commuters'      => 'commuter',
                        'drivers'        => 'driver',
                        'sacco_admins'   => 'sacco_admin',
                        'vehicle_owners' => 'vehicle_owner',
                    ];
                    $q->where('role', $roleMapping[$campaign->target_group] ?? $campaign->target_group);
                });
            }

            $tokens = $query->pluck('token')->toArray();
            $recipientsCount = count($tokens);

            if ($recipientsCount > 0) {
                $fcmService->sendNotification(
                    $tokens,
                    $campaign->title,
                    $campaign->message,
                    $campaign->link ?: '/',
                    $campaign->id
                );

                $campaign->update([
                    'status' => 'sent',
                    'recipients_count' => $recipientsCount
                ]);
            } else {
                $campaign->update(['status' => 'failed', 'recipients_count' => 0]);
            }
            
            $this->info("Processed scheduled campaign ID: {$campaign->id}");
        }
    }
}

