<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Event;
use App\Models\MasterDataChangeLog;
use App\Models\Miqaat;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of the 3.0 evolution: Master Data (Departments enhancement,
 * Miqaats, Events scoped-per-Miqaat, Venues). Admin-only, additive schema,
 * append-only audit trail — no forecasting/analytics/calendar/user-mgmt
 * in this phase.
 */
class MasterDataTest extends TestCase
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

    public function test_operator_is_forbidden_from_every_master_data_route(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->get(route('masters.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('masters.departments.index'))->assertForbidden();
        $this->actingAs($operator)->post(route('masters.departments.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($operator)->get(route('masters.miqaats.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('masters.events.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('masters.venues.index'))->assertForbidden();
    }

    public function test_admin_can_create_and_edit_a_department_and_it_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('masters.departments.store'), [
            'name' => 'Bethak Khidmat',
            'code' => 'BK',
        ])->assertRedirect(route('masters.departments.index'));

        $department = Department::where('normalized_key', Department::normalize('Bethak Khidmat'))->firstOrFail();
        $this->assertSame('BK', $department->code);
        $this->assertTrue($department->active);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'field' => 'status',
            'new_value' => 'created',
        ]);

        $this->actingAs($admin)->put(route('masters.departments.update', $department), [
            'name' => 'Bethak Khidmat',
            'code' => 'BK2',
            'description' => 'Updated description',
        ])->assertRedirect(route('masters.departments.index'));

        $department->refresh();
        $this->assertSame('BK2', $department->code);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'field' => 'code',
            'old_value' => 'BK',
            'new_value' => 'BK2',
        ]);
    }

    public function test_duplicate_department_name_is_rejected(): void
    {
        $admin = $this->admin();
        Department::create(['name' => 'Existing Dept', 'normalized_key' => Department::normalize('Existing Dept')]);

        $this->actingAs($admin)->post(route('masters.departments.store'), ['name' => 'existing   dept'])
            ->assertSessionHasErrors('name');
    }

    public function test_department_toggle_active_flips_state_and_is_audited(): void
    {
        $admin = $this->admin();
        $department = Department::create(['name' => 'Toggle Dept', 'normalized_key' => Department::normalize('Toggle Dept')])->fresh();
        $this->assertTrue($department->active);

        $this->actingAs($admin)->post(route('masters.departments.toggle', $department))->assertRedirect();
        $this->assertFalse($department->fresh()->active);

        $this->assertDatabaseHas('master_data_change_logs', [
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'new_value' => 'deactivated',
        ]);
    }

    public function test_department_import_auto_create_is_unaffected_by_new_columns(): void
    {
        // Mirrors DutyListImportService::firstOrCreateDepartment's exact call shape.
        $department = Department::firstOrCreate(
            ['normalized_key' => Department::normalize('Auto Created Dept')],
            ['name' => 'Auto Created Dept']
        )->fresh();

        $this->assertTrue($department->active);
        $this->assertNull($department->code);
        $this->assertSame(0, $department->sort_order);
    }

    public function test_admin_can_create_miqaat_and_event_under_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('masters.miqaats.store'), ['name' => 'Istefada Ilmiya'])
            ->assertRedirect(route('masters.miqaats.index'));
        $miqaat = Miqaat::where('normalized_key', Miqaat::normalize('Istefada Ilmiya'))->firstOrFail();

        $this->actingAs($admin)->post(route('masters.events.store'), [
            'miqaat_id' => $miqaat->id,
            'name' => 'Qadambosi Bethak (Mardo)',
            'family' => 'Qadambosi Bethak',
        ])->assertRedirect(route('masters.events.index'));

        $event = Event::where('miqaat_id', $miqaat->id)->firstOrFail();
        $this->assertSame('Qadambosi Bethak', $event->family);
        $this->assertTrue($miqaat->is($event->miqaat));
    }

    /** Approved decision: event identity is scoped per Miqaat, not global. */
    public function test_same_event_name_is_allowed_under_two_different_miqaats(): void
    {
        $admin = $this->admin();
        $miqaatA = Miqaat::create(['name' => 'Miqaat A', 'normalized_key' => Miqaat::normalize('Miqaat A')]);
        $miqaatB = Miqaat::create(['name' => 'Miqaat B', 'normalized_key' => Miqaat::normalize('Miqaat B')]);

        $this->actingAs($admin)->post(route('masters.events.store'), [
            'miqaat_id' => $miqaatA->id, 'name' => 'Qadambosi Bethak',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('masters.events.store'), [
            'miqaat_id' => $miqaatB->id, 'name' => 'Qadambosi Bethak',
        ])->assertRedirect();

        $this->assertSame(2, Event::where('normalized_key', Event::normalize('Qadambosi Bethak'))->count());
    }

    /** But the SAME Miqaat cannot have the same event name twice. */
    public function test_duplicate_event_name_within_same_miqaat_is_rejected(): void
    {
        $admin = $this->admin();
        $miqaat = Miqaat::create(['name' => 'Miqaat A', 'normalized_key' => Miqaat::normalize('Miqaat A')]);
        Event::create(['miqaat_id' => $miqaat->id, 'name' => 'Nikah Bethak', 'normalized_key' => Event::normalize('Nikah Bethak')]);

        $this->actingAs($admin)->post(route('masters.events.store'), [
            'miqaat_id' => $miqaat->id, 'name' => 'nikah   bethak',
        ])->assertSessionHasErrors('name');
    }

    public function test_admin_can_create_venue_with_optional_geolocation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('masters.venues.store'), [
            'name' => 'Saifee Masjid',
            'city' => 'Mumbai',
            'latitude' => '18.9587',
            'longitude' => '72.8321',
        ])->assertRedirect(route('masters.venues.index'));

        $venue = Venue::where('normalized_key', Venue::normalize('Saifee Masjid'))->firstOrFail();
        $this->assertSame('Mumbai', $venue->city);
        $this->assertNotNull($venue->latitude);
    }

    public function test_master_data_change_log_history_is_readable_via_edit_screens(): void
    {
        $admin = $this->admin();
        $venue = Venue::create(['name' => 'History Venue', 'normalized_key' => Venue::normalize('History Venue')]);

        MasterDataChangeLog::create([
            'entity_type' => 'venue',
            'entity_id' => $venue->id,
            'field' => 'status',
            'new_value' => 'created',
            'changed_by' => $admin->id,
            'changed_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('masters.venues.edit', $venue))
            ->assertOk()
            ->assertSee('Created');
    }
}
