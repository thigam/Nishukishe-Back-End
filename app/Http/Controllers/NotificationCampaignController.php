<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\NotificationCampaign;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'message'      => 'required|string',
            'link'         => 'nullable|string',
            'target_group' => 'required|in:all,commuters,drivers,sacco_admins,vehicle_owners',
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

        $campaign = NotificationCampaign::create([
            'title'            => $validated['title'],
            'message'          => $validated['message'],
            'link'             => $validated['link'] ?: '/',
            'target_group'     => $validated['target_group'],
            'status'           => 'pending',
            'recipients_count' => $recipientsCount,
            'created_by'       => $request->user()->id,
        ]);

        if ($recipientsCount > 0) {
            $result = $fcmService->sendNotification(
                $tokens,
                $validated['title'],
                $validated['message'],
                $validated['link'] ?: '/'
            );
            
            $campaign->update([
                'status' => 'sent',
                // Keep the recipient count as attempted, or successful sends depending on preference.
                // We'll keep intended recipient count.
            ]);
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
