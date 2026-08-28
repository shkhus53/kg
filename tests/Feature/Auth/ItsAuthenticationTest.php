<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItsAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_its_and_correct_password_logs_in(): void
    {
        $user = User::factory()->create(['its_number' => '40484562']);

        $response = $this->post('/login', ['its_number' => '40484562', 'password' => 'password']);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_valid_its_with_wrong_password_is_rejected(): void
    {
        User::factory()->create(['its_number' => '40484562']);

        $this->post('/login', ['its_number' => '40484562', 'password' => 'wrong']);

        $this->assertGuest();
    }

    public function test_seven_digit_its_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '1234567', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_nine_digit_its_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '123456789', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_alphabetic_its_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '1234abcd', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_its_with_spaces_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '1234 5678', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_its_with_symbols_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '12-345678', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_empty_its_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_leading_zero_its_is_preserved_and_matches_exactly(): void
    {
        $user = User::factory()->create(['its_number' => '00001234']);

        $this->assertSame('00001234', $user->fresh()->its_number);

        $response = $this->post('/login', ['its_number' => '00001234', 'password' => 'password']);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_leading_zero_its_does_not_match_stripped_numeric_value(): void
    {
        User::factory()->create(['its_number' => '00001234']);

        $this->post('/login', ['its_number' => '1234', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_unknown_its_is_rejected(): void
    {
        $response = $this->post('/login', ['its_number' => '99999999', 'password' => 'password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_logout_works(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_session_remains_authenticated_after_login(): void
    {
        $user = User::factory()->create(['its_number' => '40484562']);

        $this->post('/login', ['its_number' => '40484562', 'password' => 'password']);

        $response = $this->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_viewer_role_remains_read_only_after_its_login(): void
    {
        $viewer = User::factory()->viewer()->create(['its_number' => '40000001']);

        $this->actingAs($viewer);

        $this->assertFalse($viewer->canManageSessions());
    }

    public function test_operator_and_admin_permissions_remain_correct_after_its_login(): void
    {
        $operator = User::factory()->operator()->create(['its_number' => '40000002']);
        $admin = User::factory()->admin()->create(['its_number' => '40000003']);

        $this->assertTrue($operator->canManageSessions());
        $this->assertTrue($admin->canManageSessions());
    }

    public function test_unauthorized_direct_http_request_remains_blocked(): void
    {
        $viewer = User::factory()->viewer()->create(['its_number' => '40000004']);

        $response = $this->actingAs($viewer)->get('/sessions/create');

        $response->assertForbidden();
    }
}
