<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\KhidmatguzarChangeLog;
use App\Models\User;
use App\Services\DutyListImportService;
use App\Services\EventPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 5 (Intelligent Import Center): diff-aware preview (NEW/UPDATED/
 * UNCHANGED buckets) and the Import History/Diff view. The underlying
 * blank-preserving update logic itself is Phase 1's — these tests cover
 * the new classification/diff-surfacing layer built on top of it.
 */
class ImportCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function rowData(string $its, string $venue, array $overrides = []): array
    {
        return array_merge([
            'h_year' => '1448', 'miqaat' => 'Test', 'its_id' => $its, 'full_name' => 'Person '.$its,
            'gender' => 'Male', 'age' => '30', 'category' => 'Test', 'idara' => 'Idara One', 'jamaat' => 'Jamaat One', 'jamiaat' => 'JM',
            'venue_name' => $venue, 'block_name' => 'block', 'day' => 'day', 'day_alias' => 'alias', 'seat' => 'A1',
            'status' => 'Allocated', 'allocated_user_name' => 'sys', 'allocated_date' => '2026-01-01',
            'deallocated_user_name' => '', 'deallocated_date' => '', 'scanned' => 'N',
            'acc_child_below_5yrs' => '0', 'multiple_acc_child_above_4yrs' => '0',
        ], $overrides);
    }

    public function test_preview_classifies_new_updated_and_unchanged_khidmatguzars(): void
    {
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);

        // Seed one existing person who will be UPDATED, and one who will
        // re-import identically (UNCHANGED) — matching every field the row
        // will provide, not just the one under test.
        Khidmatguzar::create(['its_id' => '60000001', 'full_name' => 'Will Update', 'gender' => 'Male', 'idara' => 'Old Idara', 'jamaat' => 'Jamaat One', 'jamiaat' => 'JM']);
        Khidmatguzar::create(['its_id' => '60000002', 'full_name' => 'Stays Same', 'gender' => 'Male', 'idara' => 'Same Idara', 'jamaat' => 'Jamaat One', 'jamiaat' => 'JM']);

        $rows = [
            ['row_number' => 2, 'data' => $this->rowData('60000001', 'DEPT-A', ['full_name' => 'Will Update', 'idara' => 'New Idara'])],
            ['row_number' => 3, 'data' => $this->rowData('60000002', 'DEPT-A', ['full_name' => 'Stays Same', 'idara' => 'Same Idara'])],
            ['row_number' => 4, 'data' => $this->rowData('60000003', 'DEPT-A', ['full_name' => 'Brand New'])],
        ];

        $preview = $service->buildPreview($session, $rows);

        $this->assertSame(1, $preview['new_khidmatguzars']);
        $this->assertSame(1, $preview['updated_khidmatguzars']);
        $this->assertSame(1, $preview['unchanged_khidmatguzars']);
        $this->assertCount(1, $preview['changed_khidmatguzars']);
        $this->assertSame('60000001', $preview['changed_khidmatguzars'][0]['its_id']);
        $this->assertArrayHasKey('idara', $preview['changed_khidmatguzars'][0]['changes']);
        $this->assertSame('Old Idara', $preview['changed_khidmatguzars'][0]['changes']['idara']['old']);
        $this->assertSame('New Idara', $preview['changed_khidmatguzars'][0]['changes']['idara']['new']);
    }

    public function test_preview_diff_matches_what_commit_actually_applies(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $service = app(DutyListImportService::class);

        Khidmatguzar::create(['its_id' => '60000010', 'full_name' => 'Person 60000010', 'gender' => 'Male', 'idara' => 'Idara One', 'jamaat' => 'Old Jamaat', 'jamiaat' => 'JM']);

        $rows = [['row_number' => 2, 'data' => $this->rowData('60000010', 'DEPT-B', ['jamaat' => 'New Jamaat'])]];
        $preview = $service->buildPreview($session, $rows);

        $this->assertSame(1, $preview['updated_khidmatguzars']);
        $this->assertSame('Old Jamaat', $preview['changed_khidmatguzars'][0]['changes']['jamaat']['old']);
        $this->assertSame('New Jamaat', $preview['changed_khidmatguzars'][0]['changes']['jamaat']['new']);

        $batch = $service->commit($session, $preview['valid'], $user, 'f.csv', 'csv', $preview);

        $this->assertSame(1, $batch->updated_khidmatguzars);
        $this->assertSame(0, $batch->unchanged_khidmatguzars);
        $this->assertSame('New Jamaat', Khidmatguzar::where('its_id', '60000010')->value('jamaat'));

        $log = KhidmatguzarChangeLog::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('jamaat', $log->field);
        $this->assertSame('Old Jamaat', $log->old_value);
        $this->assertSame('New Jamaat', $log->new_value);
    }

    public function test_import_batch_with_only_new_people_has_zero_updated(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        $service = app(DutyListImportService::class);

        $rows = [['row_number' => 2, 'data' => $this->rowData('60000020', 'DEPT-C')]];
        $preview = $service->buildPreview($session, $rows);
        $batch = $service->commit($session, $preview['valid'], $user, 'f.csv', 'csv', $preview);

        $this->assertSame(1, $batch->new_khidmatguzars);
        $this->assertSame(0, $batch->updated_khidmatguzars);
        $this->assertSame(0, $batch->unchanged_khidmatguzars);
    }

    public function test_diff_page_shows_changes_grouped_by_person(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);

        Khidmatguzar::create(['its_id' => '60000030', 'full_name' => 'Diff Person', 'gender' => 'Male']);
        $rows = [['row_number' => 2, 'data' => $this->rowData('60000030', 'DEPT-D', ['full_name' => 'Diff Person', 'gender' => 'Female'])]];
        $preview = $service->buildPreview($session, $rows);
        $batch = $service->commit($session, $preview['valid'], $admin, 'f.csv', 'csv', $preview);

        $response = $this->actingAs($admin)->get(route('sessions.imports.diff', [$session, $batch]));

        $response->assertOk()->assertSee('Diff Person')->assertSee('gender')->assertSee('Female');
    }

    public function test_diff_page_empty_state_when_no_changes(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);

        $rows = [['row_number' => 2, 'data' => $this->rowData('60000031', 'DEPT-E')]]; // brand new person, no changes to log
        $preview = $service->buildPreview($session, $rows);
        $batch = $service->commit($session, $preview['valid'], $admin, 'f.csv', 'csv', $preview);

        $response = $this->actingAs($admin)->get(route('sessions.imports.diff', [$session, $batch]));

        $response->assertOk()->assertSee('did not change any existing master-data field', false);
    }

    public function test_diff_page_rejects_batch_from_a_different_session(): void
    {
        $admin = $this->admin();
        $sessionA = $this->activeSession();
        $sessionB = $this->activeSession();
        $batch = ImportBatch::create(['duty_session_id' => $sessionA->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $this->actingAs($admin)->get(route('sessions.imports.diff', [$sessionB, $batch]))->assertNotFound();
    }

    public function test_viewer_cannot_access_import_diff(): void
    {
        $admin = $this->admin();
        $viewer = User::factory()->viewer()->create();
        $session = $this->activeSession();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $this->actingAs($viewer)->get(route('sessions.imports.diff', [$session, $batch]))->assertForbidden();
    }

    // =======================================================================
    // Import Center 2.0: multi-department call-out, Plan vs Import
    // comparison, and the global dashboard. All additive to the existing
    // preview/commit engine above — no assignment/attendance semantics
    // touched.
    // =======================================================================

    public function test_preview_flags_same_its_across_multiple_departments_without_treating_it_as_a_duplicate(): void
    {
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);

        $rows = [
            ['row_number' => 2, 'data' => $this->rowData('70000001', 'Department A', ['full_name' => 'Dual Person'])],
            ['row_number' => 3, 'data' => $this->rowData('70000001', 'Department B', ['full_name' => 'Dual Person'])],
            ['row_number' => 4, 'data' => $this->rowData('70000002', 'Department A', ['full_name' => 'Single Dept Person'])],
        ];

        $preview = $service->buildPreview($session, $rows);

        $this->assertCount(3, $preview['valid'], 'Both department rows for the same ITS are valid, not a duplicate.');
        $this->assertCount(1, $preview['multi_department_its']);
        $this->assertEquals('70000001', $preview['multi_department_its'][0]['its_id']);
        $this->assertEqualsCanonicalizing(['Department A', 'Department B'], $preview['multi_department_its'][0]['departments']);
        $this->assertSame(['Department A' => 2, 'Department B' => 1], $preview['department_counts']);
    }

    public function test_preview_department_counts_feed_plan_vs_import_comparison(): void
    {
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);
        Department::create(['name' => 'BETHAK KHIDMAT', 'normalized_key' => Department::normalize('BETHAK KHIDMAT')]);

        $rows = [
            ['row_number' => 2, 'data' => $this->rowData('70000010', 'BETHAK KHIDMAT')],
            ['row_number' => 3, 'data' => $this->rowData('70000011', 'BETHAK KHIDMAT')],
        ];
        $preview = $service->buildPreview($session, $rows);

        $planning = app(EventPlanningService::class);
        $comparison = $planning->planVsIncoming(
            [['name' => 'BETHAK KHIDMAT', 'planned' => 3]],
            $preview['department_counts']
        );

        $this->assertSame(['name' => 'BETHAK KHIDMAT', 'planned' => 3, 'incoming' => 2, 'gap' => -1], $comparison[0]);
    }

    public function test_import_center_dashboard_lists_recent_batches(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $service = app(DutyListImportService::class);
        $rows = [['row_number' => 2, 'data' => $this->rowData('70000020', 'DEPT-G')]];
        $preview = $service->buildPreview($session, $rows);
        $service->commit($session, $preview['valid'], $admin, 'center.csv', 'csv', $preview);

        $response = $this->actingAs($admin)->get(route('imports.index'));

        $response->assertOk()->assertSee('center.csv')->assertSee('Imported');
    }

    public function test_preview_screen_shows_bucket_counts(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        Department::create(['name' => 'DEPT-F', 'normalized_key' => Department::normalize('DEPT-F')]);

        $csv = "ITS ID,FullName,Gender,Age,Category,Idara,Jamaat,Jamiaat,Venue Name,Block Name,Day,Day Alias,Seat,Status,Allocated User Name,Allocated Date,DeAllocated User Name,DeAllocated Date,Scanned,Acc Child Below 5Yrs,Multiple Acc Child Above 4Yrs,HYear,Miqaat\n"
            ."60000040,New Person,Male,30,Test,I,J,JM,DEPT-F,B,D,DA,A1,Allocated,sys,2026-01-01,,,N,0,0,1448,Test\n";
        $file = UploadedFile::fake()->createWithContent('list.csv', $csv);

        $response = $this->actingAs($admin)->post(route('sessions.imports.store', $session), ['file' => $file]);

        $response->assertOk();
        $response->assertSee('New');
        $response->assertSee('Updated');
        $response->assertSee('Unchanged');
    }
}
