<?php

namespace Tests\Feature;

use App\Models\DutySession;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real-Operator-testing finding (ITS 40484563): GET /reports and
 * GET /analytics correctly 403 for an Operator (backend authorization was
 * already right), but the Operator still SAW navigation to both — the
 * Dashboard "Quick Actions" cards for Analytics and Reports had no
 * permission gate at all (sidebar-nav/bottom-nav already gated them
 * correctly). Also found and fixed: several sessions/show.blade.php action
 * buttons were gated by the wrong concept entirely (canManageSessions(),
 * a session-lifecycle helper) instead of the actual route permission
 * (view_live_attendance / view_attendance_history / preview_import /
 * view_planning), which could hide correct actions from an Operator OR
 * show a Viewer a link to something they can't reach.
 */
class NavigationPermissionVisibilityTest extends TestCase
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

    private function grant(User $user, string $permission, string $effect): void
    {
        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => $permission, 'effect' => $effect,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
    }

    // ===================================================================
    // Backend authorization (already correct — confirm it stays correct)
    // ===================================================================

    public function test_operator_cannot_access_reports(): void
    {
        $this->actingAs($this->operator())->get(route('reports.index'))->assertForbidden();
    }

    public function test_operator_cannot_access_analytics(): void
    {
        $this->actingAs($this->operator())->get(route('analytics.overview'))->assertForbidden();
    }

    // ===================================================================
    // Navigation visibility — the actual bug
    // ===================================================================

    public function test_operator_does_not_see_reports_navigation_anywhere(): void
    {
        $operator = $this->operator();

        $dashboard = $this->actingAs($operator)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee(route('reports.index'), false);

        $sessions = $this->actingAs($operator)->get(route('sessions.index'));
        $sessions->assertOk();
        $sessions->assertDontSee(route('reports.index'), false);
    }

    public function test_operator_does_not_see_analytics_navigation_anywhere(): void
    {
        $operator = $this->operator();

        $dashboard = $this->actingAs($operator)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee(route('analytics.overview'), false);
    }

    public function test_admin_sees_reports_and_analytics_navigation(): void
    {
        $admin = $this->admin();

        $dashboard = $this->actingAs($admin)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee(route('reports.index'), false);
        $dashboard->assertSee(route('analytics.overview'), false);
    }

    public function test_operator_still_sees_navigation_it_is_actually_allowed(): void
    {
        $operator = $this->operator();

        $dashboard = $this->actingAs($operator)->get(route('dashboard'));
        $dashboard->assertOk();
        // Operator has view_sessions and view_directory — those cards must
        // remain visible; this proves the fix hides only what's disallowed,
        // not everything.
        $dashboard->assertSee(route('sessions.index'), false);
        $dashboard->assertSee(route('analytics.profile-search'), false);
    }

    public function test_user_specific_allow_override_makes_reports_navigation_appear(): void
    {
        $operator = $this->operator();
        $this->grant($operator, 'view_reports', 'allow');

        $dashboard = $this->actingAs($operator)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee(route('reports.index'), false);

        // And the route itself must now actually work, not just the link.
        $this->actingAs($operator)->get(route('reports.index'))->assertOk();
    }

    public function test_build_reports_alone_does_not_produce_a_reports_navigation_link_that_403s(): void
    {
        // Confirmed inconsistency: nav used to check view_reports OR
        // build_reports, but /reports itself requires view_reports only.
        // A user with build_reports=allow, view_reports=deny would see a
        // Reports link that 403s. Nav must now match the entry route's
        // actual permission.
        $operator = $this->operator();
        $this->grant($operator, 'build_reports', 'allow');

        $dashboard = $this->actingAs($operator)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee(route('reports.index'), false);

        $this->actingAs($operator)->get(route('reports.index'))->assertForbidden();
        // build_reports itself remains fully intact and independently authorized.
        $this->actingAs($operator)->get(route('reports.builder'))->assertOk();
    }

    public function test_user_specific_deny_override_hides_navigation_even_for_a_role_that_normally_has_it(): void
    {
        $viewer = User::factory()->viewer()->create(); // viewer role_defaults includes view_analytics
        $this->grant($viewer, 'view_analytics', 'deny');

        $dashboard = $this->actingAs($viewer)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee(route('analytics.overview'), false);

        $this->actingAs($viewer)->get(route('analytics.overview'))->assertForbidden();
    }

    public function test_direct_url_stays_protected_regardless_of_navigation_visibility(): void
    {
        $operator = $this->operator();

        // Navigation is hidden (proven above) AND the direct URL is still
        // rejected — both layers, independently.
        $this->actingAs($operator)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($operator)->get(route('analytics.alerts'))->assertForbidden();
    }

    // ===================================================================
    // Session-management action buttons — wrong-permission-concept bug
    // ===================================================================

    public function test_operator_sees_live_attendance_button_on_an_active_session(): void
    {
        $operator = $this->operator();
        $session = DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        // Before the fix this was gated by canManageSessions() (create/
        // activate/close_sessions), which Operator does NOT have — hiding
        // a button an Operator (who has view_live_attendance) should see.
        $response = $this->actingAs($operator)->get(route('sessions.show', $session));
        $response->assertOk();
        $response->assertSee(route('attendance.shell.live', $session), false);
    }

    public function test_operator_sees_attendance_list_button_on_a_session(): void
    {
        $operator = $this->operator();
        $session = DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        $response = $this->actingAs($operator)->get(route('sessions.show', $session));
        $response->assertOk();
        $response->assertSee(route('attendance.shell.list', $session), false);
    }

    public function test_operator_does_not_see_import_duty_list_button(): void
    {
        $operator = $this->operator(); // operator role_defaults has no preview_import
        $session = DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        $response = $this->actingAs($operator)->get(route('sessions.show', $session));
        $response->assertOk();
        $response->assertDontSee(route('sessions.imports.create', $session), false);
    }

    public function test_viewer_does_not_see_event_planning_link_but_has_the_permission_to_reach_it_directly(): void
    {
        $viewer = User::factory()->viewer()->create();

        // Before the fix, the Event Planning icon on Sessions was gated by
        // canManageSessions() — a Viewer (who HAS view_planning per role
        // defaults) could not see the entry point despite being allowed to
        // use the page directly. After the fix it must be visible.
        $response = $this->actingAs($viewer)->get(route('sessions.index'));
        $response->assertOk();
        $response->assertSee(route('planning.index'), false);

        $this->actingAs($viewer)->get(route('planning.index'))->assertOk();
    }
}
