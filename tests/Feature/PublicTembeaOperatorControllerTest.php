<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\Bookable;
use App\Models\TembeaOperatorProfile;
use App\Models\TicketTier;
use App\Models\TourEvent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicTembeaOperatorControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_view_public_operator_profile(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = User::create([
            'name' => 'Tembea Operator',
            'email' => 'operator@example.com',
            'phone' => '254700000400',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
        ]);

        $profile = TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Safari Horizons',
            'contact_name' => 'Joan',
            'contact_email' => 'joan@example.com',
            'contact_phone' => '254700000401',
            'public_email' => 'hello@safari.test',
            'public_phone' => '+254 700 000 401',
            'status' => 'approved',
            'metadata' => [
                'about' => 'We craft intimate Kenyan adventures.',
                'website' => 'https://safari.test',
                'headquarters' => 'Nairobi',
                'mpesa_accounts' => [['label' => 'Internal', 'number' => '999999']],
            ],
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'type' => 'tour_event',
            'title' => 'Sunset at Mara',
            'status' => 'published',
            'currency' => 'KES',
            'starts_at' => now()->addWeeks(2),
        ]);

        TourEvent::create([
            'bookable_id' => $bookable->id,
            'destination' => 'Maasai Mara',
            'duration_label' => '3 days',
        ]);

        TicketTier::create([
            'bookable_id' => $bookable->id,
            'name' => 'Explorer',
            'currency' => 'KES',
            'price' => 25000,
            'total_quantity' => 10,
            'remaining_quantity' => 8,
        ]);

        $response = $this->getJson('/api/public/tembea/operators/' . $profile->slug);

        $response->assertOk();
        $response->assertJsonPath('company_name', 'Safari Horizons');
        $response->assertJsonPath('slug', $profile->slug);
        $response->assertJsonPath('metadata.about', 'We craft intimate Kenyan adventures.');
        $response->assertJsonPath('metadata.website', 'https://safari.test');
        $response->assertJsonPath('metadata.headquarters', 'Nairobi');
        $response->assertJsonMissingPath('metadata.mpesa_accounts');
        $response->assertJsonPath('tours.0.slug', $bookable->slug);
        $response->assertJsonPath('tours.0.operator.company_name', 'Safari Horizons');
    }

    public function test_returns_not_found_for_pending_operator(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = User::create([
            'name' => 'Pending Operator',
            'email' => 'pending@example.com',
            'phone' => '254700000500',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
        ]);

        $profile = TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Pending Co',
            'contact_name' => 'Lena',
            'contact_email' => 'lena@example.com',
            'contact_phone' => '254700000501',
            'status' => 'pending',
        ]);

        $this->getJson('/api/public/tembea/operators/' . $profile->slug)->assertNotFound();
    }
}
