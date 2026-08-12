<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\NotificationCampaign;
use App\Services\FcmService;
use App\Jobs\SendNotificationCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationCampaignController extends Controller
{
    /**
     * Get all campaigns.
     */
    public function index()
    {
        $campaigns = NotificationCampaign::with('creator:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    /**
     * Create and send a new campaign.
     */
    public function store(Request $request, FcmService $fcmService)
    {
        // Verify FCM config is valid before attempting anything else
        $serviceAccountPath = config('services.fcm.service_account_path');
        if (!$serviceAccountPath || !file_exists($serviceAccountPath)) {
            return response()->json([
                'message' => 'FCM Configuration Error: Service account JSON not found on server.'
            ], 400);
        }

        $json = json_decode(@file_get_contents($serviceAccountPath), true);
        if (!$json || !isset($json['private_key'])) {
            return response()->json([
                'message' => 'FCM Configuration Error: Service account JSON is invalid or missing private_key.'
            ], 400);
        }

        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'message'      => 'required|string',
            'link'         => 'nullable|string',
            'target_group' => 'required|in:all,commuters,drivers,sacco_admins,vehicle_owners',
            'scheduled_for'=> 'nullable|date',
        ]);

        $query = DeviceToken::where('is_active', true);

        if ($validated['target_group'] !== 'all') {
            $query->whereHas('user', function ($q) use ($validated) {
                // Mapping target groups to actual roles
                $roleMapping = [
                    'commuters'      => 'commuter',
                    'drivers'        => 'driver',
                    'sacco_admins'   => 'sacco_admin',
                    'vehicle_owners' => 'vehicle_owner', // Assuming this exists or similar
                ];
                $q->where('role', $roleMapping[$validated['target_group']] ?? $validated['target_group']);
            });
        }

        $tokens = $query->pluck('token')->toArray();
        $recipientsCount = count($tokens);
        
        Log::info("Notification Campaign: Sending to {$recipientsCount} tokens.", [
            'target_group' => $validated['target_group'],
            'token_types' => DeviceToken::whereIn('token', $tokens)->select('token_type', DB::raw('count(*) as count'))->groupBy('token_type')->get()->toArray()
        ]);

        $isScheduled = !empty($validated['scheduled_for']) && strtotime($validated['scheduled_for']) > time();

        $campaign = NotificationCampaign::create([
            'title'            => $validated['title'],
            'message'          => $validated['message'],
            'link'             => $validated['link'] ?: '/',
            'target_group'     => $validated['target_group'],
            'status'           => $isScheduled ? 'scheduled' : 'pending',
            'recipients_count' => $recipientsCount,
            'created_by'       => $request->user()->id,
            'scheduled_for'    => $isScheduled ? $validated['scheduled_for'] : null,
        ]);

        if ($isScheduled) {
            return response()->json($campaign, 201);
        }

        if ($recipientsCount > 0) {
            SendNotificationCampaign::dispatch($campaign, $tokens);
        } else {
            $campaign->update(['status' => 'failed']);
        }

        return response()->json($campaign, 201);
    }

    /**
     * Track a click from a push notification.
     */
    public function trackClick($id)
    {
        $campaign = NotificationCampaign::findOrFail($id);
        $campaign->increment('clicks_count');

        return response()->json(['success' => true]);
    }
}
