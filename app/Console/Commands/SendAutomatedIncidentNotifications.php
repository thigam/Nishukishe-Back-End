<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Incident;
use App\Models\LocationPing;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\IncidentNotification;
use App\Services\FcmService;
use Carbon\Carbon;

class SendAutomatedIncidentNotifications extends Command
{
    protected $signature = 'app:send-incident-notifications';
    protected $description = 'Cross checks reported incidents with recent user locations and saved locations to send automated notifications.';

    public function handle(FcmService $fcmService)
    {
        $this->info("Starting automated incident notifications check...");

        // Get incidents created in the last 2 hours (whether active or scheduled for the future)
        $now = Carbon::now();
        $incidents = Incident::where('reported_at', '>=', $now->copy()->subHours(2))->get();

        if ($incidents->isEmpty()) {
            $this->info("No active incidents found.");
            return;
        }

        // 1. Get recent location pings (last 1 hour)
        // Group by device_id to get the latest ping
        $recentPings = LocationPing::where('recorded_at', '>=', $now->copy()->subHour())
            ->orderBy('recorded_at', 'desc')
            ->get()
            ->unique('device_id');

        // 2. Get users with notification_locations configured
        $usersWithLocations = User::whereNotNull('notification_locations')->get();

        foreach ($incidents as $incident) {
            $tokensToSend = [];
            $notificationsToLog = [];

            // A. Check against live pings
            foreach ($recentPings as $ping) {
                if ($this->calculateDistance($incident->lat, $incident->lng, $ping->lat, $ping->lng) <= 2) {
                    // Check if already notified for this incident
                    if ($this->hasBeenNotified($incident->id, null, $ping->device_id)) {
                        continue;
                    }

                    // Get token for device
                    $deviceToken = DeviceToken::where('device_id', $ping->device_id)
                        ->where('is_active', true)
                        ->first();

                    if (!$deviceToken) continue;

                    // Check daily limit if associated with a user
                    if ($deviceToken->user_id) {
                        if ($this->hasExceededDailyLimit($deviceToken->user_id)) {
                            continue;
                        }
                    }

                    $tokensToSend[$deviceToken->token] = [
                        'user_id' => $deviceToken->user_id,
                        'device_id' => $deviceToken->device_id
                    ];
                }
            }

            // B. Check against users' saved locations
            foreach ($usersWithLocations as $user) {
                $locations = is_string($user->notification_locations) ? json_decode($user->notification_locations, true) : $user->notification_locations;
                if (!is_array($locations)) continue;

                $isWithinRange = false;
                foreach ($locations as $loc) {
                    if (isset($loc['lat']) && isset($loc['lng'])) {
                        if ($this->calculateDistance($incident->lat, $incident->lng, $loc['lat'], $loc['lng']) <= 2) {
                            $isWithinRange = true;
                            break;
                        }
                    }
                }

                if ($isWithinRange) {
                    if ($this->hasExceededDailyLimit($user->id)) continue;
                    if ($this->hasBeenNotified($incident->id, $user->id, null)) continue;

                    // Get user's active tokens
                    $userTokens = DeviceToken::where('user_id', $user->id)->where('is_active', true)->get();
                    foreach ($userTokens as $tokenRec) {
                        $tokensToSend[$tokenRec->token] = [
                            'user_id' => $user->id,
                            'device_id' => $tokenRec->device_id
                        ];
                    }
                }
            }

            // Send Notifications
            if (!empty($tokensToSend)) {
                $title = "Alert: " . ucfirst($incident->type) . " reported nearby";
                if ($incident->incident_sub_type) {
                    $title = "Alert: " . ucfirst($incident->incident_sub_type) . " nearby";
                }
                $body = "An incident has been reported near you or your saved locations. Tap to verify or see details.";
                $link = "/?incident_id=" . $incident->id . "&showOccurrences=true";

                $fcmService->sendNotification(
                    array_keys($tokensToSend),
                    $title,
                    $body,
                    $link
                );

                // Log notifications
                foreach ($tokensToSend as $token => $data) {
                    IncidentNotification::create([
                        'incident_id' => $incident->id,
                        'user_id' => $data['user_id'],
                        'device_id' => $data['device_id'],
                    ]);
                }

                $this->info("Sent " . count($tokensToSend) . " notifications for incident ID: " . $incident->id);
            }
        }

        $this->info("Automated incident notifications check complete.");
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * asin(sqrt($a));
        return $earthRadius * $c;
    }

    private function hasBeenNotified($incidentId, $userId, $deviceId)
    {
        $query = IncidentNotification::where('incident_id', $incidentId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        return $query->exists();
    }

    private function hasExceededDailyLimit($userId)
    {
        $user = User::find($userId);
        if (!$user) return false;

        $max = $user->max_notifications_per_day ?? 3;
        $count = IncidentNotification::where('user_id', $userId)
            ->whereDate('created_at', Carbon::today())
            ->count();

        return $count >= $max;
    }
}

