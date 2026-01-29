<?php

namespace Tests\Feature;

use App\Models\Bookable;
use App\Models\MediaAttachment;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBookableControllerTest extends TestCase
{
    // use RefreshDatabase; // Inherited from TestCase

    public function test_show_tour_event_returns_correct_structure_with_media()
    {
        // 1. Setup Data
        $organizer = User::factory()->create();
        $profile = TembeaOperatorProfile::create([
            'user_id' => $organizer->id,
            'company_name' => 'Test Operator',
            'contact_name' => 'Test Contact',
            'contact_email' => 'contact@example.com',
            'contact_phone' => '0700000000',
            'slug' => 'test-operator',
            'status' => 'approved',
            'public_email' => 'test@example.com',
            'public_phone' => '1234567890',
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $organizer->id,
            'type' => 'tour_event',
            'title' => 'Amazing Safari',
            'slug' => 'amazing-safari',
            'status' => 'published',
            'published_at' => now(),
            'starts_at' => now()->addDays(5),
            'ticket_tiers' => [], // Simplified for this test
        ]);

        TourEvent::create([
            'bookable_id' => $bookable->id,
            'destination' => 'Maasai Mara',
            'meeting_point' => 'Nairobi CBD',
        ]);

        MediaAttachment::create([
            'bookable_id' => $bookable->id,
            'type' => 'image',
            'url' => 'https://example.com/safari.jpg',
            'title' => 'Safari View',
            'position' => 0,
        ]);

        // 2. Call API
        $response = $this->getJson(route('bookings.tours.show', ['slug' => 'amazing-safari']));

        // 3. Assertions
        $response->assertOk();

        $response->assertJsonStructure([
            'id',
            'title',
            'slug',
            'media' => [
                '*' => ['id', 'url', 'type', 'title']
            ],
            'tour_event' => [
                'destination',
                'meeting_point',
            ],
            'operator' => [
                'company_name',
            ]
        ]);

        $response->assertJsonFragment([
            'title' => 'Amazing Safari',
            'url' => 'https://example.com/safari.jpg',
            'destination' => 'Maasai Mara',
            'company_name' => 'Test Operator',
        ]);
    }
}
