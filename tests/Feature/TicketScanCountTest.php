<?php

namespace Tests\Feature;

use App\Models\Bookable;
use App\Models\Booking;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

class TicketScanCountTest extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tickets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('bookables');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('role')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });

        Schema::create('bookables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('sacco_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('KES');
            $table->decimal('service_fee_rate', 8, 5)->default(0);
            $table->decimal('service_fee_flat', 10, 2)->default(0);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('currency', 3)->default('KES');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('service_fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('download_token')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->unsignedBigInteger('ticket_tier_id')->nullable();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('qr_code')->unique();
            $table->string('status')->default('issued');
            $table->string('passenger_name')->nullable();
            $table->string('passenger_email')->nullable();
            $table->json('passenger_metadata')->nullable();
            $table->decimal('price_paid', 12, 2)->default(0);
            $table->timestamp('scanned_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('bookables');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_mark_scanned_increments_counter_without_changing_initial_timestamp(): void
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'phone' => '+254700000000',
            'password' => Hash::make('secret'),
            'role' => 'operator',
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $bookable = Bookable::create([
            'organizer_id' => $user->getKey(),
            'type' => 'tour',
            'title' => 'Maasai Mara Safari',
            'status' => 'published',
            'currency' => 'KES',
            'service_fee_rate' => 0,
            'service_fee_flat' => 0,
        ]);

        $booking = Booking::create([
            'bookable_id' => $bookable->getKey(),
            'user_id' => $user->getKey(),
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+254711223344',
            'quantity' => 1,
            'currency' => 'KES',
            'total_amount' => 12500,
            'service_fee_amount' => 0,
            'net_amount' => 12500,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $ticket = Ticket::create([
            'uuid' => (string) Str::uuid(),
            'bookable_id' => $bookable->getKey(),
            'ticket_tier_id' => null,
            'booking_id' => $booking->getKey(),
            'qr_code' => 'TEMBEA-QR-001',
            'status' => 'issued',
            'passenger_name' => 'Jane Doe',
            'passenger_email' => 'jane@example.com',
            'price_paid' => 12500,
        ]);

        $ticket->refresh();

        $this->assertSame(0, $ticket->scan_count);
        $this->assertNull($ticket->scanned_at);

        $ticket->markScanned();
        $ticket->refresh();

        $this->assertSame('scanned', $ticket->status);
        $this->assertSame(1, $ticket->scan_count);
        $this->assertNotNull($ticket->scanned_at);

        $firstScanTimestamp = $ticket->scanned_at;

        $ticket->markScanned();
        $ticket->refresh();

        $this->assertSame(2, $ticket->scan_count);
        $this->assertTrue($ticket->scanned_at->equalTo($firstScanTimestamp));
        $this->assertSame('scanned', $ticket->status);

        $ticket->markScanned();
        $ticket->refresh();

        $this->assertSame(3, $ticket->scan_count);
        $this->assertTrue($ticket->scanned_at->equalTo($firstScanTimestamp));
    }
}
