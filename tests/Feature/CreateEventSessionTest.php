<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\Miqaat;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 2 of the 3.0 evolution: Create Event Session, anchored to the
 * Phase 1 master-data hierarchy (Miqaat -> Event -> Venue), while every
 * existing DutySession behavior (lifecycle, assignments, attendance,
 * audit, legacy sessions with no structured identity) keeps working
 * unchanged.
 */
class CreateEventSessionTest extends TestCase
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
        return User::factory()->create(['role' => 'viewer']);
    }

    private function miqaat(bool $active = true): Miqaat
    {
        return Miqaat::create(['name' => 'Istefada Ilmiya', 'normalized_key' => Miqaat::normalize('Istefada Ilmiya'), 'active' => $active]);
    }

    private function event(Miqaat $miqaat, bool $active = true): Event
    {
        return Event::create([
            'miqaat_id' => $miqaat->id,
            'name' => 'Qadambosi Bethak (Mardo)',
            'normalized_key' => Event::normalize('Qadambosi Bethak (Mardo)'),
            'family' => 'Qadambosi Bethak',
            'active' => $active,
        ]);
    }

    private function venue(bool $active = true): Venue
    {
        return Venue::create(['name' => 'Saifee Masjid', 'normalized_key' => Venue::normalize('Saifee Masjid'), 'city' => 'Mumbai', 'active' => $active]);
    }

    public function test_admin_can_create_an_event_session(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $response = $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id,
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'date' => '2026-10-01',
        ]);

        $session = DutySession::latest('id')->first();
        $response->assertRedirect(route('sessions.show', $session));

        $this->assertSame($miqaat->id, $session->miqaat_id);
        $this->assertSame($event->id, $session->event_id);
        $this->assertSame($venue->id, $session->venue_id);
        $this->assertSame('draft', $session->status);
        $this->assertTrue($session->hasStructuredIdentity());
        // Legacy free-text fields are still populated for backward compatibility.
        $this->assertSame('Istefada Ilmiya', $session->miqaat);
        $this->assertNotEmpty($session->name);
    }

    /**
     * Superseded by the granular permission system: session creation is
     * now Admin-only by default (create_sessions), so a plain Operator is
     * rejected here unless explicitly granted — see
     * PermissionOverridesTest for the grant/override path.
     */
    public function test_operator_without_a_grant_cannot_create_an_event_session(): void
    {
        $operator = $this->operator();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $this->actingAs($operator)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('duty_sessions', 0);
    }

    public function test_viewer_is_forbidden_from_creating_a_session(): void
    {
        $viewer = $this->viewer();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $this->actingAs($viewer)->get(route('sessions.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('duty_sessions', 0);
    }

    public function test_missing_miqaat_is_rejected(): void
    {
        $admin = $this->admin();
        $event = $this->event($this->miqaat());
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('miqaat_id');
    }

    public function test_missing_event_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('event_id');
    }

    public function test_event_belonging_to_a_different_miqaat_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaatA = $this->miqaat();
        $miqaatB = Miqaat::create(['name' => 'Miqaat B', 'normalized_key' => Miqaat::normalize('Miqaat B')]);
        $eventUnderB = $this->event($miqaatB);
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaatA->id, 'event_id' => $eventUnderB->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('event_id');

        $this->assertDatabaseCount('duty_sessions', 0);
    }

    public function test_inactive_miqaat_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat(active: false);
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('miqaat_id');
    }

    public function test_inactive_event_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat, active: false);
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('event_id');
    }

    public function test_inactive_venue_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue(active: false);

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('venue_id');
    }

    public function test_nonexistent_venue_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => 999999, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('venue_id');
    }

    public function test_open_duplicate_event_session_is_rejected_but_closed_one_is_not_blocking(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertRedirect();
        $this->assertDatabaseCount('duty_sessions', 1);

        // Same identity again while the first is still open (draft) -> rejected.
        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertSessionHasErrors('event_id');
        $this->assertDatabaseCount('duty_sessions', 1);

        // Close the first, then the same identity is allowed again (a legitimate re-run).
        DutySession::first()->update(['status' => 'closed']);

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ])->assertRedirect();
        $this->assertDatabaseCount('duty_sessions', 2);
    }

    public function test_existing_session_lifecycle_and_assignment_flow_is_unaffected(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        $this->actingAs($admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id, 'date' => '2026-10-01',
        ]);
        $session = DutySession::latest('id')->first();

        $this->actingAs($admin)->post(route('sessions.activate', $session))->assertRedirect();
        $this->assertSame('active', $session->fresh()->status);

        $department = Department::create(['name' => 'Test Dept', 'normalized_key' => Department::normalize('Test Dept')]);
        $khidmatguzar = Khidmatguzar::create(['its_id' => '30999999', 'full_name' => 'Test Person']);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'x.csv', 'file_type' => 'csv', 'status' => 'completed', 'total_rows' => 1, 'valid_rows' => 1]);
        DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $khidmatguzar->id,
            'department_id' => $department->id, 'source_row_number' => 1, 'venue_name_raw' => $department->name,
            'assignment_fingerprint' => 'evt-test-fp', 'current_status' => 'pending', 'full_name_snapshot' => $khidmatguzar->full_name,
        ]);

        $this->actingAs($admin)->post(route('attendance.present', $session), [
            'assignment_ids' => [DutyAssignment::first()->id],
        ])->assertRedirect();

        $this->assertSame('present', DutyAssignment::first()->fresh()->current_status);
        $this->assertDatabaseHas('attendance_events', ['duty_session_id' => $session->id, 'action' => 'present']);
    }

    public function test_legacy_session_without_structured_identity_remains_fully_readable(): void
    {
        $admin = $this->admin();

        $legacy = DutySession::create(['name' => 'Legacy Session', 'date' => '2026-01-01', 'miqaat' => 'Some Old Miqaat Text', 'status' => 'closed']);

        $this->assertFalse($legacy->hasStructuredIdentity());
        $this->assertNull($legacy->miqaatRef);
        $this->assertNull($legacy->event);
        $this->assertNull($legacy->venue);

        $this->actingAs($admin)->get(route('sessions.show', $legacy))
            ->assertOk()
            ->assertSee('Legacy Session')
            ->assertSee('Some Old Miqaat Text');
    }

    /**
     * This wizard-level test only checks the forecast section is correctly
     * wired into the Create Event Session page — the forecasting formula
     * itself (Operational Demand, reconciliation, confidence, etc.) is
     * exhaustively covered in ForecastingServiceTest, not duplicated here.
     */
    public function test_historical_planning_intelligence_appears_when_comparable_history_exists(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $department = Department::create(['name' => 'Insight Dept', 'normalized_key' => Department::normalize('Insight Dept')]);
        $khidmatguzar = Khidmatguzar::create(['its_id' => '30888888', 'full_name' => 'Insight Person']);

        $priorSession = DutySession::create([
            'name' => 'Prior Occurrence', 'date' => '2026-01-01', 'status' => 'closed',
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]);
        $batch = ImportBatch::create(['duty_session_id' => $priorSession->id, 'uploaded_by' => $admin->id, 'original_filename' => 'x.csv', 'file_type' => 'csv', 'status' => 'completed', 'total_rows' => 1, 'valid_rows' => 1]);
        DutyAssignment::create([
            'duty_session_id' => $priorSession->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $khidmatguzar->id,
            'department_id' => $department->id, 'source_row_number' => 1, 'venue_name_raw' => $department->name,
            'assignment_fingerprint' => 'insight-fp', 'current_status' => 'present', 'full_name_snapshot' => $khidmatguzar->full_name,
        ]);
        ExtraPresent::create([
            'duty_session_id' => $priorSession->id, 'khidmatguzar_id' => $khidmatguzar->id, 'its_id_snapshot' => $khidmatguzar->its_id,
            'full_name_snapshot' => $khidmatguzar->full_name, 'department_id' => $department->id, 'department_name_snapshot' => $department->name,
            'marked_by' => $admin->id, 'marked_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('sessions.create', ['miqaat_id' => $miqaat->id, 'event_id' => $event->id]));

        $response->assertOk()
            ->assertSee('Historical Planning Intelligence')
            ->assertSee('Insight Dept')
            ->assertSee('Recommended HR')
            ->assertDontSee('No historical data available for a reliable recommendation');
    }

    public function test_historical_planning_intelligence_is_an_honest_empty_state_with_no_prior_sessions(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->actingAs($admin)->get(route('sessions.create', ['miqaat_id' => $miqaat->id, 'event_id' => $event->id]))
            ->assertOk()
            ->assertSee('No historical data available for a reliable recommendation');
    }

    public function test_event_dropdown_only_shows_active_events_under_the_selected_miqaat(): void
    {
        $admin = $this->admin();
        $miqaat = $this->miqaat();
        $otherMiqaat = Miqaat::create(['name' => 'Other Miqaat', 'normalized_key' => Miqaat::normalize('Other Miqaat')]);

        $activeEvent = $this->event($miqaat);
        $inactiveEvent = Event::create(['miqaat_id' => $miqaat->id, 'name' => 'Inactive Event', 'normalized_key' => Event::normalize('Inactive Event'), 'active' => false]);
        $foreignEvent = $this->event($otherMiqaat);

        $response = $this->actingAs($admin)->get(route('sessions.create', ['miqaat_id' => $miqaat->id]));

        $response->assertOk()->assertSee($activeEvent->name);
        $response->assertDontSee('Inactive Event');
        // The other Miqaat's event name happens to be identical text, so
        // assert on the count of options bound to this Miqaat instead.
        $response->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$activeEvent->id]);
    }

    public function test_session_created_at_displays_in_ist_on_the_show_screen(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'TZ Session', 'date' => '2026-10-01', 'status' => 'draft']);
        $session->forceFill(['created_at' => Carbon::parse('2026-10-01 19:00:00', 'UTC')])->save();

        $this->actingAs($admin)->get(route('sessions.show', $session))
            ->assertOk()
            ->assertSee('02 Oct 2026 00:30');
    }
}
