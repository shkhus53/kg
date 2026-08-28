<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Reads a spreadsheet as a plain 2D array (header row included, no
 * heading-row formatting) so the importer can match source columns by
 * their exact, known header text rather than a library-derived slug.
 */
class RawSheetImport implements ToArray
{
    public array $rows = [];

    public function array(array $array): void
    {
        $this->rows = $array;
    }
}
