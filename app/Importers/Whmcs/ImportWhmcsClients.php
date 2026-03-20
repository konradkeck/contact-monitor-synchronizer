<?php

namespace App\Importers\Whmcs;

use App\Importers\MetricsCube\ImportMetricsCubeClientActivity;
use App\Models\Connection;
use App\Support\MetricsCubeConfig;
use App\Support\WhmcsConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportWhmcsClients
{
    public function __construct(private string $system) {}

    public function run(callable $log): void
    {
        $config  = WhmcsConfig::fromSystem($this->system);
        $baseUrl = $config['base_url'];
        $token   = $config['token'];

        // Auto-detect admin_dir from WHMCS module config endpoint and persist it
        $this->syncAdminDirFromWhmcs($baseUrl, $token, $log);

        // MetricsCube is optional — skip gracefully if not configured
        $mcImporter = null;
        try {
            $mcImporter = new ImportMetricsCubeClientActivity($this->system, MetricsCubeConfig::fromSystem($this->system));
        } catch (\RuntimeException $e) {
            $log("MetricsCube not configured, skipping MC sync: " . $e->getMessage(), 'warning');
        }

        $checkpoint = DB::table('import_checkpoints')
            ->where('source_system', $this->system)
            ->where('importer', 'whmcs_api')
            ->where('entity', 'clients')
            ->where('cursor_type', 'after_id')
            ->first();

        $afterId    = $checkpoint ? (int) $checkpoint->last_processed_id : 0;
        $inserted   = 0;
        $updated    = 0;
        $skipped    = 0;
        $mcInserted = 0;
        $mcUpdated  = 0;
        $mcSkipped  = 0;
        $mcErrors   = 0;

        $log("Starting from after_id: {$afterId}");

        while (true) {
            $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->get("{$baseUrl}/modules/addons/contact_monitor_for_whmcs/api.php", [
                    'resource' => 'clients',
                    'limit'    => 1000,
                    'after_id' => $afterId,
                ]);

            $body = $response->json();

            if (($body['ok'] ?? null) !== true) {
                throw new \RuntimeException('WHMCS API error: ' . ($body['error'] ?? $response->status()));
            }

            $clients = $body['data'] ?? [];
            $now     = now();

            foreach ($clients as $record) {
                $sourceRecordId = $record['clientid'];
                $rowHash        = hash('sha256', json_encode($record));
                $existing       = DB::table('source_whmcs_clients')
                    ->where('source_system', $this->system)
                    ->where('source_record_id', $sourceRecordId)
                    ->first();

                $changed = false;

                if ($existing === null) {
                    DB::table('source_whmcs_clients')->insert([
                        'source_system'    => $this->system,
                        'source_record_id' => $sourceRecordId,
                        'row_hash'         => $rowHash,
                        'payload_json'     => json_encode($record),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                    $inserted++;
                    $changed = true;
                } elseif ($existing->row_hash !== $rowHash) {
                    DB::table('source_whmcs_clients')
                        ->where('id', $existing->id)
                        ->update(['row_hash' => $rowHash, 'payload_json' => json_encode($record), 'updated_at' => $now]);
                    $updated++;
                    $changed = true;
                } else {
                    $skipped++;
                }

                if ($mcImporter) {
                    try {
                        $mcResult = $mcImporter->run($sourceRecordId);
                        match ($mcResult) {
                            'inserted' => $mcInserted++,
                            'updated'  => $mcUpdated++,
                            'skipped'  => $mcSkipped++,
                        };
                    } catch (\RuntimeException $e) {
                        $log("MC client {$sourceRecordId}: " . $e->getMessage(), 'warning');
                        $mcErrors++;
                    }
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

        $log("WHMCS: inserted={$inserted} updated={$updated} skipped={$skipped}");

        if ($mcImporter) {
            $log("MetricsCube: inserted={$mcInserted} updated={$mcUpdated} skipped={$mcSkipped} errors={$mcErrors}");
        }
    }

    private function syncAdminDirFromWhmcs(string $baseUrl, string $token, callable $log): void
    {
        try {
            $resp = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->timeout(5)
                ->get(rtrim($baseUrl, '/') . '/modules/addons/contact_monitor_for_whmcs/api.php', [
                    'resource' => 'config',
                ]);

            if (!$resp->successful() || ($resp->json()['ok'] ?? false) !== true) {
                return;
            }

            $adminDir = trim($resp->json()['admin_dir'] ?? 'admin', '/') ?: 'admin';

            $conn = Connection::where('type', 'whmcs')->where('system_slug', $this->system)->first();
            if ($conn) {
                $settings             = $conn->settings ?? [];
                $settings['admin_dir'] = $adminDir;
                $conn->update(['settings' => $settings]);
            }

            $log("WHMCS config: admin_dir={$adminDir}");
        } catch (\Throwable) {
            // Best-effort; don't abort the import if config fetch fails
        }
    }

    private function saveCheckpoint(int $afterId, mixed $now): void
    {
        $exists = DB::table('import_checkpoints')
            ->where('source_system', $this->system)
            ->where('importer', 'whmcs_api')
            ->where('entity', 'clients')
            ->where('cursor_type', 'after_id')
            ->exists();

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_system', $this->system)
                ->where('importer', 'whmcs_api')
                ->where('entity', 'clients')
                ->where('cursor_type', 'after_id')
                ->update(['last_processed_id' => $afterId, 'last_run_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('import_checkpoints')->insert([
                'source_system'     => $this->system,
                'importer'          => 'whmcs_api',
                'entity'            => 'clients',
                'cursor_type'       => 'after_id',
                'last_processed_id' => $afterId,
                'last_run_at'       => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
    }
}
