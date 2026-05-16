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

    // Type weights for priority scoring
    private const TYPE_WEIGHTS = [
        'accident' => 10,
        'traffic'  => 5,
        'hazard'   => 3,
        'security' => 4,
    ];

    // Upvote score threshold to override the 5-hour cooldown
    private const COOLDOWN_OVERRIDE_THRESHOLD = 15;

    // Minimum hours between notifications per user/device
    private const COOLDOWN_HOURS = 5;

    public function handle(FcmService $fcmService)
    {
        $this->info("Starting automated incident notifications check...");

        $now = Carbon::now();

        // Get incidents created in the last 2 hours
        $incidents = Incident::where('reported_at', '>=', $now->copy()->subHours(2))->get();

        if ($incidents->isEmpty()) {
            $this->info("No active incidents found.");
            return;
        }

        // Get recent location pings (last 1 hour), deduplicated by device
        $recentPings = LocationPing::where('recorded_at', '>=', $now->copy()->subHour())
            ->orderBy('recorded_at', 'desc')
            ->get()
            ->unique('device_id');

        // Get users with saved notification_locations
        $usersWithLocations = User::whereNotNull('notification_locations')->get();

        // ── Build a map: identifier → best candidate ───────────────────────────
        // Key: 'device:{device_id}' or 'user:{user_id}'
        // Value: ['incident' => Incident, 'score' => int, 'device_id' => ?string, 'user_id' => ?int]
        $bestForCandidate = [];

        foreach ($incidents as $incident) {
            $score = $this->scoreIncident($incident);

            // A. Check live location pings
            foreach ($recentPings as $ping) {
                if ($this->calculateDistance($incident->lat, $incident->lng, $ping->lat, $ping->lng) > 2) {
                    continue;
                }

                $key = "device:{$ping->device_id}";
                if (!isset($bestForCandidate[$key]) || $score > $bestForCandidate[$key]['score']) {
                    $bestForCandidate[$key] = [
                        'incident'  => $incident,
                        'score'     => $score,
                        'device_id' => $ping->device_id,
                        'user_id'   => null,
                    ];
                }
            }

            // B. Check saved user locations
            foreach ($usersWithLocations as $user) {
                $locations = is_string($user->notification_locations)
                    ? json_decode($user->notification_locations, true)
                    : $user->notification_locations;

                if (!is_array($locations)) continue;

                foreach ($locations as $loc) {
                    if (!isset($loc['lat'], $loc['lng'])) continue;

                    if ($this->calculateDistance($incident->lat, $incident->lng, $loc['lat'], $loc['lng']) <= 2) {
                        $key = "user:{$user->id}";
                        if (!isset($bestForCandidate[$key]) || $score > $bestForCandidate[$key]['score']) {
                            $bestForCandidate[$key] = [
                                'incident'  => $incident,
                                'score'     => $score,
                                'device_id' => null,
                                'user_id'   => $user->id,
                            ];
                        }
                        break; // Only need one matching location per user
                    }
                }
            }
        }

        if (empty($bestForCandidate)) {
            $this->info("No candidates to notify.");
            return;
        }

        // ── For each candidate, check cooldown & daily limit, then send ────────
        $sentCount = 0;

        foreach ($bestForCandidate as $candidate) {
            $incident  = $candidate['incident'];
            $userId    = $candidate['user_id'];
            $deviceId  = $candidate['device_id'];
            $score     = $candidate['score'];
            $highPriority = ($score >= self::COOLDOWN_OVERRIDE_THRESHOLD);

            // Daily limit check (user-based only)
            if ($userId && $this->hasExceededDailyLimit($userId)) {
                continue;
            }

            // Cooldown check — skipped if incident is high-priority (viral upvotes)
            if (!$highPriority && $this->isInCooldown($userId, $deviceId)) {
                continue;
            }

            // Already notified about this specific incident?
            if ($this->hasBeenNotifiedAboutIncident($incident->id, $userId, $deviceId)) {
                continue;
            }

            // Resolve tokens to send to
            $tokens = [];
            if ($deviceId) {
                $deviceToken = DeviceToken::where('device_id', $deviceId)
                    ->where('is_active', true)
                    ->first();
                if ($deviceToken) {
                    $tokens[] = ['token' => $deviceToken->token, 'user_id' => $deviceToken->user_id, 'device_id' => $deviceId];
                }
            } elseif ($userId) {
                $userTokens = DeviceToken::where('user_id', $userId)->where('is_active', true)->get();
                foreach ($userTokens as $t) {
                    $tokens[] = ['token' => $t->token, 'user_id' => $userId, 'device_id' => $t->device_id];
                }
            }

            if (empty($tokens)) continue;

            // Build notification content
            $type  = $incident->incident_sub_type ?: $incident->type;
            $title = "Alert: " . ucfirst($type) . " reported nearby";
            $body  = "An incident has been reported near you or your saved locations. Tap to view details.";
            $frontendUrl = rtrim(env('FRONTEND_URL', 'https://nishukishe.com'), '/');
            $link  = "{$frontendUrl}/?incident_id={$incident->id}&showOccurrences=true";

            $fcmService->sendNotification(
                array_column($tokens, 'token'),
                $title,
                $body,
                $link
            );

            // Log each notification sent
            foreach ($tokens as $t) {
                IncidentNotification::create([
                    'incident_id' => $incident->id,
                    'user_id'     => $t['user_id'],
                    'device_id'   => $t['device_id'],
                    'created_at'  => now(),
                ]);
                $sentCount++;
            }
        }

        $this->info("Automated incident notifications complete. Sent: {$sentCount}.");
    }

    /**
     * Score an incident for priority sorting.
     * Higher score = more important = should be sent first.
     */
    private function scoreIncident(Incident $incident): int
    {
        $typeWeight = self::TYPE_WEIGHTS[strtolower($incident->type ?? '')] ?? 0;
        $netVotes   = ($incident->upvotes ?? 0) - ($incident->downvotes ?? 0);
        return $typeWeight + max(0, $netVotes);
    }

    /**
     * Check whether this user/device is in the 5-hour global notification cooldown.
     * High-priority incidents bypass this check.
     */
    private function isInCooldown(?int $userId, ?string $deviceId): bool
    {
        $cutoff = Carbon::now()->subHours(self::COOLDOWN_HOURS);
        $query  = IncidentNotification::where('created_at', '>=', $cutoff);

        if ($userId) {
            return $query->where('user_id', $userId)->exists();
        } elseif ($deviceId) {
            return $query->where('device_id', $deviceId)->exists();
        }

        return false;
    }

    /**
     * Check if the user/device has already been notified about this specific incident.
     */
    private function hasBeenNotifiedAboutIncident(int $incidentId, ?int $userId, ?string $deviceId): bool
    {
        $query = IncidentNotification::where('incident_id', $incidentId);

        if ($userId) {
            return $query->where('user_id', $userId)->exists();
        } elseif ($deviceId) {
            return $query->where('device_id', $deviceId)->exists();
        }

        return false;
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * asin(sqrt($a));
    }

    private function hasExceededDailyLimit(int $userId): bool
    {
        $user = User::find($userId);
        if (!$user) return false;

        $max   = $user->max_notifications_per_day ?? 3;
        $count = IncidentNotification::where('user_id', $userId)
            ->whereDate('created_at', Carbon::today())
            ->count();

        return $count >= $max;
    }
}
