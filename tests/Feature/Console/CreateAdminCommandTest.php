<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_valid_its_and_password(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('ITS Number (8 digits)', '40484562')
            ->expectsQuestion('Full name', 'Admin User')
            ->expectsQuestion('Password (min 8 characters, not echoed)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertExitCode(0);

        $user = User::where('its_number', '40484562')->first();

        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
    }

    public function test_refuses_invalid_its_format(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('ITS Number (8 digits)', '1234')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_refuses_duplicate_its(): void
    {
        User::factory()->create(['its_number' => '40484562']);

        $this->artisan('app:create-admin')
            ->expectsQuestion('ITS Number (8 digits)', '40484562')
            ->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_refuses_mismatched_password_confirmation(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('ITS Number (8 digits)', '40484562')
            ->expectsQuestion('Full name', 'Admin User')
            ->expectsQuestion('Password (min 8 characters, not echoed)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'different-password')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }
}
