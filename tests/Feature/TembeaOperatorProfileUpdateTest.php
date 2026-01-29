<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\Bookable;
use App\Models\TembeaOperatorProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TembeaOperatorProfileUpdateTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_tembea_operator_can_update_profile_and_sync_mpesa_accounts(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = User::create([
            'name' => 'Tembea Operator',
            'email' => 'operator@example.com',
            'phone' => '254700000100',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $profile = TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Original Co',
            'contact_name' => 'Grace',
            'contact_email' => 'grace@example.com',
            'contact_phone' => '254700000200',
            'status' => 'approved',
            'metadata' => ['about' => 'Existing about'],
        ]);

        $originalSlug = $profile->slug;

        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'type' => 'tour_event',
            'title' => 'Weekend Safari',
            'status' => 'draft',
            'currency' => 'KES',
        ]);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->putJson('/api/tembea/operator-profile', [
            'company_name' => 'Updated Co',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@example.com',
            'contact_phone' => '254700000300',
            'public_email' => 'hello@example.com',
            'public_phone' => '+254 700 000 300',
            'about' => 'We run magical getaways.',
            'website' => 'https://tembea.example.com',
            'headquarters' => 'Nairobi',
            'mpesa_accounts' => [
                ['label' => 'Primary Till', 'number' => '123 456'],
                ['label' => 'Backup Till', 'number' => ''],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('company_name', 'Updated Co');
        $response->assertJsonPath('slug', $originalSlug);
        $response->assertJsonPath('metadata.about', 'We run magical getaways.');
        $response->assertJsonPath('metadata.website', 'https://tembea.example.com');
        $response->assertJsonPath('metadata.headquarters', 'Nairobi');
        $response->assertJsonPath('metadata.mpesa_accounts.0.label', 'Primary Till');
        $response->assertJsonPath('metadata.mpesa_accounts.0.number', '123456');
        $response->assertJsonCount(1, 'metadata.mpesa_accounts');

        $profile->refresh();
        $this->assertSame('Updated Co', $profile->company_name);
        $this->assertSame($originalSlug, $profile->slug);
        $this->assertSame('Jane Doe', $profile->contact_name);
        $this->assertSame('jane@example.com', $profile->contact_email);
        $this->assertSame('254700000300', $profile->contact_phone);
        $this->assertSame('hello@example.com', $profile->public_email);
        $this->assertSame('We run magical getaways.', $profile->metadata['about']);
        $this->assertSame('https://tembea.example.com', $profile->metadata['website']);
        $this->assertSame('Nairobi', $profile->metadata['headquarters']);
        $this->assertSame('123456', $profile->metadata['mpesa_accounts'][0]['number']);

        $bookable->refresh();
        $payoutProfile = $bookable->primaryPayoutProfile;
        $this->assertNotNull($payoutProfile);
        $this->assertSame('mpesa', $payoutProfile->payout_type);
        $this->assertSame('123456', $payoutProfile->phone_number);
        $this->assertSame('Primary Till', $payoutProfile->metadata['tembea_account_label']);
    }

    public function test_non_tembea_operator_cannot_update_profile(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'phone' => '254711111111',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/tembea/operator-profile', [
            'company_name' => 'Should Fail',
        ]);

        $response->assertStatus(403);
    }
}

