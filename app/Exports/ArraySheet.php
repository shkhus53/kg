<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * One reusable, professionally-styled worksheet used by every report export
 * in this app — constructed with a title, header row, and body rows, plus
 * an optional report title/subtitle for a management-ready cover on the
 * first sheet. Avoids a near-duplicate class (and near-duplicate styling)
 * per sheet per report type.
 */
class ArraySheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    private const NAVY = '0F1E3D';

    private const HEADER_STRIPE = 'F8FAFC';

    private const BORDER_COLOR = 'E2E8F0';

    /**
     * @param  array<int,string>  $headings
     * @param  array<int,array<int,mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly ?string $reportTitle = null,
        private readonly ?string $subtitle = null,
        private readonly bool $landscape = false,
    ) {}

    public function array(): array
    {
        $out = [];

        if ($this->reportTitle) {
            $out[] = [$this->reportTitle];
            if ($this->subtitle) {
                $out[] = [$this->subtitle];
            }
            // A bare [] here is falsy and gets silently dropped somewhere in
            // the write pipeline, collapsing every row below it up by one.
            // A single blank-string cell is a real (non-empty) PHP array, so
            // it survives and actually renders as a blank spacer row.
            $out[] = [''];
        }

        $out[] = $this->headings;

        foreach ($this->rows as $row) {
            $out[] = $row;
        }

        return $out;
    }

    public function title(): string
    {
        return $this->title;
    }

    private function headingRow(): int
    {
        if (! $this->reportTitle) {
            return 1;
        }

        return $this->subtitle ? 4 : 3;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $colCount = max(1, count($this->headings));
                $lastCol = Coordinate::stringFromColumnIndex($colCount);
                $headingRow = $this->headingRow();
                $lastRow = $headingRow + count($this->rows);

                if ($this->reportTitle) {
                    $sheet->mergeCells("A1:{$lastCol}1");
                    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB(self::NAVY);
                    $sheet->getRowDimension(1)->setRowHeight(24);

                    if ($this->subtitle) {
                        $sheet->mergeCells("A2:{$lastCol}2");
                        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');
                    }
                }

                // Work around a maatwebsite/excel quirk: Sheet::append() calls
                // PhpSpreadsheet's fromArray() with strictNullComparison=false,
                // so any literal int/float 0 loosely equals the null sentinel
                // and gets silently skipped, leaving the cell blank instead of
                // "0" — a real accuracy problem for a report where 0 is a
                // meaningful, verified count (e.g. "Unknown: 0"). Re-write
                // every cell we know was meant to be a numeric 0.
                foreach ($this->rows as $i => $row) {
                    foreach (array_values($row) as $j => $value) {
                        if ($value === 0 || $value === 0.0) {
                            $col = Coordinate::stringFromColumnIndex($j + 1);
                            $sheet->setCellValueExplicit(
                                $col.($headingRow + 1 + $i),
                                0,
                                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                            );
                        }
                    }
                }

                // Header row: navy fill, white bold text, vertically centered.
                $headingRange = "A{$headingRow}:{$lastCol}{$headingRow}";
                $sheet->getStyle($headingRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($headingRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
                $sheet->getStyle($headingRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension($headingRow)->setRowHeight(20);

                if ($lastRow > $headingRow) {
                    $bodyRange = 'A'.($headingRow + 1).":{$lastCol}{$lastRow}";
                    $sheet->getStyle($bodyRange)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDER_COLOR);

                    // Subtle zebra striping for readability on longer tables.
                    for ($r = $headingRow + 1; $r <= $lastRow; $r++) {
                        if (($r - $headingRow) % 2 === 0) {
                            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
                                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_STRIPE);
                        }
                    }

                    $sheet->setAutoFilter("A{$headingRow}:{$lastCol}{$lastRow}");
                }

                // Freeze everything above and including the header row so it
                // stays visible while scrolling through data.
                $sheet->freezePane('A'.($headingRow + 1));

                // Print setup: repeat the header row on every printed page,
                // fit wide detail tables to one page width.
                $sheet->getPageSetup()->setOrientation(
                    $this->landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT
                );
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headingRow, $headingRow);
                $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
            },
        ];
    }
}
