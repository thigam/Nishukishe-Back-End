<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SaccoRoutePublishLogger
{
    public function log(string $saccoRouteId, ?User $user = null, ?Carbon $publishedAt = null): void
    {
        $saccoRouteId = trim($saccoRouteId);

        if ($saccoRouteId === '') {
            return;
        }

        $payload = [
            'sacco_route_id' => $saccoRouteId,
            'created_by_role' => $user?->role ?? 'guest',
            'created_by' => $user?->email ?? null,
            'published_at' => ($publishedAt ?? Carbon::now())->toIso8601String(),
        ];

        try {
            file_put_contents(
                storage_path('logs/saccoroute_publish.log'),
                json_encode($payload) . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write sacco route publish log', [
                'sacco_route_id' => $saccoRouteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
