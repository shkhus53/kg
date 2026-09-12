<?php

namespace Tests\Feature;

use App\Exports\ArraySheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 9.1 (security patch): formula-injection guard centralized in
 * ArraySheet, the single class every Excel export in the app builds its
 * sheets through (SessionAttendanceExport, DepartmentReportExport,
 * DepartmentDetailReportExport, KhidmatguzarReportExport,
 * OperatorActivityReportExport — all of them, confirmed by inspection).
 * These tests exercise ArraySheet directly rather than duplicating the
 * check per export type, since that IS the centralization point.
 */
class ExcelFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function loadSheet(ArraySheet $sheet, string $filename): Worksheet
    {
        $path = storage_path('app/private/'.$filename);
        Excel::store($sheet, $filename, 'local');
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        unlink($path);

        return $worksheet;
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
    public function test_dangerous_leading_character_is_exported_as_literal_text_not_a_formula(string $malicious): void
    {
        $sheet = new ArraySheet('Sheet1', ['Name'], [[$malicious]]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-'.md5($malicious).'.xlsx');

        $cell = $worksheet->getCell('A2');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), 'cell must be typed as literal text, never a formula');
        $this->assertSame($malicious, $cell->getValue(), 'the value itself must be preserved exactly — no character stripped or added');
    }

    public function test_ordinary_text_is_completely_unaffected(): void
    {
        $sheet = new ArraySheet('Sheet1', ['Name'], [['Mohammed Ali Khan']]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-ordinary-text.xlsx');

        $this->assertSame('Mohammed Ali Khan', $worksheet->getCell('A2')->getValue());
    }

    public function test_its_number_style_string_is_unaffected_by_the_sanitizer(): void
    {
        // Real ITS numbers are 8-digit strings — never start with the four
        // guarded characters, so the sanitizer must never touch this cell
        // (PhpSpreadsheet's own numeric-looking-string auto-detection is
        // pre-existing, unrelated behavior — this test only proves the new
        // guard doesn't additionally alter it).
        $sheet = new ArraySheet('Sheet1', ['ITS'], [['30123456']]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-its.xlsx');

        $this->assertEquals('30123456', (string) $worksheet->getCell('A2')->getValue());
        $this->assertNotSame(DataType::TYPE_STRING, $worksheet->getCell('A2')->getDataType(), 'a plain numeric-looking value must never be force-typed as a string by the guard');
    }

    public function test_text_merely_containing_a_guarded_character_midstring_is_unaffected(): void
    {
        // Only a LEADING character is dangerous — "Al-Farsi" or "care@x"
        // must not be treated as a formula risk.
        $sheet = new ArraySheet('Sheet1', ['Name'], [['Al-Farsi Department']]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-midstring.xlsx');

        $this->assertSame('Al-Farsi Department', $worksheet->getCell('A2')->getValue());
    }

    public function test_numeric_zero_still_renders_correctly_alongside_the_new_guard(): void
    {
        // Regression: the pre-existing zero-blanking fix in the same loop
        // must keep working now that a second condition shares it.
        $sheet = new ArraySheet('Sheet1', ['Count'], [[0]]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-zero.xlsx');

        $cell = $worksheet->getCell('A2');
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
        $this->assertSame(0, $cell->getValue());
    }

    public function test_numeric_and_float_values_are_never_treated_as_strings(): void
    {
        $sheet = new ArraySheet('Sheet1', ['Rate', 'Count'], [[50.5, 12]]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-numeric.xlsx');

        $this->assertEqualsWithDelta(50.5, (float) $worksheet->getCell('A2')->getValue(), 0.001);
        $this->assertSame(12, $worksheet->getCell('B2')->getValue());
    }

    public function test_date_formatted_string_is_unaffected(): void
    {
        $sheet = new ArraySheet('Sheet1', ['Date'], [['11 Sep 2026 14:30']]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-date.xlsx');

        $this->assertSame('11 Sep 2026 14:30', $worksheet->getCell('A2')->getValue());
    }

    public function test_null_value_is_unaffected(): void
    {
        $sheet = new ArraySheet('Sheet1', ['Remark'], [[null]]);
        $worksheet = $this->loadSheet($sheet, 'test-formula-null.xlsx');

        $this->assertNull($worksheet->getCell('A2')->getValue());
    }
}
