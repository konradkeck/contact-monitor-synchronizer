<?php

namespace App\Exporters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesOsClient
{
    private string $url;
    private string $secret;

    public function __construct()
    {
        $this->url    = rtrim(config('services.salesos.ingest_url', ''), '/');
        $this->secret = config('services.salesos.ingest_secret', '');

        if (empty($this->url)) {
            throw new \RuntimeException('SALESOS_INGEST_URL is not configured.');
        }
        if (empty($this->secret)) {
            throw new \RuntimeException('SALESOS_INGEST_SECRET is not configured.');
        }
    }

    /**
     * Send a batch to SalesOS.
     * Retries up to 3 times on server errors.
     *
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function sendBatch(array $batch): array
    {
        $endpoint = $this->url . '/api/ingest/batch';
        $maxTries = 3;

        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            $response = Http::withHeader('X-Ingest-Secret', $this->secret)
                ->withHeader('Content-Type', 'application/json')
                ->timeout(60)
                ->post($endpoint, $batch);

            if ($response->successful()) {
                $body = $response->json();

                if ($body['ok'] ?? false) {
                    return $body['data'] ?? [];
                }

                throw new \RuntimeException(
                    'SalesOS returned ok=false: ' . ($body['error'] ?? 'unknown')
                );
            }

            if ($response->status() === 401) {
                throw new \RuntimeException('SalesOS auth failed (401). Check SALESOS_INGEST_SECRET.');
            }

            if ($response->status() === 422) {
                throw new \RuntimeException(
                    'SalesOS rejected batch (422): ' . substr($response->body(), 0, 300)
                );
            }

            // 5xx or network error → retry
            Log::warning("SalesOS ingest attempt {$attempt}/{$maxTries} failed (HTTP {$response->status()}), retrying...");

            if ($attempt < $maxTries) {
                sleep($attempt * 2);
            }
        }

        throw new \RuntimeException(
            "SalesOS ingest failed after {$maxTries} attempts."
        );
    }

    public static function buildIdempotencyKey(
        string $systemType,
        string $systemSlug,
        string $itemType,
        string $externalId,
        string $payloadHash
    ): string {
        return hash('sha256', "{$systemType}:{$systemSlug}:{$itemType}:{$externalId}:{$payloadHash}");
    }

    public static function buildPayloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function buildItem(
        string $systemType,
        string $systemSlug,
        string $itemType,
        string $action,
        string $externalId,
        array  $payload
    ): array {
        $payloadHash    = self::buildPayloadHash($payload);
        $idempotencyKey = self::buildIdempotencyKey($systemType, $systemSlug, $itemType, $externalId, $payloadHash);

        return [
            'idempotency_key' => $idempotencyKey,
            'type'            => $itemType,
            'action'          => $action,
            'system_type'     => $systemType,
            'system_slug'     => $systemSlug,
            'external_id'     => $externalId,
            'payload_hash'    => $payloadHash,
            'payload'         => $payload,
        ];
    }
}
