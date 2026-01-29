<?php

namespace Tests\Feature;

use App\Models\Bookable;
use App\Models\Booking;
use App\Models\PayoutProfile;
use App\Models\Settlement;
use App\Models\TembeaOperatorProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\MpesaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SuperAdminTembeaPayoutsTest extends TestCase
{
    // use \Illuminate\Foundation\Testing\RefreshDatabase; // Inherited from TestCase

    protected function setUp(): void
    {
        parent::setUp();
        // Force M-Pesa path for these tests
        config(['app.payment_provider' => 'mpesa']); // If config is used
        // Or env override if config is not used directly (but env() is used in controller)
        // Laravel tests usually respect putenv or $_ENV for env() calls if config is not cached.
        // But better to use Config facade if the app uses config().
        // The controller uses env() directly: env('PAYMENT_PROVIDER', 'jenga')
        // This is bad practice, but we must deal with it.
        // We can use Config::set if we change the controller to use config, but for now:
        putenv('PAYMENT_PROVIDER=mpesa');
        $_ENV['PAYMENT_PROVIDER'] = 'mpesa';
        $_SERVER['PAYMENT_PROVIDER'] = 'mpesa';

        $this->mock(\App\Services\MpesaService::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/superadmin/tembea-payouts');
        $response->assertStatus(401);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $user = User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'phone' => '254700000100',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/superadmin/tembea-payouts');
        $response->assertStatus(403);
    }

    public function test_super_admin_can_view_pending_payouts(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $superAdmin = $this->authenticateSuperAdmin();

        $settlement = $this->createTembeaSettlement();

        $response = $this->getJson('/superadmin/tembea-payouts');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $settlement->id);
        $response->assertJsonPath('data.0.operator.company_name', 'Tembea Adventures');
        $response->assertJsonPath('data.0.payout_profile.phone_number', '254700002222');
        $response->assertJsonPath('data.0.bookings_count', 1);
        $response->assertJsonPath('data.0.requested_amount', null);
        $response->assertJsonPath('data.0.requested_at', null);
        $response->assertJsonPath('data.0.requested_by', null);
    }

    public function test_super_admin_can_build_pending_settlements(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $superAdmin = $this->authenticateSuperAdmin();

        $operator = User::create([
            'name' => 'Tembea Operator',
            'email' => 'operator@example.com',
            'phone' => '254700009999',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Tembea Escapes',
            'contact_name' => 'Nia Guide',
            'contact_email' => 'nia@example.com',
            'contact_phone' => '254711110000',
            'status' => 'approved',
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'type' => 'tour_event',
            'title' => 'Safari Sundowner',
            'slug' => 'safari-sundowner',
            'status' => 'published',
            'currency' => 'KES',
        ]);

        $payoutProfile = PayoutProfile::create([
            'bookable_id' => $bookable->id,
            'payout_type' => 'mpesa',
            'phone_number' => '254700009999',
            'is_primary' => true,
        ]);

        $firstBooking = Booking::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'download_token' => 'token-a',
            'bookable_id' => $bookable->id,
            'user_id' => $operator->id,
            'customer_name' => 'First Guest',
            'customer_email' => 'first@example.com',
            'customer_phone' => '254700111111',
            'quantity' => 2,
            'currency' => 'KES',
            'total_amount' => 5000,
            'service_fee_amount' => 500,
            'net_amount' => 4500,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'paid_at' => Carbon::parse('2024-03-10T08:00:00Z'),
        ]);

        $secondBooking = Booking::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'download_token' => 'token-b',
            'bookable_id' => $bookable->id,
            'user_id' => $operator->id,
            'customer_name' => 'Second Guest',
            'customer_email' => 'second@example.com',
            'customer_phone' => '254700222222',
            'quantity' => 4,
            'currency' => 'KES',
            'total_amount' => 7000,
            'service_fee_amount' => 700,
            'net_amount' => 6300,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'paid_at' => Carbon::parse('2024-03-11T12:30:00Z'),
        ]);

        // Non-Tembea booking should be ignored.
        $nonTembeaOrganizer = User::create([
            'name' => 'Sacco Admin',
            'email' => 'sacco2@example.com',
            'phone' => '254700003334',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $nonTembeaBookable = Bookable::create([
            'organizer_id' => $nonTembeaOrganizer->id,
            'type' => 'sacco_safari',
            'title' => 'Daily Shuttle',
            'slug' => 'daily-shuttle',
            'status' => 'published',
            'currency' => 'KES',
        ]);

        Booking::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'download_token' => 'token-c',
            'bookable_id' => $nonTembeaBookable->id,
            'user_id' => $nonTembeaOrganizer->id,
            'customer_name' => 'Transit Rider',
            'customer_email' => 'transit@example.com',
            'customer_phone' => '254700333333',
            'quantity' => 1,
            'currency' => 'KES',
            'total_amount' => 1000,
            'service_fee_amount' => 100,
            'net_amount' => 900,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'paid_at' => Carbon::parse('2024-03-09T09:15:00Z'),
        ]);

        $response = $this->postJson('/superadmin/tembea-payouts/build');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.created_count', 1);
        $response->assertJsonPath('meta.booking_count', 2);
        $response->assertJsonPath('data.0.bookings_count', 2);
        $response->assertJsonPath('data.0.operator.company_name', 'Tembea Escapes');
        $response->assertJsonPath('data.0.payout_profile.id', $payoutProfile->id);
        $response->assertJsonPath('data.0.metadata.generated_by.email', $superAdmin->email);
        $response->assertJsonPath('data.0.requested_amount', null);

        $settlementId = $response->json('data.0.id');
        $this->assertNotNull($settlementId);

        $this->assertSame($settlementId, $firstBooking->fresh()->settlement_id);
        $this->assertSame($settlementId, $secondBooking->fresh()->settlement_id);

        $settlement = Settlement::find($settlementId);
        $this->assertNotNull($settlement);
        $this->assertEquals(12000.0, (float) $settlement->total_amount);
        $this->assertEquals(1200.0, (float) $settlement->fee_amount);
        $this->assertEquals(10800.0, (float) $settlement->net_amount);
    }

    public function test_build_returns_empty_when_no_bookings_ready(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $response = $this->postJson('/superadmin/tembea-payouts/build');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.created_count', 0);
        $response->assertJsonPath('meta.booking_count', 0);
        $response->assertJsonPath('meta.message', 'No paid Tembea bookings without settlements were found.');
    }

    public function test_super_admin_can_initiate_payout(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $superAdmin = $this->authenticateSuperAdmin();

        $settlement = $this->createTembeaSettlement();

        $mpesaMock = Mockery::mock(MpesaService::class);
        $mpesaMock->shouldReceive('b2cPayment')
            ->once()
            ->withArgs(function (array $payload) use ($settlement) {
                $this->assertArrayHasKey('OriginatorConversationID', $payload);
                $this->assertSame(round((float) $settlement->net_amount, 2), $payload['Amount']);
                $this->assertSame('254700002222', $payload['PartyB']);
                $this->assertSame('Tembea Payout', $payload['Occasion']);

                return true;
            })
            ->andReturn([
                'ConversationID' => 'AG_20191219_00004e48cf7e3533f581',
                'ResponseDescription' => 'The service request is processed successfully.',
            ]);

        $this->app->instance(MpesaService::class, $mpesaMock);

        $response = $this->postJson("/superadmin/tembea-payouts/{$settlement->id}/initiate", [
            'note' => 'Send to mpesa tomorrow',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'initiated');
        $response->assertJsonPath('data.metadata.initiation_note', 'Send to mpesa tomorrow');
        $response->assertJsonPath('data.metadata.initiated_by.email', $superAdmin->email);
        $response->assertJsonPath('data.metadata.mpesa_b2c.conversation_id', 'AG_20191219_00004e48cf7e3533f581');
        $response->assertJsonPath('data.metadata.mpesa_b2c.phone_number', '254700002222');

        $fresh = $settlement->fresh();

        $this->assertEquals('initiated', $fresh->status);
        $this->assertEquals('Send to mpesa tomorrow', $fresh->metadata['initiation_note']);
        $this->assertEquals('AG_20191219_00004e48cf7e3533f581', $fresh->metadata['mpesa_b2c']['conversation_id']);
        $this->assertEquals(13500.0, $fresh->metadata['mpesa_b2c']['amount']);
    }

    public function test_initiate_uses_requested_amount_when_present(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $settlement = $this->createTembeaSettlement();
        $settlement->update([
            'requested_amount' => 5000,
            'requested_at' => Carbon::parse('2024-02-20T10:00:00Z'),
            'requested_by' => [
                'id' => 123,
                'name' => 'Tembea Operator',
                'email' => 'tembea@example.com',
            ],
        ]);

        $mpesaMock = Mockery::mock(MpesaService::class);
        $mpesaMock->shouldReceive('b2cPayment')
            ->once()
            ->withArgs(function (array $payload) {
                return $payload['Amount'] === 5000.0;
            })
            ->andReturn([
                'ConversationID' => 'AG_20191219_00004e48cf7e3533f580',
            ]);

        $this->app->instance(MpesaService::class, $mpesaMock);

        $response = $this->postJson("/superadmin/tembea-payouts/{$settlement->id}/initiate");

        $response->assertOk();
        $response->assertJsonPath('data.metadata.mpesa_b2c.amount', 5000);

        $fresh = $settlement->fresh();
        $this->assertEquals(5000.0, $fresh->metadata['mpesa_b2c']['amount']);
    }

    public function test_initiate_payout_returns_mpesa_error_message(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $settlement = $this->createTembeaSettlement();

        $mpesaMock = Mockery::mock(MpesaService::class);
        $mpesaMock->shouldReceive('b2cPayment')
            ->once()
            ->andThrow(new \RuntimeException('The service is unavailable. Try again.'));

        $this->app->instance(MpesaService::class, $mpesaMock);

        $response = $this->postJson("/superadmin/tembea-payouts/{$settlement->id}/initiate");

        $response->assertStatus(502);
        $response->assertJson([
            'message' => 'The service is unavailable. Try again.',
        ]);
    }

    public function test_initiate_requires_pending_status(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $settlement = $this->createTembeaSettlement();
        $settlement->update(['status' => 'initiated']);

        $response = $this->postJson("/superadmin/tembea-payouts/{$settlement->id}/initiate");
        $response->assertStatus(422);
    }

    public function test_initiate_rejects_non_tembea_settlement(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $nonTembeaSettlement = $this->createNonTembeaSettlement();

        $response = $this->postJson("/superadmin/tembea-payouts/{$nonTembeaSettlement->id}/initiate");
        $response->assertStatus(404);
    }

    private function authenticateSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'phone' => '254700000001',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function createTembeaSettlement(): Settlement
    {
        $operator = User::create([
            'name' => 'Tembea Operator',
            'email' => 'tembea@example.com',
            'phone' => '254700002222',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Tembea Adventures',
            'contact_name' => 'Jane Guide',
            'contact_email' => 'guide@example.com',
            'contact_phone' => '254711112222',
            'status' => 'approved',
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'type' => 'tour_event',
            'title' => 'Mount Longonot Hike',
            'slug' => 'mount-longonot-hike',
            'status' => 'published',
            'currency' => 'KES',
        ]);

        $payoutProfile = PayoutProfile::create([
            'bookable_id' => $bookable->id,
            'payout_type' => 'mpesa',
            'phone_number' => '254700002222',
            'is_primary' => true,
        ]);

        $settlement = Settlement::create([
            'bookable_id' => $bookable->id,
            'payout_profile_id' => $payoutProfile->id,
            'total_amount' => 15000,
            'fee_amount' => 1500,
            'net_amount' => 13500,
            'status' => 'pending',
            'period_start' => Carbon::parse('2024-02-01T00:00:00Z'),
            'period_end' => Carbon::parse('2024-02-15T00:00:00Z'),
            'metadata' => [],
        ]);

        Booking::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'download_token' => 'token-123',
            'bookable_id' => $bookable->id,
            'user_id' => $operator->id,
            'customer_name' => 'Alex Traveller',
            'customer_email' => 'alex@example.com',
            'customer_phone' => '254799988877',
            'quantity' => 2,
            'currency' => 'KES',
            'total_amount' => 5000,
            'service_fee_amount' => 500,
            'net_amount' => 4500,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'settlement_id' => $settlement->id,
        ]);

        return $settlement;
    }

    private function createNonTembeaSettlement(): Settlement
    {
        $organizer = User::create([
            'name' => 'Sacco Admin',
            'email' => 'sacco@example.com',
            'phone' => '254700003333',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $organizer->id,
            'type' => 'sacco_safari',
            'title' => 'Safari Ride',
            'slug' => 'safari-ride',
            'status' => 'published',
            'currency' => 'KES',
        ]);

        return Settlement::create([
            'bookable_id' => $bookable->id,
            'payout_profile_id' => null,
            'total_amount' => 8000,
            'fee_amount' => 800,
            'net_amount' => 7200,
            'status' => 'pending',
            'period_start' => Carbon::parse('2024-02-01T00:00:00Z'),
            'period_end' => Carbon::parse('2024-02-07T00:00:00Z'),
            'metadata' => [],
        ]);
    }
}
