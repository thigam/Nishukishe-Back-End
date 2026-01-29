<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Bookable;
use App\Models\Comment;
use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\TembeaOperatorProfile;
use App\Models\TourEvent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CommentSeeder extends Seeder
{
    public function run(): void
    {
        $commuter = User::firstOrCreate(
            ['email' => 'commenter@example.com'],
            [
                'name' => 'Comment Seed Commuter',
                'phone' => '0712000000',
                'password' => bcrypt('password'),
                'role' => UserRole::USER,
                'is_verified' => true,
                'is_active' => true,
            ]
        );

        $sacco = Sacco::firstOrCreate(
            ['sacco_id' => 'NISH-001'],
            [
                'sacco_name' => 'Nish Sample Sacco',
                'vehicle_type' => 'Matatu',
                'join_date' => Carbon::now(),
                'sacco_location' => 'Nairobi',
                'sacco_phone' => '0700000001',
                'sacco_email' => 'sacco@nishukishe.com',
                'sacco_routes' => [],
                'is_approved' => true,
            ]
        );

        $saccoManagerUser = User::firstOrCreate(
            ['email' => 'sacco.manager@example.com'],
            [
                'name' => 'Seed Sacco Manager',
                'phone' => '0711000000',
                'password' => bcrypt('password'),
                'role' => UserRole::SACCO,
                'is_verified' => true,
                'is_active' => true,
                'is_approved' => true,
            ]
        );

        SaccoManager::firstOrCreate([
            'user_id' => $saccoManagerUser->id,
            'sacco_id' => $sacco->sacco_id,
        ]);

        $operatorUser = User::firstOrCreate(
            ['email' => 'tembea.operator@example.com'],
            [
                'name' => 'Tembea Operator',
                'phone' => '0799000000',
                'password' => bcrypt('password'),
                'role' => UserRole::TEMBEA,
                'is_verified' => true,
                'is_active' => true,
            ]
        );

        $operatorProfile = TembeaOperatorProfile::firstOrCreate(
            ['user_id' => $operatorUser->id],
            [
                'company_name' => 'Tembea Adventures',
                'slug' => 'tembea-adventures',
                'contact_name' => 'Tembea Admin',
                'contact_email' => 'tembea.operator@example.com',
                'contact_phone' => '0799000000',
                'public_email' => 'hello@tembea.com',
                'public_phone' => '0799000000',
                'status' => 'approved',
                'metadata' => [
                    'about' => 'Seeded operator profile for comments.',
                ],
            ]
        );

        $bookable = Bookable::firstOrCreate(
            ['slug' => 'tembea-sample-tour'],
            [
                'organizer_id' => $operatorUser->id,
                'sacco_id' => $sacco->sacco_id,
                'type' => 'tour_event',
                'title' => 'Tembea Sample Tour',
                'subtitle' => 'Seeder adventure',
                'description' => 'A sample tour created from the database seeder.',
                'status' => 'published',
                'currency' => 'KES',
                'service_fee_rate' => 0.05,
                'service_fee_flat' => 0,
                'starts_at' => Carbon::now()->addWeek(),
                'ends_at' => Carbon::now()->addWeek()->addDay(),
                'metadata' => ['source' => 'comment seeder'],
            ]
        );

        $tourEvent = TourEvent::firstOrCreate(
            ['bookable_id' => $bookable->id],
            [
                'destination' => 'Lake Ellis',
                'meeting_point' => 'CBD Pick-up',
                'duration_label' => 'Full day',
                'highlights' => ['Waterfalls', 'Glacier lake'],
            ]
        );

        Booking::firstOrCreate(
            [
                'bookable_id' => $bookable->id,
                'user_id' => $commuter->id,
            ],
            [
                'customer_name' => $commuter->name,
                'customer_email' => $commuter->email,
                'customer_phone' => $commuter->phone,
                'quantity' => 1,
                'currency' => 'KES',
                'total_amount' => 1500,
                'service_fee_amount' => 0,
                'net_amount' => 1500,
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]
        );

        Comment::updateOrCreate(
            [
                'commentable_type' => Sacco::class,
                'commentable_id' => $sacco->sacco_id,
                'user_id' => $commuter->id,
            ],
            [
                'body' => 'Great rides with clean vehicles and punctual crews from the seeder.',
                'rating' => 5,
                'status' => Comment::STATUS_APPROVED,
            ]
        );

        Comment::updateOrCreate(
            [
                'commentable_type' => TembeaOperatorProfile::class,
                'commentable_id' => (string) $operatorProfile->getKey(),
                'user_id' => $commuter->id,
            ],
            [
                'body' => 'Tembea Adventures curated a lovely day trip.',
                'rating' => 4,
                'status' => Comment::STATUS_PENDING,
            ]
        );

        Comment::updateOrCreate(
            [
                'commentable_type' => TourEvent::class,
                'commentable_id' => (string) $tourEvent->getKey(),
                'user_id' => $commuter->id,
            ],
            [
                'body' => 'The Lake Ellis hike was beautiful even under light rain.',
                'rating' => 5,
                'status' => Comment::STATUS_HIDDEN,
            ]
        );
    }
}
