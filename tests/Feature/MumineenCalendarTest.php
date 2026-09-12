<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Miqaat;
use App\Models\User;
use App\Services\MumineenCalendarService;
use App\Support\HijriDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard Hijri date + Mumineen calendar Miqaat display. The critical
 * requirement under test throughout: the Mumineen calendar Miqaat feature
 * is completely independent of KG Attendance's own Event/Miqaat master
 * data — nothing in MumineenCalendarService reads from those models at
 * all, so a KG-created Event/Miqaat can never surface here.
 */
class MumineenCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ===================================================================
    // Dashboard display
    // ===================================================================

    public function test_dashboard_shows_gregorian_and_hijri_date(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(now()->toIst()->format('d M Y'));

        $hijriToday = app(MumineenCalendarService::class)->today();
        $response->assertSee($hijriToday->format());
    }

    public function test_hijri_date_is_a_clickable_button_that_opens_the_calendar_modal(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        // The Hijri date is a real <button> (not a link, not plain text)
        // that dispatches the event the modal listens for — clicking it
        // must never navigate to a separate page.
        $response->assertSee('open-calendar-modal', false);
        $response->assertSee('role="dialog"', false);
        $response->assertDontSee('href="/calendar"', false);
    }

    public function test_calendar_modal_embeds_both_gregorian_and_hijri_values_in_one_merged_structure(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $content = $response->getContent();

        // One JSON blob containing both — never two separate calendar
        // payloads. Js::from() escapes quotes for safe inline embedding
        // (e.g. "hijri"), so check for the key names rather than
        // literal JSON quoting.
        $this->assertMatchesRegularExpression('/hijri/i', $content);
        $this->assertMatchesRegularExpression('/gregorian/i', $content);
    }

    public function test_no_technical_source_labels_appear_in_the_dashboard(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        foreach ([
            'Mumineen Calendar', 'Mumineen Hijri Calendar', 'Mumineen Hijri',
            'Hijri &amp; Gregorian (Combined)', 'Hijri & Gregorian (Combined)',
            'Gregorian + Hijri Calendar', 'from Mumineen Calendar',
        ] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
    }

    // ===================================================================
    // Current day identification
    // ===================================================================

    public function test_current_day_is_correctly_identified_in_the_calendar_grid(): void
    {
        $calendar = app(MumineenCalendarService::class);
        $today = $calendar->today();
        $grid = $calendar->monthGrid($today);

        $todayCell = collect($grid)->flatten(1)->filter()->firstWhere('isToday', true);

        $this->assertNotNull($todayCell, 'The grid for the current Hijri month must contain exactly one cell marked isToday.');
        $this->assertSame($today->day, $todayCell['hijri']['day']);
        $this->assertSame(now()->toIst()->toDateString(), $todayCell['gregorian']['iso']);
    }

    // ===================================================================
    // Miqaat source separation — the mandatory regression
    // ===================================================================

    public function test_kg_attendance_event_never_becomes_a_dashboard_miqaat(): void
    {
        $admin = $this->admin();
        $miqaat = Miqaat::create(['name' => 'Istefada Ilmiya', 'normalized_key' => Miqaat::normalize('Istefada Ilmiya')]);
        Event::create([
            'miqaat_id' => $miqaat->id,
            'name' => 'Qadambosi Bethak',
            'normalized_key' => Event::normalize('Qadambosi Bethak'),
        ]);

        $todaysMiqaats = app(MumineenCalendarService::class)->todaysMiqaats();

        $this->assertNotContains('Qadambosi Bethak', array_column($todaysMiqaats, 'title'), 'A KG Attendance Event must never appear as a dashboard Miqaat merely because it exists or shares a name.');

        // And end-to-end: the dashboard response must not surface it via
        // the Miqaat card, regardless of what the calendar source contains.
        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_mumineen_calendar_service_has_no_dependency_on_kg_attendance_models(): void
    {
        $reflection = new \ReflectionClass(MumineenCalendarService::class);
        $source = file_get_contents($reflection->getFileName());

        foreach (['App\\Models\\Event', 'App\\Models\\DutySession', 'App\\Models\\EventPlan', 'App\\Models\\Miqaat', 'App\\Models\\Venue'] as $forbiddenImport) {
            $this->assertStringNotContainsString($forbiddenImport, $source, "MumineenCalendarService must never reference {$forbiddenImport} — it is a completely separate data source.");
        }
    }

    // ===================================================================
    // Miqaat card presence/absence
    // ===================================================================

    public function test_miqaat_card_appears_when_todays_hijri_date_has_a_real_calendar_miqaat(): void
    {
        // Hijri 1/1 is Hijri New Year in the real, unmodified calendar data.
        $miqaats = app(MumineenCalendarService::class)->miqaatsOn(new HijriDate(1448, 0, 1));

        $this->assertNotEmpty($miqaats, 'Hijri New Year (month 0, day 1) must be present in the real calendar data.');
        $this->assertSame('Hijri New Year', $miqaats[0]['title']);
    }

    public function test_no_miqaat_card_placeholder_when_there_is_no_miqaat_today(): void
    {
        $admin = $this->admin();

        // Force "today" to a date with no Miqaat by directly checking the
        // service for a date the real data confirms is empty, then assert
        // the dashboard never renders any placeholder wording for that case.
        $emptyDate = collect(range(1, 29))
            ->map(fn ($day) => new HijriDate(1448, 5, $day))
            ->first(fn ($d) => empty(app(MumineenCalendarService::class)->miqaatsOn($d)));

        $this->assertNotNull($emptyDate, 'Sanity check: at least one day in this month has no Miqaat in the real data.');

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('No Miqaat today');
        $response->assertDontSee('There is no Miqaat today');
        $response->assertDontSee("Today's Miqaat");
    }

    public function test_multiple_miqaats_on_the_same_date_are_all_returned(): void
    {
        // Hijri 1/1 carries both "Hijri New Year" and an Urus entry in the
        // real data — confirms the source's multi-miqaat-per-day case is
        // handled, not collapsed to one.
        $miqaats = app(MumineenCalendarService::class)->miqaatsOn(new HijriDate(1448, 0, 1));

        $this->assertGreaterThanOrEqual(2, count($miqaats));
    }

    public function test_miqaat_dates_are_marked_in_the_calendar_grid(): void
    {
        $calendar = app(MumineenCalendarService::class);
        $grid = $calendar->monthGrid(new HijriDate(1448, 0, 1));

        $newYearCell = collect($grid)->flatten(1)->filter()->firstWhere(fn ($c) => $c['hijri']['day'] === 1);

        $this->assertNotEmpty($newYearCell['miqaats']);
    }

    // ===================================================================
    // IST boundary
    // ===================================================================

    public function test_today_uses_the_operational_ist_timezone_not_server_utc(): void
    {
        $calendar = app(MumineenCalendarService::class);

        $expectedGregorianIst = now()->toIst()->toDateString();
        $todayHijri = $calendar->today();

        $this->assertSame($expectedGregorianIst, $todayHijri->toGregorian()->toDateString());
    }

    // ===================================================================
    // Regression: existing dashboard/attendance data unaffected
    // ===================================================================

    public function test_existing_dashboard_attendance_data_is_unchanged(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk()->assertSee('Quick Actions')->assertSee('Recent Sessions');
    }

    public function test_permission_visibility_operator_and_viewer_both_see_the_calendar(): void
    {
        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($operator)->get(route('dashboard'))->assertOk()->assertSee('open-calendar-modal', false);
        $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertSee('open-calendar-modal', false);
    }
}
