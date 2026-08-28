<?php

namespace App\Services;

use App\Imports\RawSheetImport;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class DutyListImportService
{
    /**
     * The known source columns and the exact header text used for each,
     * per the locked Phase 0 source-file analysis. Do not invent columns.
     */
    private const COLUMN_MAP = [
        'h_year' => 'HYear',
        'miqaat' => 'Miqaat',
        'its_id' => 'ITS ID',
        'full_name' => 'FullName',
        'gender' => 'Gender',
        'age' => 'Age',
        'category' => 'Category',
        'idara' => 'Idara',
        'jamaat' => 'Jamaat',
        'jamiaat' => 'Jamiaat',
        'venue_name' => 'Venue Name',
        'block_name' => 'Block Name',
        'day' => 'Day',
        'day_alias' => 'Day Alias',
        'seat' => 'Seat',
        'status' => 'Status',
        'allocated_user_name' => 'Allocated User Name',
        'allocated_date' => 'Allocated Date',
        'deallocated_user_name' => 'DeAllocated User Name',
        'deallocated_date' => 'DeAllocated Date',
        'scanned' => 'Scanned',
        'acc_child_below_5yrs' => 'Acc Child Below 5Yrs',
        'multiple_acc_child_above_4yrs' => 'Multiple Acc Child Above 4Yrs',
    ];

    /**
     * Structural columns without which the file cannot be safely imported.
     */
    private const REQUIRED_COLUMNS = ['its_id', 'full_name', 'venue_name'];

    /**
     * Read the file and validate structure only. Throws via the
     * 'missingColumns' key rather than an exception so the controller can
     * render a clear, human-readable rejection.
     *
     * @return array{headers: array<int,string>, missingColumns: array<int,string>, rows: array<int,array<string,mixed>>}
     */
    public function parse(UploadedFile $file): array
    {
        $import = new RawSheetImport;
        Excel::import($import, $file);

        $sheet = $import->rows;
        $headerRow = array_shift($sheet) ?? [];

        $columnIndex = [];
        foreach ($headerRow as $index => $value) {
            $columnIndex[$this->normalizeHeader((string) $value)] = $index;
        }

        $missingColumns = [];
        foreach (self::REQUIRED_COLUMNS as $field) {
            $header = self::COLUMN_MAP[$field];
            if (! array_key_exists($this->normalizeHeader($header), $columnIndex)) {
                $missingColumns[] = $header;
            }
        }

        if (! empty($missingColumns)) {
            return ['headers' => array_map('strval', $headerRow), 'missingColumns' => $missingColumns, 'rows' => []];
        }

        $rows = [];
        foreach ($sheet as $rowNumber => $cells) {
            if ($this->isBlankRow($cells)) {
                continue;
            }

            $data = [];
            foreach (self::COLUMN_MAP as $field => $header) {
                $index = $columnIndex[$this->normalizeHeader($header)] ?? null;
                $data[$field] = $index !== null ? trim((string) ($cells[$index] ?? '')) : null;
            }

            // +2: array is zero-indexed after the header row was shifted off,
            // and spreadsheets are 1-indexed with row 1 being the header.
            $rows[] = ['row_number' => $rowNumber + 2, 'data' => $data];
        }

        return ['headers' => array_map('strval', $headerRow), 'missingColumns' => [], 'rows' => $rows];
    }

    /**
     * Classify parsed rows against the current database state and against
     * each other, without writing anything. Used for the preview screen.
     *
     * Three duplicate scopes are considered, per the locked Phase 3 rules:
     *  - within the incoming file itself (existing Phase 2 behavior)
     *  - against Duty Assignments already committed in THIS session, from
     *    an earlier batch (new in Phase 3)
     *  - a different Duty Session never affects this — deliberately not
     *    checked here.
     *
     * @param  array<int,array{row_number:int,data:array<string,mixed>}>  $rows
     */
    public function buildPreview(DutySession $dutySession, array $rows): array
    {
        $existingDeptKeys = Department::whereIn('normalized_key', collect($rows)
            ->pluck('data.venue_name')->filter()->map(fn ($v) => Department::normalize($v))->unique())
            ->pluck('normalized_key')->flip();

        // Fingerprints already committed for this session, from any earlier
        // batch, keyed for O(1) lookup. Carries enough to reference back to
        // the earlier batch/row in the preview.
        $existingAssignments = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->with('importBatch:id,original_filename')
            ->get(['assignment_fingerprint', 'import_batch_id', 'source_row_number'])
            ->keyBy('assignment_fingerprint');

        $seenFingerprints = [];
        $invalid = [];
        $withinFileValid = [];
        $exactDuplicates = [];
        $crossBatchDuplicates = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $itsId = $data['its_id'];

            if ($itsId === '' || $itsId === null) {
                $invalid[] = ['row_number' => $row['row_number'], 'error' => 'Missing ITS ID', 'value' => null];

                continue;
            }

            if ($data['venue_name'] === '' || $data['venue_name'] === null) {
                $invalid[] = ['row_number' => $row['row_number'], 'error' => 'Missing Venue Name', 'value' => $itsId];

                continue;
            }

            if ($data['full_name'] === '' || $data['full_name'] === null) {
                $invalid[] = ['row_number' => $row['row_number'], 'error' => 'Missing FullName', 'value' => $itsId];

                continue;
            }

            $fingerprint = $this->fingerprint($data);

            if (isset($seenFingerprints[$fingerprint])) {
                $exactDuplicates[] = [
                    'row_number' => $row['row_number'],
                    'error' => 'Exact duplicate of row '.$seenFingerprints[$fingerprint].' in this file',
                    'value' => $itsId,
                ];

                continue;
            }

            $seenFingerprints[$fingerprint] = $row['row_number'];

            if ($existing = $existingAssignments->get($fingerprint)) {
                $crossBatchDuplicates[] = [
                    'row_number' => $row['row_number'],
                    'error' => 'Already imported in batch "'.$existing->importBatch?->original_filename.'" (row '.$existing->source_row_number.')',
                    'value' => $itsId,
                ];

                continue;
            }

            $withinFileValid[] = $row;
        }

        $existingIts = Khidmatguzar::whereIn('its_id', array_column(array_column($withinFileValid, 'data'), 'its_id'))
            ->pluck('its_id')->flip();

        $newIts = collect($withinFileValid)->pluck('data.its_id')->unique()
            ->reject(fn ($its) => $existingIts->has($its));

        $newDeptKeys = collect($withinFileValid)
            ->pluck('data.venue_name')
            ->map(fn ($v) => Department::normalize($v))
            ->unique()
            ->reject(fn ($key) => $existingDeptKeys->has($key));

        $existingDeptKeysUsed = collect($withinFileValid)
            ->pluck('data.venue_name')
            ->map(fn ($v) => Department::normalize($v))
            ->unique()
            ->filter(fn ($key) => $existingDeptKeys->has($key));

        return [
            'total_rows' => count($rows),
            'valid_rows' => count($withinFileValid),
            'invalid_rows' => $invalid,
            'exact_duplicate_rows' => $exactDuplicates,
            'cross_batch_duplicate_rows' => $crossBatchDuplicates,
            'unique_its_count' => collect($withinFileValid)->pluck('data.its_id')->unique()->count(),
            'new_khidmatguzars' => $newIts->count(),
            'existing_khidmatguzars' => collect($withinFileValid)->pluck('data.its_id')->unique()->count() - $newIts->count(),
            'new_departments' => $newDeptKeys->count(),
            'existing_departments' => $existingDeptKeysUsed->count(),
            'valid' => $withinFileValid,
        ];
    }

    /**
     * Commit previously-validated rows to the database inside a single
     * transaction. `$validRows` must be the 'valid' bucket produced by
     * buildPreview() for the same file — rows already known to be
     * structurally sound and free of in-file exact duplicates.
     *
     * @param  array<int,array{row_number:int,data:array<string,mixed>}>  $validRows
     */
    public function commit(
        DutySession $dutySession,
        array $validRows,
        User $uploadedBy,
        string $originalFilename,
        string $fileType,
        array $previewSummary,
    ): ImportBatch {
        return DB::transaction(function () use ($dutySession, $validRows, $uploadedBy, $originalFilename, $fileType, $previewSummary) {
            $batch = ImportBatch::create([
                'duty_session_id' => $dutySession->id,
                'uploaded_by' => $uploadedBy->id,
                'original_filename' => $originalFilename,
                'file_type' => $fileType,
                'status' => 'completed',
                'total_rows' => $previewSummary['total_rows'],
                'valid_rows' => $previewSummary['valid_rows'],
                'invalid_rows' => count($previewSummary['invalid_rows']),
                'exact_duplicate_rows' => count($previewSummary['exact_duplicate_rows']),
                'cross_batch_duplicate_rows' => count($previewSummary['cross_batch_duplicate_rows']),
                'new_khidmatguzars' => $previewSummary['new_khidmatguzars'],
                'existing_khidmatguzars' => $previewSummary['existing_khidmatguzars'],
                'new_departments' => $previewSummary['new_departments'],
                'existing_departments' => $previewSummary['existing_departments'],
                'error_summary' => [
                    'invalid_rows' => $previewSummary['invalid_rows'],
                    'exact_duplicate_rows' => $previewSummary['exact_duplicate_rows'],
                    'cross_batch_duplicate_rows' => $previewSummary['cross_batch_duplicate_rows'],
                ],
            ]);

            $departmentCache = [];
            $khidmatguzarCache = [];

            foreach ($validRows as $row) {
                $data = $row['data'];

                $deptKey = Department::normalize($data['venue_name']);
                if (! isset($departmentCache[$deptKey])) {
                    $departmentCache[$deptKey] = Department::firstOrCreate(
                        ['normalized_key' => $deptKey],
                        ['name' => $data['venue_name']],
                    );
                }
                $department = $departmentCache[$deptKey];

                if (! isset($khidmatguzarCache[$data['its_id']])) {
                    $khidmatguzarCache[$data['its_id']] = Khidmatguzar::updateOrCreate(
                        ['its_id' => $data['its_id']],
                        [
                            'full_name' => $data['full_name'],
                            'gender' => $this->blank($data['gender']),
                            'idara' => $this->blank($data['idara']),
                            'jamaat' => $this->blank($data['jamaat']),
                            'jamiaat' => $this->blank($data['jamiaat']),
                        ],
                    );
                }
                $khidmatguzar = $khidmatguzarCache[$data['its_id']];

                DutyAssignment::create([
                    'duty_session_id' => $dutySession->id,
                    'import_batch_id' => $batch->id,
                    'khidmatguzar_id' => $khidmatguzar->id,
                    'department_id' => $department->id,
                    'source_row_number' => $row['row_number'],
                    'assignment_fingerprint' => $this->fingerprint($data),
                    'block_name' => $this->blank($data['block_name']),
                    'day' => $this->blank($data['day']),
                    'day_alias' => $this->blank($data['day_alias']),
                    'seat' => $this->blank($data['seat']),
                    'category' => $this->blank($data['category']),
                    'venue_name_raw' => $data['venue_name'],
                    'current_status' => 'pending',
                    'full_name_snapshot' => $data['full_name'],
                    'gender_snapshot' => $this->blank($data['gender']),
                    'age_snapshot' => $this->blank($data['age']),
                    'idara_snapshot' => $this->blank($data['idara']),
                    'jamaat_snapshot' => $this->blank($data['jamaat']),
                    'jamiaat_snapshot' => $this->blank($data['jamiaat']),
                    'h_year' => $this->blank($data['h_year']),
                    'miqaat' => $this->blank($data['miqaat']),
                    'status_raw' => $this->blank($data['status']),
                    'allocated_user_name' => $this->blank($data['allocated_user_name']),
                    'allocated_date' => $this->blank($data['allocated_date']),
                    'deallocated_user_name' => $this->blank($data['deallocated_user_name']),
                    'deallocated_date' => $this->blank($data['deallocated_date']),
                    'scanned' => $this->blank($data['scanned']),
                    'acc_child_below_5yrs' => $this->blank($data['acc_child_below_5yrs']),
                    'multiple_acc_child_above_4yrs' => $this->blank($data['multiple_acc_child_above_4yrs']),
                ]);
            }

            return $batch;
        });
    }

    public function fingerprint(array $data): string
    {
        $parts = [
            $data['its_id'],
            Department::normalize($data['venue_name'] ?? ''),
            mb_strtolower(trim((string) ($data['block_name'] ?? ''))),
            mb_strtolower(trim((string) ($data['day'] ?? ''))),
            mb_strtolower(trim((string) ($data['day_alias'] ?? ''))),
            mb_strtolower(trim((string) ($data['seat'] ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function blank(?string $value): ?string
    {
        return $value === '' || $value === null ? null : $value;
    }

    private function normalizeHeader(string $header): string
    {
        // Strip a UTF-8 BOM if present (common on CSVs exported from Excel),
        // collapse repeated/non-breaking whitespace, then fold case. This is
        // exact-header matching with cosmetic noise removed — not fuzzy
        // matching: "ITS ID" and "ITSID" still do not match.
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = str_replace("\xC2\xA0", ' ', $header); // non-breaking space
        $header = preg_replace('/\s+/', ' ', trim($header));

        return mb_strtolower($header);
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
