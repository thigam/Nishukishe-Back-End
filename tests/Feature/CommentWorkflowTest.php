<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bookable;
use App\Models\Comment;
use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\CommentLeftNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_commuter_with_booking_can_create_comment_for_sacco(): void
    {
        ['commuter' => $commuter, 'sacco' => $sacco] = $this->createFixtures();

        Notification::fake();

        $response = $this->actingAs($commuter, 'sanctum')->postJson(
            "/api/comments/saccos/{$sacco->sacco_id}",
            [
                'body' => 'I loved how clean the vehicles were on this sacco.',
                'rating' => 5,
            ]
        );

        $response->assertCreated()->assertJsonFragment([
            'body' => 'I loved how clean the vehicles were on this sacco.',
            'status' => Comment::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => Sacco::class,
            'commentable_id' => $sacco->sacco_id,
            'user_id' => $commuter->id,
            'status' => Comment::STATUS_PENDING,
        ]);

        Notification::assertSentTo([$this->saccoManagerUser], CommentLeftNotification::class);
    }

    public function test_commuter_without_booking_can_comment_on_sacco(): void
    {
        $data = $this->createFixtures(withBooking: false);
        $commuter = $data['commuter'];
        $sacco = $data['sacco'];

        $response = $this->actingAs($commuter, 'sanctum')
            ->postJson("/api/comments/saccos/{$sacco->sacco_id}", [
                'body' => 'Trying to comment without riding.',
                'rating' => 3,
            ]);

        $response->assertCreated()->assertJsonFragment([
            'body' => 'Trying to comment without riding.',
            'status' => Comment::STATUS_PENDING,
        ]);
    }

    public function test_sacco_admin_can_comment_on_owned_sacco(): void
    {
        $data = $this->createFixtures(withBooking: false);
        $saccoAdmin = $data['saccoAdmin'];
        $sacco = $data['sacco'];

        $this->actingAs($saccoAdmin, 'sanctum')
            ->postJson("/api/comments/saccos/{$sacco->sacco_id}", [
                'body' => 'Official response from the sacco team.',
                'rating' => 4,
            ])
            ->assertCreated()
            ->assertJsonFragment([
                'body' => 'Official response from the sacco team.',
                'status' => Comment::STATUS_PENDING,
            ]);
    }

    public function test_sacco_manager_without_role_can_comment_on_owned_sacco(): void
    {
        $data = $this->createFixtures(withBooking: false);
        $sacco = $data['sacco'];

        $managerWithoutRole = User::create([
            'name' => 'Manager Without Role',
            'email' => Str::random(6) . '@manager.example.com',
            'phone' => '0712000000',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
        ]);

        SaccoManager::create([
            'user_id' => $managerWithoutRole->id,
            'sacco_id' => $sacco->sacco_id,
        ]);

        $this->actingAs($managerWithoutRole, 'sanctum')
            ->postJson("/api/comments/saccos/{$sacco->sacco_id}", [
                'body' => 'Response from manager without sacco role.',
                'rating' => 4,
            ])
            ->assertCreated()
            ->assertJsonFragment([
                'body' => 'Response from manager without sacco role.',
                'status' => Comment::STATUS_PENDING,
            ]);
    }

    public function test_public_listing_returns_only_approved_comments(): void
    {
        $data = $this->createFixtures();
        $sacco = $data['sacco'];
        $commuter = $data['commuter'];

        Comment::create([
            'commentable_type' => Sacco::class,
            'commentable_id' => $sacco->sacco_id,
            'user_id' => $commuter->id,
            'body' => 'Approved comment',
            'status' => Comment::STATUS_APPROVED,
        ]);

        Comment::create([
            'commentable_type' => Sacco::class,
            'commentable_id' => $sacco->sacco_id,
            'user_id' => $commuter->id,
            'body' => 'Hidden comment',
            'status' => Comment::STATUS_HIDDEN,
        ]);

        $this->getJson("/api/comments/saccos/{$sacco->sacco_id}")
            ->assertOk()
            ->assertJsonMissing(['body' => 'Hidden comment'])
            ->assertJsonFragment(['body' => 'Approved comment']);
    }

    public function test_sacco_admin_can_moderate_comment(): void
    {
        $data = $this->createFixtures();
        $saccoAdmin = $data['saccoAdmin'];
        $sacco = $data['sacco'];
        $commuter = $data['commuter'];

        $comment = Comment::create([
            'commentable_type' => Sacco::class,
            'commentable_id' => $sacco->sacco_id,
            'user_id' => $commuter->id,
            'body' => 'Needs review',
            'status' => Comment::STATUS_PENDING,
        ]);

        $this->actingAs($saccoAdmin, 'sanctum')
            ->postJson("/api/comments/{$comment->id}/moderate", ['status' => Comment::STATUS_APPROVED])
            ->assertOk()
            ->assertJsonFragment(['status' => Comment::STATUS_APPROVED]);

        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => Comment::STATUS_APPROVED]);
    }

    public function test_operator_can_view_pending_comments_for_dashboard(): void
    {
        $data = $this->createFixtures();
        $operator = $data['operator'];
        $operatorProfile = $data['operatorProfile'];
        $commuter = $data['commuter'];

        Comment::create([
            'commentable_type' => TembeaOperatorProfile::class,
            'commentable_id' => (string) $operatorProfile->id,
            'user_id' => $commuter->id,
            'body' => 'Pending operator review',
            'status' => Comment::STATUS_PENDING,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/comments/operators/{$operatorProfile->slug}?status=pending")
            ->assertOk()
            ->assertJsonFragment(['body' => 'Pending operator review'])
            ->assertJsonPath('meta.can_moderate', true);
    }

    public function test_commuter_without_booking_can_comment_on_operator_profile(): void
    {
        $data = $this->createFixtures(withBooking: false);
        $commuter = $data['commuter'];
        $operatorProfile = $data['operatorProfile'];

        $response = $this->actingAs($commuter, 'sanctum')
            ->postJson("/api/comments/operators/{$operatorProfile->slug}", [
                'body' => 'Sharing feedback without a booking.',
                'rating' => 4,
            ]);

        $response->assertCreated()->assertJsonFragment([
            'body' => 'Sharing feedback without a booking.',
            'status' => Comment::STATUS_PENDING,
        ]);
    }

    public function test_commuter_without_tour_booking_gets_helpful_error(): void
    {
        $data = $this->createFixtures(withBooking: false);
        $commuter = $data['commuter'];
        $bookable = $data['bookable'];

        $this->actingAs($commuter, 'sanctum')
            ->postJson("/api/comments/tours/{$bookable->slug}", [
                'body' => 'Trying to comment on a tour without booking.',
                'rating' => 4,
            ])
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'You need a confirmed or paid booking to leave a comment on this tour.',
            ]);
    }

    private function createFixtures(bool $withBooking = true): array
    {
        $commuter = User::create([
            'name' => 'Fixture Commuter',
            'email' => Str::random(6) . '@example.com',
            'phone' => '0710000000',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
        ]);

        $sacco = Sacco::create([
            'sacco_id' => 'SAC' . Str::upper(Str::random(5)),
            'sacco_name' => 'Fixture Sacco',
            'vehicle_type' => 'Matatu',
            'join_date' => now(),
            'join_date' => now(),
        ]);

        $saccoAdmin = User::create([
            'name' => 'Fixture Sacco Admin',
            'email' => Str::random(6) . '@sacco.example.com',
            'phone' => '0711000000',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        SaccoManager::create([
            'user_id' => $saccoAdmin->id,
            'sacco_id' => $sacco->sacco_id,
        ]);

        $operator = User::create([
            'name' => 'Fixture Operator',
            'email' => Str::random(6) . '@tembea.example.com',
            'phone' => '0799000000',
            'password' => bcrypt('password'),
            'role' => UserRole::TEMBEA,
            'is_verified' => true,
            'is_active' => true,
        ]);

        $operatorProfile = TembeaOperatorProfile::create([
            'user_id' => $operator->id,
            'company_name' => 'Fixture Operator Co.',
            'slug' => 'fixture-operator-' . Str::random(4),
            'contact_name' => 'Fixture Contact',
            'contact_email' => 'contact@tembea.test',
            'contact_phone' => '0799000000',
            'public_email' => 'public@tembea.test',
            'public_phone' => '0799000000',
            'status' => 'approved',
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $operator->id,
            'sacco_id' => $sacco->sacco_id,
            'type' => 'tour_event',
            'title' => 'Fixture Tour',
            'slug' => 'fixture-tour-' . Str::random(4),
            'status' => 'published',
            'currency' => 'KES',
            'service_fee_rate' => 0.05,
            'service_fee_flat' => 0,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
        ]);

        $tourEvent = TourEvent::create([
            'bookable_id' => $bookable->id,
            'destination' => 'Fixture Destination',
            'meeting_point' => 'Fixture Meeting',
            'duration_label' => '1 day',
        ]);

        if ($withBooking) {
            Booking::create([
                'bookable_id' => $bookable->id,
                'user_id' => $commuter->id,
                'customer_name' => $commuter->name,
                'customer_email' => $commuter->email,
                'customer_phone' => $commuter->phone,
                'quantity' => 1,
                'currency' => 'KES',
                'total_amount' => 1000,
                'service_fee_amount' => 0,
                'net_amount' => 1000,
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        $this->saccoManagerUser = $saccoAdmin;

        return [
            'commuter' => $commuter,
            'sacco' => $sacco,
            'saccoAdmin' => $saccoAdmin,
            'operator' => $operator,
            'operatorProfile' => $operatorProfile,
            'bookable' => $bookable,
            'tourEvent' => $tourEvent,
        ];
    }

    private User $saccoManagerUser;
}
