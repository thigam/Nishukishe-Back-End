<?php

namespace App\Http\Controllers;

use App\Models\Bookable;
use App\Models\TembeaOperatorProfile;
use App\Support\TembeaTourPresenter;
use Illuminate\Http\JsonResponse;

class PublicTembeaOperatorController extends Controller
{
    use TembeaTourPresenter;

    public function show(string $slug): JsonResponse
    {
        $profile = TembeaOperatorProfile::query()
            ->where('slug', $slug)
            ->where('status', 'approved')
            ->firstOrFail();

        $metadata = $this->formatPublicMetadata($profile->metadata ?? []);

        $tours = Bookable::query()
            ->where('organizer_id', $profile->user_id)
            ->where('type', 'tour_event')
            ->where('status', 'published')
            ->with(['tourEvent', 'media', 'organizer.tembeaOperatorProfile'])
            ->withMin('ticketTiers', 'price')
            ->withCount('bookings')
            ->orderByDesc('is_featured')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Bookable $bookable) => $this->summarizeTour($bookable))
            ->values()
            ->all();

        return response()->json([
            'id' => $profile->id,
            'slug' => $profile->slug,
            'company_name' => $profile->company_name,
            'public_email' => $profile->public_email,
            'public_phone' => $profile->public_phone,
            'metadata' => $metadata,
            'tours' => $tours,
        ]);
    }

    /**
     * @param array<string, mixed>|object $metadata
     * @return array<string, mixed>|\stdClass
     */
    protected function formatPublicMetadata($metadata)
    {
        $collection = collect((array) $metadata)
            ->only(['about', 'website', 'headquarters'])
            ->map(function ($value) {
                if ($value === null) {
                    return null;
                }

                if (is_string($value)) {
                    $trimmed = trim($value);

                    return $trimmed === '' ? null : $trimmed;
                }

                return $value;
            })
            ->filter(function ($value) {
                if (is_string($value)) {
                    return $value !== '';
                }

                return $value !== null;
            });

        if ($collection->isEmpty()) {
            return new \stdClass();
        }

        return $collection->all();
    }
}
