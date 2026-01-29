<?php

namespace App\Support;

use App\Models\Bookable;

trait TembeaTourPresenter
{
    protected function summarizeTour(Bookable $bookable): array
    {
        $media = collect($bookable->media ?? [])
            ->map(fn($attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->type,
                'url' => $attachment->url,
                'title' => $attachment->title,
                'alt_text' => $attachment->alt_text,
                'position' => $attachment->position,
            ])
            ->values()
            ->all();

        return [
            'id' => $bookable->id,
            'title' => $bookable->title,
            'slug' => $bookable->slug,
            'subtitle' => $bookable->subtitle,
            'starts_at' => optional($bookable->starts_at)->toIso8601String(),
            'currency' => $bookable->currency,
            'price_from' => $bookable->ticket_tiers_min_price ? (float) $bookable->ticket_tiers_min_price : null,
            'bookings_count' => $bookable->bookings_count ?? 0,
            'operator' => $this->summarizeOperator($bookable),
            'tour_event' => [
                'id' => $bookable->tourEvent?->id,
                'destination' => $bookable->tourEvent?->destination,
                'meeting_point' => $bookable->tourEvent?->meeting_point,
                'duration_label' => $bookable->tourEvent?->duration_label,
                'checkout_type' => $bookable->tourEvent?->checkout_type,
                'contact_info' => $bookable->tourEvent?->contact_info,
            ],
            'media' => $media,
        ];
    }

    protected function summarizeOperator(Bookable $bookable): ?array
    {
        $profile = optional($bookable->organizer)->tembeaOperatorProfile;

        if (!$profile || $profile->status !== 'approved') {
            return null;
        }

        return [
            'id' => $profile->id,
            'company_name' => $profile->company_name,
            'slug' => $profile->slug,
            'public_email' => $profile->public_email,
            'public_phone' => $profile->public_phone,
        ];
    }

    protected function presentTourEventDetail(Bookable $bookable): array
    {
        $summary = $this->summarizeTour($bookable);

        // Add detail-specific fields
        $summary['description'] = $bookable->description;
        $summary['metadata'] = $bookable->metadata;
        $summary['ends_at'] = optional($bookable->ends_at)->toIso8601String();

        // Ensure ticket tiers are included
        $summary['ticket_tiers'] = collect($bookable->ticketTiers ?? [])
            ->map(fn($tier) => [
                'id' => $tier->id,
                'name' => $tier->name,
                'description' => $tier->description,
                'price' => (float) $tier->price,
                'currency' => $tier->currency,
                'remaining_quantity' => $tier->remaining_quantity,
                'total_quantity' => $tier->total_quantity,
            ])
            ->values()
            ->all();

        // Add extra tour event details that might not be in the summary
        if ($bookable->tourEvent) {
            $summary['tour_event'] = array_merge($summary['tour_event'], [
                'marketing_copy' => $bookable->tourEvent->marketing_copy,
                'highlights' => $bookable->tourEvent->highlights,
                'stops' => $bookable->tourEvent->stops,
                'path_geojson' => $bookable->tourEvent->path_geojson,
            ]);
        }

        return $summary;
    }
}
