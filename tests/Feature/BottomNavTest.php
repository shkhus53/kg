<?php

namespace Tests\Feature;

use App\Models\DutySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_fab_redirects_to_active_session_live_attendance(): void
    {
        $user = User::factory()->admin()->create();
        DutySession::create(['name' => 'Draft One', 'date' => now()->format('Y-m-d'), 'status' => 'draft']);
        $active = DutySession::create(['name' => 'Active One', 'date' => now()->format('Y-m-d'), 'status' => 'active']);

        $response = $this->actingAs($user)->get(route('attendance.shell.live-redirect'));

        $response->assertRedirect(route('attendance.shell.live', $active));
    }

    public function test_search_fab_redirects_to_sessions_when_no_active_session(): void
    {
        $user = User::factory()->admin()->create();
        DutySession::create(['name' => 'Closed One', 'date' => now()->format('Y-m-d'), 'status' => 'closed']);

        $response = $this->actingAs($user)->get(route('attendance.shell.live-redirect'));

        $response->assertRedirect(route('sessions.index'));
        $response->assertSessionHas('flash_info');
    }

    public function test_more_menu_analytics_and_directory_routes_work(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('analytics.overview'))->assertOk();
        $this->actingAs($user)->get(route('analytics.profile-search'))->assertOk();
    }
}
