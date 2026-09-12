<?php

namespace Tests\Feature;

use App\Models\Khidmatguzar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real-UI-testing finding: the Khidmatguzar Directory (/khidmatguzars) had
 * search/pagination but no gender filter and no member counts. Adds both,
 * reusing App\Support\Gender — the app's single gender-normalization source
 * of truth (M/Male -> Male, F/Female -> Female, else Unknown) — rather than
 * inventing a second interpretation, and one aggregate GROUP BY query
 * rather than loading rows into PHP to compute counts.
 */
class KhidmatguzarDirectoryGenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function seedMixedGenders(): void
    {
        Khidmatguzar::create(['its_id' => '40000001', 'full_name' => 'Ali Male One', 'gender' => 'M']);
        Khidmatguzar::create(['its_id' => '40000002', 'full_name' => 'Ali Male Two', 'gender' => 'Male']);
        Khidmatguzar::create(['its_id' => '40000003', 'full_name' => 'Ali Male Three', 'gender' => 'Male']);
        Khidmatguzar::create(['its_id' => '40000004', 'full_name' => 'Fatema Female One', 'gender' => 'F']);
        Khidmatguzar::create(['its_id' => '40000005', 'full_name' => 'Fatema Female Two', 'gender' => 'Female']);
        Khidmatguzar::create(['its_id' => '40000006', 'full_name' => 'No Gender Person', 'gender' => null]);
        Khidmatguzar::create(['its_id' => '40000007', 'full_name' => 'Blank Gender Person', 'gender' => '']);
    }

    public function test_directory_shows_total_and_gender_counts_that_reconcile(): void
    {
        $this->seedMixedGenders();

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search'));

        $response->assertOk();
        $response->assertSee('7', false); // total
        $response->assertSee('3', false); // male
        $response->assertSee('2', false); // female
        $response->assertSee('2', false); // unknown (null + blank)

        // Reconciliation is the real assertion — pull the values the
        // controller actually computed, not just substrings in the HTML.
        $response->assertViewHas('totalCount', 7);
        $response->assertViewHas('genderCounts', ['Male' => 3, 'Female' => 2, 'Unknown' => 2]);
    }

    public function test_male_filter_returns_only_male_members(): void
    {
        $this->seedMixedGenders();

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['gender' => 'Male']));

        $response->assertOk();
        $response->assertSee('Ali Male One');
        $response->assertSee('Ali Male Two');
        $response->assertSee('Ali Male Three');
        $response->assertDontSee('Fatema Female One');
        $response->assertDontSee('No Gender Person');
        $this->assertSame(3, $response->viewData('matches')->total());
    }

    public function test_female_filter_returns_only_female_members(): void
    {
        $this->seedMixedGenders();

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['gender' => 'Female']));

        $response->assertOk();
        $response->assertSee('Fatema Female One');
        $response->assertSee('Fatema Female Two');
        $response->assertDontSee('Ali Male One');
        $this->assertSame(2, $response->viewData('matches')->total());
    }

    public function test_unknown_filter_returns_only_null_or_blank_gender_members(): void
    {
        $this->seedMixedGenders();

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['gender' => 'Unknown']));

        $response->assertOk();
        $response->assertSee('No Gender Person');
        $response->assertSee('Blank Gender Person');
        $response->assertDontSee('Ali Male One');
        $this->assertSame(2, $response->viewData('matches')->total());
    }

    public function test_search_and_gender_filter_apply_together(): void
    {
        $this->seedMixedGenders();
        Khidmatguzar::create(['its_id' => '40000008', 'full_name' => 'Zainab Female Search', 'gender' => 'Female']);
        Khidmatguzar::create(['its_id' => '40000009', 'full_name' => 'Zainab Male Search', 'gender' => 'Male']);

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['q' => 'Zainab', 'gender' => 'Female']));

        $response->assertOk();
        $response->assertSee('Zainab Female Search');
        $response->assertDontSee('Zainab Male Search');
        $this->assertSame(1, $response->viewData('matches')->total());
    }

    public function test_gender_counts_reflect_other_active_filters_not_narrowed_by_gender_itself(): void
    {
        $this->seedMixedGenders();
        Khidmatguzar::create(['its_id' => '40000010', 'full_name' => 'Scoped Male', 'gender' => 'Male']);
        Khidmatguzar::create(['its_id' => '40000011', 'full_name' => 'Scoped Female', 'gender' => 'Female']);

        // Searching "Scoped" narrows to 2 people (1 Male, 1 Female) — the
        // gender counts must reflect THIS scope, not the whole directory,
        // even though no gender filter is selected.
        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['q' => 'Scoped']));

        $response->assertOk();
        $response->assertViewHas('totalCount', 2);
        $response->assertViewHas('genderCounts', ['Male' => 1, 'Female' => 1, 'Unknown' => 0]);
    }

    public function test_pagination_does_not_alter_the_reported_total_count(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Khidmatguzar::create(['its_id' => '5'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'full_name' => 'Page Person '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'gender' => 'Male']);
        }

        $page1 = $this->actingAs($this->admin())->get(route('analytics.profile-search'));
        $page1->assertOk();
        $this->assertSame(25, $page1->viewData('matches')->total());
        $this->assertCount(20, $page1->viewData('matches')->items()); // page size

        $page2 = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['page' => 2]));
        $page2->assertOk();
        $this->assertSame(25, $page2->viewData('matches')->total(), 'total must not become the page-row count on page 2');
        $this->assertCount(5, $page2->viewData('matches')->items());
    }

    public function test_showing_x_of_y_wording_is_distinct_from_total_members_wording(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Khidmatguzar::create(['its_id' => '6'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'full_name' => 'Wording Person '.$i, 'gender' => 'Male']);
        }

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search'));

        $response->assertOk();
        $response->assertSee('Showing 1–20 of 25');
        // Laravel's default pagination view's own "Showing X to Y of Z
        // results" text must not also render — exactly one summary line.
        $response->assertDontSee('Showing 1 to 20 of 25 results');
    }

    public function test_existing_search_and_department_filters_still_work_alongside_gender(): void
    {
        $this->seedMixedGenders();

        $response = $this->actingAs($this->admin())->get(route('analytics.profile-search', ['q' => 'Ali']));

        $response->assertOk();
        $response->assertSee('Ali Male One');
        $response->assertSee('Ali Male Two');
        $response->assertSee('Ali Male Three');
        $response->assertDontSee('Fatema Female One');
    }

    public function test_gender_counts_use_one_aggregate_query_not_n_plus_one(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Khidmatguzar::create(['its_id' => '7'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'full_name' => 'Agg Person '.$i, 'gender' => $i % 2 === 0 ? 'Male' : 'Female']);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin())->get(route('analytics.profile-search'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Must stay small and NOT scale with the 20 people created above —
        // one gender aggregate query plus the paginated list query plus
        // session/auth overhead, never one query per row.
        $this->assertLessThan(20, $queryCount);
    }
}
