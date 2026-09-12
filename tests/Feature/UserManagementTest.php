<?php

namespace Tests\Feature;

use App\Models\MasterDataChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Admin-only User Management. This is account administration, NOT
 * self-registration — there is still no public registration route
 * anywhere in the application (see test_no_public_registration_route_exists
 * below). Every mutation route is gated server-side by the `manage_users`
 * permission (admin only); the nav item being hidden from other roles is
 * not the security boundary, the route middleware is.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function operator(): User
    {
        return User::factory()->operator()->create();
    }

    private function viewer(): User
    {
        return User::factory()->viewer()->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'its_number' => '30123456',
            'role' => 'operator',
            'is_active' => '1',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ], $overrides);
    }

    // ===================================================================
    // LOGIN-ONLY REGRESSION: confirm no public registration was added
    // ===================================================================

    public function test_no_public_registration_route_exists(): void
    {
        $this->assertFalse(Route::has('register'), 'No public self-registration route may exist.');
    }

    public function test_login_page_contains_no_register_or_sign_up_link(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('Register', false);
        $response->assertDontSee('Sign up', false);
        $response->assertDontSee('Sign Up', false);
        $response->assertDontSee('Create account', false);
        $response->assertDontSee('Create Account', false);
    }

    // ===================================================================
    // AUTHORIZATION
    // ===================================================================

    public function test_admin_can_access_user_management(): void
    {
        $this->actingAs($this->admin())->get(route('users.index'))->assertOk();
    }

    public function test_operator_receives_403_on_user_management(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('users.create'))->assertForbidden();
        $this->actingAs($operator)->post(route('users.store'), $this->validPayload())->assertForbidden();
    }

    public function test_viewer_receives_403_on_user_management(): void
    {
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get(route('users.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('users.create'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    // ===================================================================
    // CREATE
    // ===================================================================

    public function test_admin_can_create_a_user(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('users.store'), $this->validPayload());

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['its_number' => '30123456', 'name' => 'New User', 'role' => 'operator', 'is_active' => true]);
    }

    public function test_duplicate_its_number_is_rejected_on_create(): void
    {
        $admin = $this->admin();
        User::factory()->create(['its_number' => '30123456']);

        $response = $this->actingAs($admin)->post(route('users.store'), $this->validPayload());

        $response->assertSessionHasErrors('its_number');
    }

    public function test_invalid_its_number_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['its_number' => '123']))
            ->assertSessionHasErrors('its_number');

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['its_number' => 'abcd1234']))
            ->assertSessionHasErrors('its_number');
    }

    public function test_leading_zero_its_number_is_preserved(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['its_number' => '00123456']))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['its_number' => '00123456']);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['role' => 'superadmin']))
            ->assertSessionHasErrors('role');
    }

    public function test_password_is_hashed_and_plaintext_never_persisted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['password' => 'a-plain-password-123', 'password_confirmation' => 'a-plain-password-123']));

        $user = User::where('its_number', '30123456')->firstOrFail();
        $this->assertTrue(Hash::check('a-plain-password-123', $user->password));
        $this->assertNotEquals('a-plain-password-123', $user->password);

        $raw = \DB::table('users')->where('id', $user->id)->value('password');
        $this->assertStringNotContainsString('a-plain-password-123', $raw);
    }

    public function test_create_requires_password_confirmation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload(['password_confirmation' => 'mismatch-value']))
            ->assertSessionHasErrors('password');
    }

    // ===================================================================
    // EDIT
    // ===================================================================

    public function test_admin_can_edit_a_user(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $response = $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => 'Renamed', 'its_number' => $target->its_number, 'role' => 'viewer', 'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed', 'role' => 'viewer']);
    }

    public function test_edit_rejects_duplicate_its_number(): void
    {
        $admin = $this->admin();
        $target = $this->operator();
        $other = User::factory()->create(['its_number' => '30999999']);

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => '30999999', 'role' => 'operator', 'is_active' => '1',
        ])->assertSessionHasErrors('its_number');
    }

    public function test_edit_does_not_require_a_password(): void
    {
        $admin = $this->admin();
        $target = $this->operator();
        $originalHash = $target->password;

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => 'Still No Password Field', 'its_number' => $target->its_number, 'role' => 'operator', 'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    // ===================================================================
    // STATUS (activate/deactivate)
    // ===================================================================

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => $target->its_number, 'role' => 'operator', 'is_active' => '0',
        ]);
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => $target->its_number, 'role' => 'operator', 'is_active' => '1',
        ]);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['its_number' => '30555555', 'password' => Hash::make('secret-password'), 'is_active' => false]);

        $response = $this->post(route('login'), ['its_number' => '30555555', 'password' => 'secret-password']);

        $response->assertSessionHasErrors('its_number');
        $this->assertGuest();
    }

    public function test_active_user_can_log_in(): void
    {
        $user = User::factory()->create(['its_number' => '30555556', 'password' => Hash::make('secret-password'), 'is_active' => true]);

        $response = $this->post(route('login'), ['its_number' => '30555556', 'password' => 'secret-password']);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    // ===================================================================
    // PASSWORD MANAGEMENT
    // ===================================================================

    public function test_admin_can_set_a_new_password_for_a_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['its_number' => '30111111', 'password' => Hash::make('old-password-123')]);

        $response = $this->actingAs($admin)->put(route('users.password.update', $target), [
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ]);

        $response->assertRedirect(route('users.edit', $target));

        // The admin is still authenticated from actingAs() — the 'guest'
        // middleware on /login would otherwise redirect straight past the
        // controller (never validating anything) before we can exercise
        // the target user's own login attempt.
        $this->post(route('logout'));

        $loginOld = $this->post(route('login'), ['its_number' => '30111111', 'password' => 'old-password-123']);
        $loginOld->assertSessionHasErrors('its_number');
        $this->assertGuest();

        $loginNew = $this->post(route('login'), ['its_number' => '30111111', 'password' => 'brand-new-password-456']);
        $loginNew->assertRedirect();
        $this->assertAuthenticatedAs($target->fresh());
    }

    public function test_password_never_appears_in_audit_log(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $this->actingAs($admin)->put(route('users.password.update', $target), [
            'password' => 'super-secret-value-789', 'password_confirmation' => 'super-secret-value-789',
        ]);

        $logs = MasterDataChangeLog::where('entity_type', 'user')->where('entity_id', $target->id)->get();
        $this->assertTrue($logs->isNotEmpty());
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('super-secret-value-789', (string) $log->old_value);
            $this->assertStringNotContainsString('super-secret-value-789', (string) $log->new_value);
        }
    }

    // ===================================================================
    // SECURITY
    // ===================================================================

    public function test_operator_cannot_change_roles_or_deactivate_users(): void
    {
        $operator = $this->operator();
        $target = $this->viewer();

        $this->actingAs($operator)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => $target->its_number, 'role' => 'admin', 'is_active' => '0',
        ])->assertForbidden();

        $this->assertSame('viewer', $target->fresh()->role);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_viewer_cannot_perform_any_user_management_mutation(): void
    {
        $viewer = $this->viewer();
        $target = $this->operator();

        $this->actingAs($viewer)->put(route('users.update', $target), [
            'name' => 'Hacked', 'its_number' => $target->its_number, 'role' => 'admin', 'is_active' => '1',
        ])->assertForbidden();

        $this->actingAs($viewer)->put(route('users.password.update', $target), [
            'password' => 'whatever-password', 'password_confirmation' => 'whatever-password',
        ])->assertForbidden();
    }

    public function test_idor_attempt_on_arbitrary_user_id_by_non_admin_fails(): void
    {
        $operator = $this->operator();
        $admin = $this->admin();

        $this->actingAs($operator)->get(route('users.edit', $admin))->assertForbidden();
    }

    // ===================================================================
    // ADMIN SAFETY
    // ===================================================================

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();
        $this->admin(); // a second admin exists, so this is not a last-admin scenario

        $response = $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name, 'its_number' => $admin->its_number, 'role' => 'admin', 'is_active' => '0',
        ]);

        $response->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_deactivated_by_another_admin(): void
    {
        $onlyAdmin = $this->admin();
        $actingAdmin = $onlyAdmin; // only one admin exists in this scenario

        // A second admin performs the action conceptually, but since only one
        // admin exists, simulate another admin acting on the sole admin:
        $secondAdmin = $this->admin();
        // Deactivate the second admin first so $onlyAdmin becomes the last one.
        $secondAdmin->update(['is_active' => false]);

        $response = $this->actingAs($secondAdmin)->put(route('users.update', $onlyAdmin), [
            'name' => $onlyAdmin->name, 'its_number' => $onlyAdmin->its_number, 'role' => 'admin', 'is_active' => '0',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertTrue($onlyAdmin->fresh()->is_active);
    }

    public function test_last_active_admin_role_cannot_be_changed_away_from_admin(): void
    {
        $lastAdmin = $this->admin();
        $otherAdminActingUser = $this->admin();
        $otherAdminActingUser->update(['is_active' => false]);

        $response = $this->actingAs($otherAdminActingUser)->put(route('users.update', $lastAdmin), [
            'name' => $lastAdmin->name, 'its_number' => $lastAdmin->its_number, 'role' => 'viewer', 'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame('admin', $lastAdmin->fresh()->role);
    }

    public function test_admin_can_deactivate_another_admin_when_a_third_active_admin_remains(): void
    {
        $actingAdmin = $this->admin();
        $targetAdmin = $this->admin();
        $this->admin(); // a third active admin, so deactivating the target is safe

        $response = $this->actingAs($actingAdmin)->put(route('users.update', $targetAdmin), [
            'name' => $targetAdmin->name, 'its_number' => $targetAdmin->its_number, 'role' => 'admin', 'is_active' => '0',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertFalse($targetAdmin->fresh()->is_active);
    }

    // ===================================================================
    // AUDIT
    // ===================================================================

    public function test_create_generates_an_audit_record(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), $this->validPayload());
        $user = User::where('its_number', '30123456')->firstOrFail();

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'user', 'entity_id' => $user->id, 'field' => 'status', 'new_value' => 'created', 'changed_by' => $admin->id,
        ]);
    }

    public function test_role_change_generates_an_audit_record(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => $target->its_number, 'role' => 'viewer', 'is_active' => '1',
        ]);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'user', 'entity_id' => $target->id, 'field' => 'status', 'new_value' => 'role_changed_to_viewer', 'changed_by' => $admin->id,
        ]);
    }

    public function test_status_change_generates_an_audit_record(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name, 'its_number' => $target->its_number, 'role' => 'operator', 'is_active' => '0',
        ]);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'user', 'entity_id' => $target->id, 'field' => 'status', 'new_value' => 'deactivated', 'changed_by' => $admin->id,
        ]);
    }

    public function test_password_set_generates_an_audit_record_without_the_password_value(): void
    {
        $admin = $this->admin();
        $target = $this->operator();

        $this->actingAs($admin)->put(route('users.password.update', $target), [
            'password' => 'another-secret-value', 'password_confirmation' => 'another-secret-value',
        ]);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'user', 'entity_id' => $target->id, 'field' => 'status', 'new_value' => 'password_set_by_admin', 'changed_by' => $admin->id,
        ]);
    }
}
