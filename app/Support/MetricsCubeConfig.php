<?php

namespace App\Support;

use App\Models\Connection;

class MetricsCubeConfig
{
    public const BASE_URL = 'https://api.metricscube.io/api/connector/whmcs';

    /**
     * Find the MetricsCube connection linked to a given WHMCS system slug
     * and return its credentials.
     */
    public static function fromSystem(string $whmcsSystem): array
    {
        $whmcsConnection = Connection::where('type', 'whmcs')
            ->where('system_slug', $whmcsSystem)
            ->first();

        if (!$whmcsConnection) {
            throw new \RuntimeException("No WHMCS connection found with slug: {$whmcsSystem}");
        }

        $mcConnection = Connection::where('type', 'metricscube')
            ->get()
            ->first(fn ($c) => (int) ($c->settings['whmcs_connection_id'] ?? 0) === $whmcsConnection->id);

        if (!$mcConnection) {
            throw new \RuntimeException("No MetricsCube connection linked to WHMCS '{$whmcsSystem}'.");
        }

        $s            = $mcConnection->settings ?? [];
        $appKey       = $s['app_key'] ?? null;
        $connectorKey = $s['connector_key'] ?? null;

        if (!$appKey) {
            throw new \RuntimeException("MetricsCube connection linked to '{$whmcsSystem}' is missing app_key.");
        }
        if (!$connectorKey) {
            throw new \RuntimeException("MetricsCube connection linked to '{$whmcsSystem}' is missing connector_key.");
        }

        return [
            'base_url'      => self::BASE_URL,
            'app_key'       => $appKey,
            'connector_key' => $connectorKey,
        ];
    }
}
