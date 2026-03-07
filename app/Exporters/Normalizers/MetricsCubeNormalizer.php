<?php

namespace App\Exporters\Normalizers;

use App\Exporters\SalesOsClient;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes MetricsCube activity data to SalesOS canonical items.
 *
 * Emits:
 *   activity — one per MetricsCube activity entry, linked to the WHMCS account
 */
class MetricsCubeNormalizer
{
    /** MetricsCube TYPE → SalesOS activity_type */
    private const TYPE_MAP = [
        'Paid Invoice'        => 'payment',
        'New Transaction'     => 'payment',
        'Renewal'             => 'renewal',
        'New Unsuspension'    => 'renewal',
        'Cancellation Request' => 'cancellation',
        'New Suspension'      => 'cancellation',
        'Opened Ticket'       => 'conversation',
        'Ticket Replied'      => 'conversation',
        'New Order'           => 'note',
        'New Service'         => 'note',
    ];

    private string $systemSlug;
    private string $whmcsSlug; // WHMCS system slug to link accounts

    public function __construct(string $systemSlug, string $whmcsSlug)
    {
        $this->systemSlug = $systemSlug;
        $this->whmcsSlug  = $whmcsSlug;
    }

    /**
     * Yield canonical activity items from source_metricscube_client_activities updated since $sinceAt.
     */
    public function normalizeActivities(?string $sinceAt, callable $log): \Generator
    {
        // source_metricscube_client_activities.system = WHMCS system slug (set by the importer)
        $query = DB::table('source_metricscube_client_activities')
            ->where('system', $this->whmcsSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $payload = json_decode($row->payload_json, true);
            $clientId = $row->external_id;

            // Activity list is in payload['data'] or the payload itself is an array of events
            $activities = [];
            if (isset($payload['draw'])) {
                // Format: {draw: N, data: [...events...]}
                $activities = $payload['data'] ?? [];
            } elseif (is_array($payload) && isset($payload[0])) {
                $activities = $payload;
            }

            foreach ($activities as $idx => $event) {
                $activityType = self::TYPE_MAP[$event['TYPE'] ?? ''] ?? 'note';
                $timestamp    = isset($event['DATETIME_TIMESTAMP'])
                    ? date('Y-m-d\TH:i:s\Z', (int) $event['DATETIME_TIMESTAMP'])
                    : null;

                if (!$timestamp) {
                    continue;
                }

                // Unique external_id: clientId + activity type + timestamp + index
                $externalId = "mc_{$clientId}_{$event['TYPE']}_{$event['DATETIME_TIMESTAMP']}_{$idx}";

                $actPayload = [
                    'activity_type'        => $activityType,
                    // Account is stored under whmcs system_type; pass both for ActivityProcessor
                    'account_system_type'  => 'whmcs',
                    'account_system_slug'  => $this->whmcsSlug,
                    'account_external_id'  => $clientId,
                    'description'          => $event['DESCRIPTION'] ?? null,
                    'occurred_at'          => $timestamp,
                    'meta'                 => [
                        'mc_type'     => $event['TYPE'] ?? null,
                        'relation_id' => $event['RELATION_ID'] ?? null,
                        'customer'    => $event['CUSTOMER'] ?? null,
                        'app'         => $event['APP'] ?? null,
                    ],
                ];

                yield [
                    'item'       => SalesOsClient::buildItem('metricscube', $this->systemSlug, 'activity', 'upsert', $externalId, $actPayload),
                    'updated_at' => $row->updated_at,
                ];
            }
        }
    }
}
