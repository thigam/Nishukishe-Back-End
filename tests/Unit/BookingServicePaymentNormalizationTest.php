<?php

namespace Tests\Unit;

use App\Services\Bookings\BookingService;
use App\Services\Bookings\PaymentGatewayManager;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BookingServicePaymentNormalizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testJengaCardForcesCardChannel(): void
    {
        $service = new BookingService(Mockery::mock(PaymentGatewayManager::class));

        [$method, $channel] = $this->normalize($service, [
            'payment_method' => 'jenga_card',
            'payment_channel' => null,
        ]);

        $this->assertSame('jenga', $method);
        $this->assertSame('CARD', $channel);
    }

    public function testJengaMobileForcesMobileChannel(): void
    {
        $service = new BookingService(Mockery::mock(PaymentGatewayManager::class));

        [$method, $channel] = $this->normalize($service, [
            'payment_method' => 'jenga_mobile',
            'payment_channel' => 'card', // should be overridden
        ]);

        $this->assertSame('jenga', $method);
        $this->assertSame('MOBILE', $channel);
    }

    public function testMpesaDefaultsToMobileChannel(): void
    {
        $service = new BookingService(Mockery::mock(PaymentGatewayManager::class));

        [$method, $channel] = $this->normalize($service, [
            'payment_method' => 'mpesa',
            'payment_channel' => null,
        ]);

        $this->assertSame('mpesa', $method);
        $this->assertSame('MOBILE', $channel);
    }

    public function testChannelsAreUppercasedWhenProvided(): void
    {
        $service = new BookingService(Mockery::mock(PaymentGatewayManager::class));

        [$method, $channel] = $this->normalize($service, [
            'payment_method' => 'jenga',
            'payment_channel' => 'mobile',
        ]);

        $this->assertSame('jenga', $method);
        $this->assertSame('MOBILE', $channel);
    }

    private function normalize(BookingService $service, array $payload): array
    {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizePaymentSelection');
        $method->setAccessible(true);

        return $method->invoke($service, $payload);
    }
}
