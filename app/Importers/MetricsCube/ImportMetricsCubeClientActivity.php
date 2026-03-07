<?php

namespace App\Importers\MetricsCube;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportMetricsCubeClientActivity
{
    /**
     * @param  string $system  Lowercase system slug, e.g. "modulesgarden"
     * @param  array  $config  From MetricsCubeConfig::fromSystem()
     */
    public function __construct(
        private string $system,
        private array  $config,
    ) {}

    /**
     * Fetch and upsert activity snapshot for one client.
     *
     * @param  string|int $clientId  WHMCS CLIENT_ID
     * @return string  'inserted' | 'updated' | 'skipped'
     *
     * @throws \RuntimeException on API error or unexpected response shape
     */
    public function run(string|int $clientId): string
    {
        $response = Http::asForm()->timeout(30)->post($this->config['base_url'], [
            'METRICSCUBE_VERSION'          => '3.2.0',
            'CONNECTOR_TYPE'               => 'WHMCS',
            'METRICSCUBE_APP_KEY'          => $this->config['app_key'],
            'METRICSCUBE_CONNECTOR_KEY'    => $this->config['connector_key'],
            'METRICSCUBE_CONNECTOR_ACTION' => 'GET_CLIENT_ACTIVITY',
            'CLIENT_ID'                    => $clientId,
        ]);

        $body = $response->json();

        if (($body['status'] ?? '') !== 'success') {
            $msg = $body['message'] ?? $body['status'] ?? 'unknown';
            throw new \RuntimeException(
                "MetricsCube API error for client {$clientId}: {$msg}"
            );
        }

        $payload = $body['data']['activity'] ?? null;

        if ($payload === null) {
            throw new \RuntimeException(
                "MetricsCube response missing data.activity for client {$clientId}"
            );
        }

        $rowHash = hash('sha256', json_encode($payload));
        $now     = now();

        $existing = DB::table('source_metricscube_client_activities')
            ->where('system', $this->system)
            ->where('external_id', (string) $clientId)
            ->first();

        if ($existing === null) {
            DB::table('source_metricscube_client_activities')->insert([
                'system'       => $this->system,
                'external_id'  => (string) $clientId,
                'row_hash'     => $rowHash,
                'payload_json' => json_encode($payload),
                'fetched_at'   => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            return 'inserted';
        }

        if ($existing->row_hash === $rowHash) {
            return 'skipped';
        }

        DB::table('source_metricscube_client_activities')
            ->where('id', $existing->id)
            ->update([
                'row_hash'     => $rowHash,
                'payload_json' => json_encode($payload),
                'fetched_at'   => $now,
                'updated_at'   => $now,
            ]);

        return 'updated';
    }
}
