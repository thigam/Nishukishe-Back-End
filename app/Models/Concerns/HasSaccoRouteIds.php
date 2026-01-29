<?php

namespace App\Models\Concerns;

trait HasSaccoRouteIds
{
    /**
     * Normalize an array of sacco route identifiers by trimming, casting to strings
     * and removing duplicates while preserving the original order.
     */
    protected function normalizeSaccoRouteIds(array $routeIds): array
    {
        $normalized = [];

        foreach ($routeIds as $routeId) {
            if ($routeId === null) {
                continue;
            }

            $candidate = is_string($routeId)
                ? trim($routeId)
                : trim((string) $routeId);

            if ($candidate === '') {
                continue;
            }

            if (! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * Replace the current sacco_route_ids with the provided set.
     */
    public function syncSaccoRouteIds(array $routeIds, bool $save = true): bool
    {
        $normalized = $this->normalizeSaccoRouteIds($routeIds);
        $previous   = $this->sacco_route_ids ?? [];

        $this->sacco_route_ids = $normalized;

        if (! $this->isDirty('sacco_route_ids')) {
            return false;
        }

        if ($save) {
            $this->save();
        }

        return $previous !== $normalized;
    }

    /**
     * Append a sacco route identifier to the stop if it is not already linked.
     */
    public function attachSaccoRouteId(string $routeId, bool $save = true): bool
    {
        $current = $this->sacco_route_ids ?? [];
        $current[] = $routeId;

        return $this->syncSaccoRouteIds($current, $save);
    }

    /**
     * Remove a sacco route identifier from the stop if present.
     */
    public function detachSaccoRouteId(string $routeId, bool $save = true): bool
    {
        $current = $this->sacco_route_ids ?? [];
        $filtered = array_values(array_filter($current, fn ($existing) => $existing !== $routeId));

        return $this->syncSaccoRouteIds($filtered, $save);
    }

    public function scopeForRoute($query, string $routeId)
    {
        return $query->whereJsonContains('sacco_route_ids', $routeId);
    }
}
