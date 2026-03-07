<?php

namespace App\Importers\Whmcs;

use App\Support\WhmcsConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportWhmcsContacts
{
    public function __construct(private string $system) {}

    public function run(callable $log): void
    {
        $config  = WhmcsConfig::fromSystem($this->system);
        $baseUrl = $config['base_url'];
        $token   = $config['token'];

        $checkpoint = DB::table('import_checkpoints')
            ->where('source_system', $this->system)
            ->where('importer', 'whmcs_api')
            ->where('entity', 'contacts')
            ->where('cursor_type', 'after_id')
            ->first();

        $afterId  = $checkpoint ? (int) $checkpoint->last_processed_id : 0;
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $log("Starting from after_id: {$afterId}");

        while (true) {
            $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->get("{$baseUrl}/modules/addons/salesos_synch_api/api.php", [
                    'resource' => 'contacts',
                    'limit'    => 1000,
                    'after_id' => $afterId,
                ]);

            $body = $response->json();

            if (($body['ok'] ?? null) !== true) {
                throw new \RuntimeException('WHMCS API error: ' . ($body['error'] ?? $response->status()));
            }

            $contacts = $body['data'] ?? [];
            $now      = now();

            foreach ($contacts as $record) {
                $sourceRecordId = $record['contactid'];
                $rowHash        = hash('sha256', json_encode($record));
                $existing       = DB::table('source_whmcs_contacts')
                    ->where('source_system', $this->system)
                    ->where('source_record_id', $sourceRecordId)
                    ->first();

                if ($existing === null) {
                    DB::table('source_whmcs_contacts')->insert([
                        'source_system'    => $this->system,
                        'source_record_id' => $sourceRecordId,
                        'row_hash'         => $rowHash,
                        'payload_json'     => json_encode($record),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                    $inserted++;
                } elseif ($existing->row_hash !== $rowHash) {
                    DB::table('source_whmcs_contacts')
                        ->where('id', $existing->id)
                        ->update(['row_hash' => $rowHash, 'payload_json' => json_encode($record), 'updated_at' => $now]);
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            $nextCursor  = $body['next_cursor'] ?? null;
            $nextAfterId = $nextCursor['after_id'] ?? null;

            $this->saveCheckpoint($afterId, $now);

            if ($nextCursor === null || $nextAfterId <= $afterId) {
                break;
            }

            $afterId = $nextAfterId;
        }

        $log("Inserted: {$inserted} Updated: {$updated} Skipped: {$skipped}");
    }

    private function saveCheckpoint(int $afterId, mixed $now): void
    {
        $exists = DB::table('import_checkpoints')
            ->where('source_system', $this->system)
            ->where('importer', 'whmcs_api')
            ->where('entity', 'contacts')
            ->where('cursor_type', 'after_id')
            ->exists();

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_system', $this->system)
                ->where('importer', 'whmcs_api')
                ->where('entity', 'contacts')
                ->where('cursor_type', 'after_id')
                ->update(['last_processed_id' => $afterId, 'last_run_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('import_checkpoints')->insert([
                'source_system'     => $this->system,
                'importer'          => 'whmcs_api',
                'entity'            => 'contacts',
                'cursor_type'       => 'after_id',
                'last_processed_id' => $afterId,
                'last_run_at'       => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
    }
}
