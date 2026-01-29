<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\Bookable;
use App\Models\Settlement;
use App\Models\TembeaOperatorProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TembeaOperatorSettlementRequestTest extends TestCase
{
    // setUp removed to rely on RefreshDatabase and actual migrations

    public function test_operator_can_request_payout_for_owned_settlement(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = $this->createOperator();
        $settlement = $this->createSettlementForOperator($operator, [
            'net_amount' => 9000,
        ]);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->postJson("/api/tembea/settlements/{$settlement->id}/request-payout", [
            'amount' => 7500.50,
            'note' => 'Please process by Friday.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'requested');
        $response->assertJsonPath('data.requested_amount', 7500.5);
        $response->assertJsonPath('data.requested_by.id', $operator->id);
        $response->assertJsonPath('data.metadata.request_note', 'Please process by Friday.');

        $fresh = $settlement->fresh();

        $this->assertSame('requested', $fresh->status);
        $this->assertSame(7500.5, (float) $fresh->requested_amount);
        $this->assertNotNull($fresh->requested_at);
        $this->assertSame($operator->email, $fresh->requested_by['email']);
    }

    public function test_request_rejects_amount_above_outstanding(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = $this->createOperator();
        $settlement = $this->createSettlementForOperator($operator, [
            'net_amount' => 5000,
        ]);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->postJson("/api/tembea/settlements/{$settlement->id}/request-payout", [
            'amount' => 6000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Requested amount exceeds outstanding balance.');
    }

    public function test_request_rejects_when_settlement_not_pending_or_requested(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = $this->createOperator();
        $settlement = $this->createSettlementForOperator($operator, [
            'status' => 'initiated',
        ]);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->postJson("/api/tembea/settlements/{$settlement->id}/request-payout", [
            'amount' => 1000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Settlement is not eligible for payout request.');
    }

    public function test_request_requires_positive_amount(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = $this->createOperator();
        $settlement = $this->createSettlementForOperator($operator);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->postJson("/api/tembea/settlements/{$settlement->id}/request-payout", [
            'amount' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_operator_cannot_request_payout_for_foreign_settlement(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $operator = $this->createOperator();
        $otherOperator = $this->createOperator([
            'email' => 'other@example.com',
            'phone' => '254799999999',
        ]);

        $settlement = $this->createSettlementForOperator($otherOperator);

        Sanctum::actingAs($operator, ['*']);
        $this->actingAs($operator, 'sanctum');

        $response = $this->postJson("/api/tembea/settlements/{$settlement->id}/request-payout", [
            'amount' => 1000,
        ]);

        $response->assertStatus(404);
    }

    private function createOperator(array $overrides = []): User
    {
        $defaults = [
            'name' => 'Tembea Operator',
            'email' => 'operator@example.com',
            'phone' => '254700000100',
        ];

        $attributes = array_merge($defaults, $overrides, [
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        /** @var User $operator */
        $operator = User::create($attributes);

        TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Tembea Adventures',
            'contact_name' => 'Jane Guide',
            'contact_email' => $operator->email,
            'contact_phone' => $operator->phone,
            'status' => 'approved',
        ]);

        return $operator;
    }

    private function createSettlementForOperator(User $operator, array $overrides = []): Settlement
    {
        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'type' => 'tour_event',
            'title' => 'Weekend Safari',
            'status' => 'published',
            'currency' => 'KES',
        ]);

        $defaults = [
            'bookable_id' => $bookable->id,
            'payout_profile_id' => null,
            'total_amount' => 10000,
            'fee_amount' => 1000,
            'net_amount' => 9000,
            'status' => 'pending',
            'period_start' => Carbon::parse('2024-01-01T00:00:00Z'),
            'period_end' => Carbon::parse('2024-01-31T00:00:00Z'),
            'metadata' => [],
        ];

        return Settlement::create(array_merge($defaults, $overrides));
    }
}
