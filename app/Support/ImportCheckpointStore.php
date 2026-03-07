<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ImportCheckpointStore
{
    /**
     * Retrieve checkpoint data by key.
     * Returns null if no checkpoint exists.
     */
    public function get(string $key): array|null
    {
        $row = DB::table('import_checkpoints')
            ->where('source_system', 'checkpoint_store')
            ->where('importer', 'v1')
            ->where('entity', 'v1')
            ->where('cursor_type', md5($key))
            ->first();

        if (!$row || !$row->cursor_meta) {
            return null;
        }

        return json_decode($row->cursor_meta, true);
    }

    /**
     * Save or update checkpoint data by key.
     */
    public function put(string $key, array $data): void
    {
        $now  = now();
        $hash = md5($key);

        DB::table('import_checkpoints')->upsert(
            [
                'source_system'     => 'checkpoint_store',
                'importer'          => 'v1',
                'entity'            => 'v1',
                'cursor_type'       => $hash,
                'cursor_meta'       => json_encode($data),
                'last_processed_id' => 0,
                'last_run_at'       => $data['last_run_at'] ?? $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            ['source_system', 'importer', 'entity', 'cursor_type'],
            ['cursor_meta', 'last_run_at', 'updated_at'],
        );
    }

    /**
     * Delete checkpoint for the given key.
     */
    public function forget(string $key): void
    {
        DB::table('import_checkpoints')
            ->where('source_system', 'checkpoint_store')
            ->where('importer', 'v1')
            ->where('entity', 'v1')
            ->where('cursor_type', md5($key))
            ->delete();
    }
}
