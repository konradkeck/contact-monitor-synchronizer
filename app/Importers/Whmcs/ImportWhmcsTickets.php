<?php

namespace App\Importers\Whmcs;

use App\Support\WhmcsConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportWhmcsTickets
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
            ->where('entity', 'tickets')
            ->where('cursor_type', 'after_sent_at')
            ->first();

        $afterSentAt   = $checkpoint ? (json_decode($checkpoint->cursor_meta ?? '{}', true)['after_sent_at'] ?? '') : '';
        $afterTicketId = $checkpoint ? (int) $checkpoint->last_processed_id : 0;
        $inserted      = 0;
        $updated       = 0;
        $skipped       = 0;

        $log("Starting from after_sent_at: " . ($afterSentAt ?: '(beginning)') . ", after_ticket_id: {$afterTicketId}");

        while (true) {
            $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->get("{$baseUrl}/modules/addons/salesos_synch_api/api.php", [
                    'resource'        => 'tickets',
                    'limit'           => 200,
                    'after_sent_at'   => $afterSentAt,
                    'after_ticket_id' => $afterTicketId,
                ]);

            $body = $response->json();

            if (($body['ok'] ?? null) !== true) {
                throw new \RuntimeException('WHMCS API error: ' . ($body['error'] ?? $response->status()));
            }

            $tickets = $body['data'] ?? [];
            $now     = now();

            foreach ($tickets as $record) {
                $sourceRecordId = $record['msg_id'];
                $rowHash        = hash('sha256', json_encode($record));
                $existing       = DB::table('source_whmcs_tickets')
                    ->where('source_system', $this->system)
                    ->where('source_record_id', $sourceRecordId)
                    ->first();

                if ($existing === null) {
                    DB::table('source_whmcs_tickets')->insert([
                        'source_system'    => $this->system,
                        'source_record_id' => $sourceRecordId,
                        'row_hash'         => $rowHash,
                        'payload_json'     => json_encode($record),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                    $inserted++;
                } elseif ($existing->row_hash !== $rowHash) {
                    DB::table('source_whmcs_tickets')
                        ->where('id', $existing->id)
                        ->update(['row_hash' => $rowHash, 'payload_json' => json_encode($record), 'updated_at' => $now]);
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            $nextCursor        = $body['next_cursor'] ?? null;
            $nextAfterSentAt   = $nextCursor['after_sent_at'] ?? null;
            $nextAfterTicketId = $nextCursor['after_ticket_id'] ?? null;

            $this->saveCheckpoint($afterTicketId, $afterSentAt, $now);

            if (
                $nextCursor === null ||
                $nextAfterSentAt < $afterSentAt ||
                ($nextAfterSentAt === $afterSentAt && $nextAfterTicketId <= $afterTicketId)
            ) {
                break;
            }

            $afterSentAt   = $nextAfterSentAt;
            $afterTicketId = $nextAfterTicketId;
        }

        $log("Inserted: {$inserted} Updated: {$updated} Skipped: {$skipped}");
    }

    private function saveCheckpoint(int $afterTicketId, string $afterSentAt, mixed $now): void
    {
        $exists = DB::table('import_checkpoints')
            ->where('source_system', $this->system)
            ->where('importer', 'whmcs_api')
            ->where('entity', 'tickets')
            ->where('cursor_type', 'after_sent_at')
            ->exists();

        $data = [
            'last_processed_id' => $afterTicketId,
            'cursor_meta'       => json_encode(['after_sent_at' => $afterSentAt]),
            'last_run_at'       => $now,
            'updated_at'        => $now,
        ];

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_system', $this->system)
                ->where('importer', 'whmcs_api')
                ->where('entity', 'tickets')
                ->where('cursor_type', 'after_sent_at')
                ->update($data);
        } else {
            DB::table('import_checkpoints')->insert(array_merge($data, [
                'source_system' => $this->system,
                'importer'      => 'whmcs_api',
                'entity'        => 'tickets',
                'cursor_type'   => 'after_sent_at',
                'created_at'    => $now,
            ]));
        }
    }
}
