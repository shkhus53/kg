<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\MasterDataChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1.2/P1.5 remediation: the central Audit Log previously covered only
 * attendance present/absent (incl. corrections), session reopens, and
 * offline sync issues. Import history and master-data changes both have
 * genuine, append-only persisted sources (ImportBatch, MasterDataChangeLog)
 * and are now surfaced centrally too — reusing those tables directly, never
 * fabricating a new event. Permission-change history is deliberately NOT
 * added here: user_permission_overrides stores only the current row per
 * (user, permission), not a history of past transitions, so there is no
 * genuine historical data to surface without inventing it.
 */
class AuditLogCentralCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_import_history_is_surfaced_in_the_central_audit_log(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'Audit Import Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $admin->id,
            'original_filename' => 'audit-test-list.csv', 'file_type' => 'csv', 'status' => 'completed',
            'total_rows' => 10, 'valid_rows' => 9,
        ]);

        $response = $this->actingAs($admin)->get(route('audit.index'));

        $response->assertOk();
        $response->assertSee('audit-test-list.csv');
        $response->assertSee('Import');
    }

    public function test_import_history_filters_by_action(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'Audit Import Session 2', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $admin->id,
            'original_filename' => 'only-shown-when-filtered.csv', 'file_type' => 'csv', 'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('audit.index', ['action' => 'import']));
        $response->assertOk()->assertSee('only-shown-when-filtered.csv');

        $response = $this->actingAs($admin)->get(route('audit.index', ['action' => 'present']));
        $response->assertOk()->assertDontSee('only-shown-when-filtered.csv');
    }

    public function test_master_data_change_history_is_surfaced_in_the_central_audit_log(): void
    {
        $admin = $this->admin();
        $dept = Department::create(['name' => 'Audit Dept', 'normalized_key' => Department::normalize('Audit Dept')]);

        MasterDataChangeLog::create([
            'entity_type' => 'department', 'entity_id' => $dept->id,
            'field' => 'name', 'old_value' => 'Old Dept Name', 'new_value' => 'Audit Dept',
            'changed_by' => $admin->id, 'changed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('audit.index'));

        $response->assertOk();
        $response->assertSee('Old Dept Name');
        $response->assertSee('Master Data Change');
    }

    public function test_master_data_change_history_filters_by_action(): void
    {
        $admin = $this->admin();
        $dept = Department::create(['name' => 'Audit Dept 2', 'normalized_key' => Department::normalize('Audit Dept 2')]);

        MasterDataChangeLog::create([
            'entity_type' => 'department', 'entity_id' => $dept->id,
            'field' => 'is_active', 'old_value' => '1', 'new_value' => '0',
            'changed_by' => $admin->id, 'changed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('audit.index', ['action' => 'master_data_change']));
        $response->assertOk()->assertSee('is_active');

        $response = $this->actingAs($admin)->get(route('audit.index', ['action' => 'absent']));
        $response->assertOk()->assertDontSee('is_active changed');
    }

    public function test_master_data_change_is_excluded_when_a_session_or_department_filter_narrows_the_view(): void
    {
        $admin = $this->admin();
        $dept = Department::create(['name' => 'Audit Dept 3', 'normalized_key' => Department::normalize('Audit Dept 3')]);
        $session = DutySession::create(['name' => 'Unrelated Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        MasterDataChangeLog::create([
            'entity_type' => 'venue', 'entity_id' => 99,
            'field' => 'address', 'old_value' => 'A', 'new_value' => 'B',
            'changed_by' => $admin->id, 'changed_at' => now(),
        ]);

        // Master-data changes have no session/department dimension of their
        // own, so they must not appear once one of those filters is active
        // (matches the existing pattern for reopen/sync-issue events).
        $response = $this->actingAs($admin)->get(route('audit.index', ['session_id' => $session->id]));
        $response->assertOk()->assertDontSee('address changed');

        $response = $this->actingAs($admin)->get(route('audit.index', ['department_id' => $dept->id]));
        $response->assertOk()->assertDontSee('address changed');
    }

    public function test_no_historical_audit_records_are_mutated_by_viewing_the_log(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'Immutability Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $batch = ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $admin->id,
            'original_filename' => 'immutable.csv', 'file_type' => 'csv', 'status' => 'completed',
        ]);
        $originalCreatedAt = $batch->created_at->toIso8601String();

        $this->actingAs($admin)->get(route('audit.index'))->assertOk();
        $this->actingAs($admin)->get(route('audit.index', ['action' => 'import']))->assertOk();

        $this->assertSame($originalCreatedAt, $batch->fresh()->created_at->toIso8601String());
    }
}
