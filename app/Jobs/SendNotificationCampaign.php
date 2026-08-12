<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes to allow large loops to process safely

    public $campaign;
    public $tokens;

    /**
     * Create a new job instance.
     */
    public function __construct(NotificationCampaign $campaign, array $tokens)
    {
        $this->campaign = $campaign;
        $this->tokens = $tokens;
    }

    /**
     * Execute the job.
     */
    public function handle(FcmService $fcmService): void
    {
        Log::info("SendNotificationCampaign job started for Campaign ID: {$this->campaign->id}");

        try {
            $this->campaign->update(['status' => 'sending']);

            $result = $fcmService->sendNotification(
                $this->tokens,
                $this->campaign->title,
                $this->campaign->message,
                $this->campaign->link ?: '/',
                $this->campaign->id
            );

            $this->campaign->update([
                'status' => 'sent',
            ]);

            Log::info("SendNotificationCampaign job finished successfully. Sent: {$result['sent']}, Failed: {$result['failed']}");
        } catch (\Throwable $e) {
            $this->campaign->update(['status' => 'failed']);
            Log::error("SendNotificationCampaign job failed for Campaign ID: {$this->campaign->id}. Error: " . $e->getMessage());
            throw $e;
        }
    }
}
