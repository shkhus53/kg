<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\MasterDataChangeLog;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The flexible permission system: role gives the default, a per-user
 * override (allow/deny) always takes precedence, Admin is always fully
 * unrestricted. Every check here is a direct route hit (GET/POST), not a
 * Blade-visibility check — hiding a button is not authorization.
 */
class PermissionOverridesTest extends TestCase
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

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(string $name = 'Department A'): Department
    {
        return Department::firstOrCreate(['normalized_key' => Department::normalize($name)], ['name' => $name]);
    }

    private function assignment(DutySession $session, Department $dept, string $its, string $status = 'pending'): DutyAssignment
    {
        $admin = User::where('role', 'admin')->first() ?? $this->admin();
        $batch = ImportBatch::firstOrCreate(
            ['duty_session_id' => $session->id, 'original_filename' => 'f.csv'],
            ['uploaded_by' => $admin->id, 'file_type' => 'csv', 'status' => 'completed']
        );
        $kg = Khidmatguzar::firstOrCreate(['its_id' => $its], ['full_name' => 'Person '.$its]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 1, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => $status,
        ]);
    }

    private function grant(User $user, string $permission, string $effect, ?User $actor = null): void
    {
        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => $permission, 'effect' => $effect,
            'created_by' => ($actor ?? $user)->id, 'updated_by' => ($actor ?? $user)->id,
        ]);
    }

    // ===================================================================
    // ADMIN: full access, cannot be restricted by a user-level deny
    // ===================================================================

    public function test_admin_has_full_access_regardless_of_role_default(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('reports.builder'))->assertOk();
        $this->actingAs($admin)->get(route('sessions.create'))->assertOk();
    }

    public function test_explicit_deny_cannot_restrict_an_admin(): void
    {
        $admin = $this->admin();
        $this->grant($admin, 'manage_users', 'deny');

        $this->assertTrue($admin->hasPermission('manage_users'), 'Admin must remain unrestricted even with a DENY override row present.');
        $this->actingAs($admin)->get(route('users.index'))->assertOk();
    }

    // ===================================================================
    // OPERATOR DEFAULTS
    // ===================================================================

    public function test_operator_default_permissions(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '10000001');

        $this->actingAs($operator)->get(route('dashboard'))->assertOk();
        $this->actingAs($operator)->get(route('sessions.index'))->assertOk();
        $this->actingAs($operator)->get(route('attendance.shell.live', $session))->assertOk();
        $this->actingAs($operator)->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]])->assertRedirect();
        $this->actingAs($operator)->get(route('analytics.profile-search'))->assertOk();
    }

    public function test_operator_default_denials(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();

        $this->actingAs($operator)->get(route('imports.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($operator)->get(route('planning.create'))->assertForbidden();
        $this->actingAs($operator)->get(route('masters.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('audit.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('sessions.create'))->assertForbidden();
        $this->actingAs($operator)->post(route('sessions.activate', $session))->assertForbidden();
        $this->actingAs($operator)->get(route('analytics.overview'))->assertForbidden();
    }

    // ===================================================================
    // VIEWER DEFAULTS
    // ===================================================================

    public function test_viewer_default_permissions(): void
    {
        $viewer = $this->viewer();
        $session = $this->activeSession();

        $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
        $this->actingAs($viewer)->get(route('sessions.index'))->assertOk();
        $this->actingAs($viewer)->get(route('attendance.shell.live', $session))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.overview'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.planning'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.alerts'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.summary'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.profile-search'))->assertOk();
    }

    public function test_viewer_default_denials(): void
    {
        $viewer = $this->viewer();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '20000001');

        $this->actingAs($viewer)->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]])->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.absent', $session), ['assignment_id' => $assignment->id])->assertForbidden();
        $this->actingAs($viewer)->get(route('imports.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reports.builder'))->assertForbidden();
        $this->actingAs($viewer)->get(route('sessions.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('planning.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('masters.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('users.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('audit.index'))->assertForbidden();
    }

    // ===================================================================
    // USER OVERRIDE: ALLOW grants an otherwise-unavailable permission
    // ===================================================================

    public function test_operator_granted_import_permissions_can_perform_the_import_workflow(): void
    {
        $admin = $this->admin();
        $ahmed = $this->operator();
        $otherOperator = $this->operator();
        $session = $this->activeSession();

        $this->grant($ahmed, 'import_duty_list', 'allow', $admin);
        $this->grant($ahmed, 'preview_import', 'allow', $admin);
        $this->grant($ahmed, 'commit_import', 'allow', $admin);

        $this->actingAs($ahmed)->get(route('imports.index'))->assertOk();
        $this->actingAs($ahmed)->get(route('sessions.imports.create', $session))->assertOk();

        // The other Operator, with no overrides, remains blocked.
        $this->actingAs($otherOperator)->get(route('imports.index'))->assertForbidden();
        $this->actingAs($otherOperator)->get(route('sessions.imports.create', $session))->assertForbidden();
    }

    public function test_viewer_granted_mark_attendance_can_mark_attendance(): void
    {
        $admin = $this->admin();
        $viewer = $this->viewer();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '30000001');

        $this->grant($viewer, 'mark_attendance', 'allow', $admin);

        $this->actingAs($viewer)->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]])
            ->assertRedirect();
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_operator_granted_reports_can_access_reports(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();
        $this->grant($operator, 'view_reports', 'allow', $admin);

        $this->actingAs($operator)->get(route('reports.index'))->assertOk();
    }

    // ===================================================================
    // USER OVERRIDE: DENY removes an otherwise-available permission
    // ===================================================================

    public function test_operator_denied_extra_present_can_no_longer_add_extra_present(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();

        $this->assertTrue($operator->fresh()->hasPermission('mark_extra_present'), 'Sanity check: Operator has this by role default before the deny.');

        $this->grant($operator, 'mark_extra_present', 'deny', $admin);

        $this->actingAs($operator->fresh())->post(route('attendance.extra-present', $session), [
            'its' => '99999999', 'full_name' => 'Walk In', 'gender' => 'Male', 'department_id' => $dept->id,
        ])->assertForbidden();
    }

    // ===================================================================
    // OVERRIDE RESET: allow -> inherit restores the role default
    // ===================================================================

    public function test_removing_an_override_restores_the_role_default(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();

        $this->grant($operator, 'view_reports', 'allow', $admin);
        $this->assertTrue($operator->fresh()->hasPermission('view_reports'));

        $this->actingAs($admin)->put(route('users.permissions.update', $operator), [
            'overrides' => array_fill_keys(PermissionRegistry::all(), 'inherit'),
        ]);

        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $operator->id, 'permission' => 'view_reports']);
        $this->assertFalse($operator->fresh()->hasPermission('view_reports'), 'Resetting to inherit must restore the Operator role default (no view_reports).');
    }

    // ===================================================================
    // PRECEDENCE: explicit override beats role default in both directions
    // ===================================================================

    public function test_explicit_deny_overrides_a_role_granted_default(): void
    {
        $operator = $this->operator();
        $this->assertTrue($operator->hasPermission('mark_attendance'));

        $this->grant($operator, 'mark_attendance', 'deny');

        $this->assertFalse($operator->fresh()->hasPermission('mark_attendance'));
    }

    public function test_explicit_allow_overrides_a_role_absent_default(): void
    {
        $viewer = $this->viewer();
        $this->assertFalse($viewer->hasPermission('manage_planning'));

        $this->grant($viewer, 'manage_planning', 'allow');

        $this->assertTrue($viewer->fresh()->hasPermission('manage_planning'));
    }

    // ===================================================================
    // ADMIN SAFETY: permission overrides cannot be set on an Admin account
    // ===================================================================

    public function test_permission_overrides_cannot_be_set_on_an_admin_account(): void
    {
        $actingAdmin = $this->admin();
        $targetAdmin = $this->admin();

        $response = $this->actingAs($actingAdmin)->put(route('users.permissions.update', $targetAdmin), [
            'overrides' => ['manage_users' => 'deny'],
        ]);

        $response->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $targetAdmin->id]);
    }

    // ===================================================================
    // SECURITY: authorization failures do not partially mutate data
    // ===================================================================

    public function test_correction_permission_blocks_the_whole_batch_atomically(): void
    {
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $freshAssignment = $this->assignment($session, $dept, '40000001', 'pending');
        $alreadyAbsent = $this->assignment($session, $dept, '40000002', 'absent');

        // Operator has mark_attendance (fresh present is fine) but not
        // correct_attendance (absent -> present is a correction).
        $response = $this->actingAs($operator)->post(route('attendance.present', $session), [
            'assignment_ids' => [$freshAssignment->id, $alreadyAbsent->id],
        ]);

        $response->assertForbidden();
        $this->assertSame('pending', $freshAssignment->fresh()->current_status, 'A 403 on the batch must not leave the fresh assignment half-mutated.');
        $this->assertSame('absent', $alreadyAbsent->fresh()->current_status);
    }

    public function test_operator_granted_correct_attendance_can_correct_absent_to_present(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();
        $session = $this->activeSession();
        $dept = $this->department();
        $assignment = $this->assignment($session, $dept, '41000001', 'absent');

        $this->grant($operator, 'correct_attendance', 'allow', $admin);

        $this->actingAs($operator)->post(route('attendance.present', $session), ['assignment_ids' => [$assignment->id]])
            ->assertRedirect();
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    // ===================================================================
    // AUDIT
    // ===================================================================

    public function test_permission_change_is_audited(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();

        $overrides = array_fill_keys(PermissionRegistry::all(), 'inherit');
        $overrides['import_duty_list'] = 'allow';

        $this->actingAs($admin)->put(route('users.permissions.update', $operator), ['overrides' => $overrides]);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'user_permission', 'entity_id' => $operator->id,
            'field' => 'permission_import_duty_list', 'old_value' => 'inherit', 'new_value' => 'allow', 'changed_by' => $admin->id,
        ]);
    }

    public function test_audit_never_stores_a_password_value(): void
    {
        $admin = $this->admin();
        $operator = $this->operator();

        $this->grant($operator, 'view_reports', 'allow', $admin);

        $logs = MasterDataChangeLog::where('entity_type', 'user_permission')->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('password', mb_strtolower((string) $log->field));
        }
    }

    // ===================================================================
    // DIRECT URL ACCESS: guest redirected, not a raw exception
    // ===================================================================

    public function test_guest_hitting_a_protected_route_is_redirected_to_login(): void
    {
        $this->get(route('reports.builder'))->assertRedirect(route('login'));
        $this->post(route('sessions.store'), [])->assertRedirect(route('login'));
    }
}
