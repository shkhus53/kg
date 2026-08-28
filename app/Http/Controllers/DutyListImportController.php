<?php

namespace App\Http\Controllers;

use App\Models\DutySession;
use App\Services\DutyListImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DutyListImportController extends Controller
{
    public function __construct(private readonly DutyListImportService $importer) {}

    public function create(DutySession $dutySession): View|RedirectResponse
    {
        if ($this->isLockedForImport($dutySession)) {
            return redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'This session is closed — new imports are not allowed.');
        }

        return view('imports.create', ['dutySession' => $dutySession]);
    }

    public function store(Request $request, DutySession $dutySession): View|RedirectResponse
    {
        if ($this->isLockedForImport($dutySession)) {
            return redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'This session is closed — new imports are not allowed.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $file = $request->file('file');

        $parsed = $this->importer->parse($file);

        if (! empty($parsed['missingColumns'])) {
            return back()->withErrors([
                'file' => 'The file is missing required column(s): '.implode(', ', $parsed['missingColumns']).'. Import was not started.',
            ]);
        }

        $preview = $this->importer->buildPreview($dutySession, $parsed['rows']);

        $token = (string) Str::uuid();

        Cache::put("import_preview:{$token}", [
            'duty_session_id' => $dutySession->id,
            'uploaded_by' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'valid_rows' => $preview['valid'],
            'summary' => $preview,
        ], now()->addMinutes(30));

        return view('imports.preview', [
            'dutySession' => $dutySession,
            'preview' => $preview,
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    public function confirm(Request $request, DutySession $dutySession, string $token): RedirectResponse
    {
        if ($this->isLockedForImport($dutySession)) {
            return redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'This session is closed — new imports are not allowed.');
        }

        $cacheKey = "import_preview:{$token}";
        $cached = Cache::get($cacheKey);

        if (! $cached || (int) $cached['duty_session_id'] !== $dutySession->id) {
            return redirect()
                ->route('sessions.imports.create', $dutySession)
                ->withErrors(['file' => 'This import preview has expired. Please upload the file again.']);
        }

        $batch = $this->importer->commit(
            $dutySession,
            $cached['valid_rows'],
            $request->user(),
            $cached['original_filename'],
            $cached['file_type'],
            $cached['summary'],
        );

        Cache::forget($cacheKey);

        return redirect()
            ->route('sessions.show', $dutySession)
            ->with('status', "Import complete: {$batch->valid_rows} duty assignment(s) created from \"{$batch->original_filename}\".");
    }

    private function isLockedForImport(DutySession $dutySession): bool
    {
        return in_array($dutySession->status, ['closing', 'closed'], true);
    }
}
