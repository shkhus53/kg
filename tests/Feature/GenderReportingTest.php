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
use Tests\TestCase;

class GenderReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAssignment(DutySession $session, Department $dept, ImportBatch $batch, ?string $gender, string $status): DutyAssignment
    {
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Gender Test', 'gender' => $gender]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 1, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'gender_snapshot' => $gender, 'current_status' => $status,
        ]);
    }

    public function test_gender_buckets_reconcile_with_overall_totals(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Gender Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'GENDEP', 'normalized_key' => Department::normalize('GENDEP')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'g.csv', 'file_type' => 'csv', 'status' => 'completed']);

        // M/Male variants, F/Female variants, NULL — mirrors real data mix.
        $this->makeAssignment($session, $dept, $batch, 'M', 'present');
        $this->makeAssignment($session, $dept, $batch, 'Male', 'absent');
        $this->makeAssignment($session, $dept, $batch, 'F', 'present');
        $this->makeAssignment($session, $dept, $batch, 'Female', 'pending');
        $this->makeAssignment($session, $dept, $batch, null, 'present');

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(5, $report['scheduled']);
        $this->assertSame(3, $report['present']);
        $this->assertSame(1, $report['absent']);
        $this->assertSame(1, $report['pending']);

        $g = $report['genderBreakdown'];

        // M+Male collapse to one Male bucket of 2; F+Female collapse to Female bucket of 2; NULL -> Unknown 1.
        $this->assertSame(2, $g['scheduled']['male']);
        $this->assertSame(2, $g['scheduled']['female']);
        $this->assertSame(1, $g['scheduled']['unknown']);
        $this->assertSame($report['scheduled'], $g['scheduled']['male'] + $g['scheduled']['female'] + $g['scheduled']['unknown']);

        // Present + Absent + Pending = Scheduled, within each gender bucket.
        foreach (['male', 'female', 'unknown'] as $bucket) {
            $this->assertSame(
                $g['scheduled'][$bucket],
                $g['present'][$bucket] + $g['absent'][$bucket] + $g['pending'][$bucket],
                "reconciliation failed for {$bucket}"
            );
        }

        $this->assertSame($report['present'], $g['present']['male'] + $g['present']['female'] + $g['present']['unknown']);
        $this->assertSame($report['absent'], $g['absent']['male'] + $g['absent']['female'] + $g['absent']['unknown']);
        $this->assertSame($report['pending'], $g['pending']['male'] + $g['pending']['female'] + $g['pending']['unknown']);
    }

    public function test_null_gender_is_not_excluded_it_is_unknown(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Unknown Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'UNKDEP', 'normalized_key' => Department::normalize('UNKDEP')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'u.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $this->makeAssignment($session, $dept, $batch, null, 'pending');
        $this->makeAssignment($session, $dept, $batch, 'weird-value', 'pending');

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(2, $report['genderBreakdown']['scheduled']['unknown']);
        $this->assertSame(0, $report['genderBreakdown']['scheduled']['male']);
        $this->assertSame(0, $report['genderBreakdown']['scheduled']['female']);
    }

    public function test_historical_reporting_uses_gender_snapshot_not_master_gender(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Snapshot Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'SNAPDEP', 'normalized_key' => Department::normalize('SNAPDEP')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 's.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $kg = Khidmatguzar::create(['its_id' => '77777777', 'full_name' => 'Snapshot Person', 'gender' => 'Male']);
        $assignment = DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 1, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
            'gender_snapshot' => 'Male', 'current_status' => 'present',
        ]);

        // Master record's gender changes later (e.g. data correction) — historical
        // session reporting must NOT be rewritten by this.
        $kg->update(['gender' => 'Female']);

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(1, $report['genderBreakdown']['scheduled']['male']);
        $this->assertSame(0, $report['genderBreakdown']['scheduled']['female']);
        $this->assertSame('Male', $assignment->fresh()->gender_snapshot);
    }

    public function test_department_breakdown_includes_gender_split(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'Dept Gender Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'DEPTG', 'normalized_key' => Department::normalize('DEPTG')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'd.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $this->makeAssignment($session, $dept, $batch, 'M', 'present');
        $this->makeAssignment($session, $dept, $batch, 'F', 'present');

        $report = app(ReportService::class)->sessionReport($session);
        $deptRow = $report['departments']->firstWhere('department_id', $dept->id);

        $this->assertNotNull($deptRow->genderBreakdown);
        $this->assertSame(1, $deptRow->genderBreakdown['scheduled']['male']);
        $this->assertSame(1, $deptRow->genderBreakdown['scheduled']['female']);
    }

    public function test_khidmatguzar_report_gender_breakdown(): void
    {
        $user = User::factory()->admin()->create();
        $session = DutySession::create(['name' => 'KG Report Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        $dept = Department::create(['name' => 'KGDEP', 'normalized_key' => Department::normalize('KGDEP')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'k.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $assignment = $this->makeAssignment($session, $dept, $batch, 'Female', 'present');

        $report = app(ReportService::class)->khidmatguzarReport($assignment->khidmatguzar);

        $this->assertSame(1, $report['genderBreakdown']['scheduled']['female']);
        $this->assertSame(1, $report['genderBreakdown']['present']['female']);
    }

    public function test_extra_present_remains_outside_scheduled_denominator_with_gender_present(): void
    {
        [$session, $assignment, $user] = [
            DutySession::create(['name' => 'Extra Gender Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']),
            null,
            User::factory()->admin()->create(),
        ];
        $dept = Department::create(['name' => 'EXGDEP', 'normalized_key' => Department::normalize('EXGDEP')]);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $user->id, 'original_filename' => 'e.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $assignment = $this->makeAssignment($session, $dept, $batch, 'M', 'present');

        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Extra Gender', 'gender' => 'F']);
        app(AttendanceService::class)->markExtraPresentKnown($session, $extraKg, $dept, $user);

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(1, $report['scheduled']);
        $this->assertSame(1, $report['extraCount']);
        $this->assertSame(1, $report['genderBreakdown']['scheduled']['male']);
        $this->assertSame(0, $report['genderBreakdown']['scheduled']['female']);
    }
}
