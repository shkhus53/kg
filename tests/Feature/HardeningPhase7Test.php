<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 7 (Hardening / Performance / Accessibility): departmentDetailReport()
 * query-count regression, khidmatguzars.full_name index, icon-button
 * accessibility, and bulk-present confirmation parity.
 */
class HardeningPhase7Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    public function test_khidmatguzars_full_name_index_exists(): void
    {
        $indexes = Schema::getIndexes('khidmatguzars');
        $columns = collect($indexes)->pluck('columns')->flatten()->all();

        $this->assertContains('full_name', $columns);
    }

    /**
     * Before the refactor this ran ~3 queries PER department (stats,
     * assignments+eager-loads, extra-presents) in a loop — genuinely O(n).
     * Verifies the query count is now constant (O(1)): identical whether
     * 2 or 6 departments are requested.
     */
    public function test_department_detail_report_query_count_does_not_scale_with_department_count(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $seedDepartments = function (int $count) use ($session, $batch) {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $dept = Department::create(['name' => 'DEPT-'.uniqid(), 'normalized_key' => Department::normalize('DEPT-'.uniqid())]);
                $ids[] = $dept->id;
                $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Person']);
                DutyAssignment::create([
                    'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
                    'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
                    'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
                ]);
            }

            return $ids;
        };

        $twoDeptIds = $seedDepartments(2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReportService::class)->departmentDetailReport($twoDeptIds, now()->subDays(30)->format('Y-m-d'), now()->format('Y-m-d'), null);
        $queryCountForTwo = count(DB::getQueryLog());
        DB::flushQueryLog();

        $sixDeptIds = array_merge($twoDeptIds, $seedDepartments(4)); // seeding happens with the log still enabled from above — flush again before measuring

        DB::flushQueryLog();
        $report = app(ReportService::class)->departmentDetailReport($sixDeptIds, now()->subDays(30)->format('Y-m-d'), now()->format('Y-m-d'), null);
        $queryCountForSix = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertSame($queryCountForTwo, $queryCountForSix, 'query count must stay constant as department count grows (was O(n) before the refactor)');
        $this->assertCount(6, $report['sections']);
    }

    public function test_department_detail_report_data_correct_after_refactor(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $deptA = Department::create(['name' => 'REFACTOR-A', 'normalized_key' => Department::normalize('REFACTOR-A')]);
        $deptB = Department::create(['name' => 'REFACTOR-B', 'normalized_key' => Department::normalize('REFACTOR-B')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $kgA = Khidmatguzar::create(['its_id' => '70000001', 'full_name' => 'A Person']);
        $aA = DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kgA->id, 'department_id' => $deptA->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-a', 'venue_name_raw' => $deptA->name, 'full_name_snapshot' => $kgA->full_name]);
        $kgB = Khidmatguzar::create(['its_id' => '70000002', 'full_name' => 'B Person']);
        DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kgB->id, 'department_id' => $deptB->id, 'source_row_number' => 3, 'assignment_fingerprint' => 'fp-b', 'venue_name_raw' => $deptB->name, 'full_name_snapshot' => $kgB->full_name]);

        app(AttendanceService::class)->markPresent($session, $aA->id, $admin);
        // B stays pending

        $report = app(ReportService::class)->departmentDetailReport([$deptA->id, $deptB->id], now()->subDays(30)->format('Y-m-d'), now()->format('Y-m-d'), null);

        $sectionA = $report['sections']->firstWhere('department.id', $deptA->id);
        $sectionB = $report['sections']->firstWhere('department.id', $deptB->id);

        $this->assertSame(1, $sectionA['scheduled']);
        $this->assertSame(1, $sectionA['present']);
        $this->assertSame(100.0, $sectionA['rate']);

        $this->assertSame(1, $sectionB['scheduled']);
        $this->assertSame(0, $sectionB['present']);
        $this->assertSame(1, $sectionB['pending']);
        $this->assertSame(0.0, $sectionB['rate']);

        // Cross-department isolation: department A's data never leaks into B's section.
        $this->assertSame('A Person', $sectionA['assignments']->first()->khidmatguzar->full_name);
        $this->assertSame('B Person', $sectionB['assignments']->first()->khidmatguzar->full_name);
    }

    public function test_page_header_back_link_has_aria_label(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();

        $response = $this->actingAs($admin)->get(route('sessions.show', $session));

        $response->assertOk()->assertSee('aria-label="Back"', false);
    }

    public function test_dashboard_user_menu_has_aria_label(): void
    {
        // Dashboard has no back-url — it renders the user-menu (hamburger) instead.
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk()->assertSee('aria-label="Account menu"', false);
    }

    public function test_bulk_present_form_has_confirmation(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = Department::create(['name' => 'CONFIRM-DEPT', 'normalized_key' => Department::normalize('CONFIRM-DEPT')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '70000010', 'full_name' => 'Multi Person']);

        DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-c1', 'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name]);
        DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id, 'source_row_number' => 3, 'assignment_fingerprint' => 'fp-c2', 'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name]);

        $response = $this->actingAs($admin)->get(route('attendance.shell.live', [$session, 'its' => '70000010']));

        $response->assertOk();
        $response->assertSee('Multiple Assignments Found');
        $response->assertSee('onsubmit="return confirm(', false);
    }
}
