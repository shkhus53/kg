<?php

namespace App\Services;

use App\Models\MasterDataChangeLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One shared, append-only audit writer for every master-data entity
 * (Department/Miqaat/Event/Venue), mirroring the existing
 * KhidmatguzarChangeLog pattern instead of a per-entity mechanism.
 * Never updates or deletes a row it previously wrote — only ever inserts.
 */
class MasterDataAuditService
{
    /**
     * Diff two attribute arrays and log one row per field that actually
     * changed. Fields absent from $after are ignored (a partial update
     * that never touched a field is not "changed to null").
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    public function logUpdate(string $entityType, int $entityId, array $before, array $after, User $actor): void
    {
        $now = now();

        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;

            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            MasterDataChangeLog::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field' => $field,
                'old_value' => $oldValue === null ? null : (string) $oldValue,
                'new_value' => $newValue === null ? null : (string) $newValue,
                'changed_by' => $actor->id,
                'changed_at' => $now,
            ]);
        }
    }

    /**
     * A single lifecycle event (created / activated / deactivated) that
     * doesn't map to one specific field — recorded with a synthetic
     * "status" field so it appears in the same timeline as field edits.
     */
    public function logLifecycle(string $entityType, int $entityId, string $event, User $actor): void
    {
        MasterDataChangeLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => 'status',
            'old_value' => null,
            'new_value' => $event,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    /**
     * @return Collection<int,MasterDataChangeLog>
     */
    public function history(string $entityType, int $entityId)
    {
        return MasterDataChangeLog::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('changedBy')
            ->orderByDesc('changed_at')
            ->get();
    }
}
