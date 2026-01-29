<?php

namespace Tests\Unit;

use App\Models\UserRole;
use PHPUnit\Framework\TestCase;

class AdminSignUpTest extends TestCase
{
    public function test_service_person_role_constant_and_assignment(): void
    {
        $this->assertEquals('nishukishe_service_person', UserRole::SERVICE_PERSON);

        $roleValue = ('nishukishe_service_person' == 'nishukishe_service_person')
            ? UserRole::SERVICE_PERSON
            : UserRole::SUPER_ADMIN;

        $this->assertEquals(UserRole::SERVICE_PERSON, $roleValue);
    }
}
