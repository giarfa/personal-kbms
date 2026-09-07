<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    /**
     * Only the registration attributes may be mass assigned.
     */
    public function test_it_allows_mass_assignment_of_registration_attributes_only(): void
    {
        $this->assertSame(['name', 'email', 'password'], (new User)->getFillable());
    }

    /**
     * Credentials must never leak through array or JSON serialization.
     */
    public function test_it_hides_credentials_from_serialization(): void
    {
        $user = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
        $user->forceFill(['password' => 'secret-hash', 'remember_token' => 'secret-token']);

        $this->assertSame(['password', 'remember_token'], $user->getHidden());
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $this->assertSame('ada@example.com', $user->toArray()['email']);
    }

    /**
     * Passwords hash on assignment and verification timestamps cast to dates.
     */
    public function test_it_casts_credentials_and_verification_timestamp(): void
    {
        $casts = (new User)->getCasts();

        $this->assertSame('hashed', $casts['password']);
        $this->assertSame('datetime', $casts['email_verified_at']);
    }
}
