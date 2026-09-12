<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutySession;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\DutyListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression for the audit-confirmed gap: formula-injection protection
 * existed only on export (ArraySheet::isFormulaInjectionRisk), never on
 * import ingestion — a malicious cell value could sit in the database
 * unneutralized until some future export path forgot to route through
 * ArraySheet. DutyListImportService::parse() now neutralizes a leading
 * =/+/-/@ with a leading apostrophe (the same "force text" marker
 * spreadsheet applications themselves use), preserving every original
 * character rather than stripping any of them.
 */
class ImportFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function parseSingleRow(string $fullName): array
    {
        $csv = "ITS ID,FullName,Gender,Age,Category,Idara,Jamaat,Jamiaat,Venue Name,Block Name,Day,Day Alias,Seat,Status,Allocated User Name,Allocated Date,DeAllocated User Name,DeAllocated Date,Scanned,Acc Child Below 5Yrs,Multiple Acc Child Above 4Yrs,HYear,Miqaat\n"
            .'60000099,"'.str_replace('"', '""', $fullName)."\",Male,30,Test,I,J,JM,DEPT-X,B,D,DA,A1,Allocated,sys,2026-01-01,,,N,0,0,1448,Test\n";
        $file = UploadedFile::fake()->createWithContent('list.csv', $csv);

        $result = app(DutyListImportService::class)->parse($file);

        return $result['rows'][0]['data'];
    }

    public static function dangerousPrefixProvider(): array
    {
        return [
            'equals sign' => ['=cmd|\'/c calc\'!A1'],
            'plus sign' => ['+1+1'],
            'minus sign' => ['-2+3'],
            'at sign' => ['@SUM(1+1)'],
        ];
    }

    #[DataProvider('dangerousPrefixProvider')]
    public function test_dangerous_leading_character_is_neutralized_on_ingestion(string $malicious): void
    {
        $data = $this->parseSingleRow($malicious);

        $this->assertSame("'".$malicious, $data['full_name'], 'a leading apostrophe must be added so the value is inert text if ever exported, with no original character lost');
        $this->assertStringContainsString(ltrim($malicious, "'"), $data['full_name']);
    }

    public function test_ordinary_name_is_completely_unaffected(): void
    {
        $data = $this->parseSingleRow('Mohammed Ali Khan');

        $this->assertSame('Mohammed Ali Khan', $data['full_name']);
    }

    public function test_name_merely_containing_a_guarded_character_midstring_is_unaffected(): void
    {
        $data = $this->parseSingleRow('Al-Farsi');

        $this->assertSame('Al-Farsi', $data['full_name']);
    }

    public function test_its_number_is_never_touched_by_the_guard(): void
    {
        $data = $this->parseSingleRow('Normal Person');

        $this->assertSame('60000099', $data['its_id'], 'ITS numbers never start with =, +, -, @ in real data — confirm the guard leaves them exactly as-is');
    }

    public function test_neutralized_value_still_flows_correctly_through_preview_and_commit(): void
    {
        $session = DutySession::create(['name' => 'Formula Test', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
        Department::create(['name' => 'DEPT-X', 'normalized_key' => Department::normalize('DEPT-X')]);
        $admin = User::factory()->admin()->create();

        $data = $this->parseSingleRow('=cmd|\'/c calc\'!A1');
        $service = app(DutyListImportService::class);
        $preview = $service->buildPreview($session, [['row_number' => 2, 'data' => $data]]);

        $this->assertSame(1, $preview['new_khidmatguzars']);
        $batch = $service->commit($session, $preview['valid'], $admin, 'f.csv', 'csv', $preview);

        $kg = Khidmatguzar::where('its_id', '60000099')->first();
        $this->assertNotNull($kg);
        $this->assertSame("'=cmd|'/c calc'!A1", $kg->full_name, 'the neutralized value must persist through commit unchanged — no additional mutation, no loss');
    }
}
