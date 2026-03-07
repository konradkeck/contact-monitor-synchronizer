<?php

namespace App\Exporters;

use App\Exporters\Normalizers\DiscordNormalizer;
use App\Exporters\Normalizers\ImapNormalizer;
use App\Exporters\Normalizers\MetricsCubeNormalizer;
use App\Exporters\Normalizers\SlackNormalizer;
use App\Exporters\Normalizers\WhmcsNormalizer;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactMonitorExporter
{
    private const BATCH_SIZE = 250;

    private ContactMonitorClient $client;
    private mixed $log;

    public function __construct(callable $log)
    {
        $this->client = new ContactMonitorClient();
        $this->log    = $log;
    }

    /**
     * Export all active connections to Contact Monitor.
     */
    public function exportAll(): void
    {
        $connections = Connection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            try {
                $this->exportConnection($connection);
            } catch (\Throwable $e) {
                ($this->log)("ERROR exporting {$connection->type}/{$connection->system_slug}: {$e->getMessage()}", 'error');
            }
        }
    }

    /**
     * Export a single connection.
     */
    public function exportConnection(Connection $connection): void
    {
        $slug = $connection->system_slug;
        $type = $connection->type;

        ($this->log)("Exporting {$type}/{$slug}...");

        match ($type) {
            'whmcs'       => $this->exportWhmcs($connection),
            'imap'        => $this->exportImap($connection),
            'slack'       => $this->exportSlack($connection),
            'discord'     => $this->exportDiscord($connection),
            'gmail'       => ($this->log)("Gmail export: skipped in local mode (configure when needed)."),
            'metricscube' => $this->exportMetricsCube($connection),
            default       => ($this->log)("Unknown connection type: {$type}, skipping.", 'warning'),
        };
    }

    // -------------------------------------------------------------------------
    // Per-source exporters
    // -------------------------------------------------------------------------

    private function exportWhmcs(Connection $connection): void
    {
        $slug       = $connection->system_slug;
        $normalizer = new WhmcsNormalizer($slug);

        // Clients → accounts + primary email identities
        $cursor = $this->getCursor($slug, 'whmcs_clients');
        $this->streamItems($normalizer->normalizeClients($cursor, $this->log), 'whmcs', $slug, 'whmcs_clients', $cursor);

        // Contacts → additional email identities
        $cursor = $this->getCursor($slug, 'whmcs_contacts');
        $this->streamItems($normalizer->normalizeContacts($cursor, $this->log), 'whmcs', $slug, 'whmcs_contacts', $cursor);

        // Tickets → conversations + messages
        $cursor = $this->getCursor($slug, 'whmcs_tickets');
        $this->streamItems($normalizer->normalizeTickets($cursor, $this->log), 'whmcs', $slug, 'whmcs_tickets', $cursor);
    }

    private function exportImap(Connection $connection): void
    {
        $slug       = $connection->system_slug;
        $normalizer = new ImapNormalizer($slug);

        $cursor = $this->getCursor($slug, 'imap_messages');
        $this->streamItems($normalizer->normalize($cursor, $this->log), 'imap', $slug, 'imap_messages', $cursor);

        // Activities: one per email thread
        $cursor = $this->getCursor($slug, 'imap_activities');
        $this->streamItems($normalizer->normalizeActivities($cursor, $this->log), 'imap', $slug, 'imap_activities', $cursor);
    }

    private function exportSlack(Connection $connection): void
    {
        $slug       = $connection->system_slug;
        $normalizer = new SlackNormalizer($slug);

        // Channels → conversations
        $cursor = $this->getCursor($slug, 'slack_channels');
        $this->streamItems($normalizer->normalizeChannels($cursor, $this->log), 'slack', $slug, 'slack_channels', $cursor);

        // Messages
        $cursor = $this->getCursor($slug, 'slack_messages');
        $this->streamItems($normalizer->normalizeMessages($cursor, $this->log), 'slack', $slug, 'slack_messages', $cursor);

        // Activities: one per channel per day
        $cursor = $this->getCursor($slug, 'slack_activities');
        $this->streamItems($normalizer->normalizeActivities($cursor, $this->log), 'slack', $slug, 'slack_activities', $cursor);
    }

    private function exportDiscord(Connection $connection): void
    {
        $slug       = $connection->system_slug;
        $normalizer = new DiscordNormalizer($slug);

        // Members → identities (all guild members, not just message senders)
        $cursor = $this->getCursor($slug, 'discord_members');
        $this->streamItems($normalizer->normalizeMembers($cursor, $this->log), 'discord', $slug, 'discord_members', $cursor);

        // Channels → conversations
        $cursor = $this->getCursor($slug, 'discord_channels');
        $this->streamItems($normalizer->normalizeChannels($cursor, $this->log), 'discord', $slug, 'discord_channels', $cursor);

        // Messages
        $cursor = $this->getCursor($slug, 'discord_messages');
        $this->streamItems($normalizer->normalizeMessages($cursor, $this->log), 'discord', $slug, 'discord_messages', $cursor);

        // Activities: one per channel per day
        $cursor = $this->getCursor($slug, 'discord_activities');
        $this->streamItems($normalizer->normalizeActivities($cursor, $this->log), 'discord', $slug, 'discord_activities', $cursor);
    }

    private function exportMetricsCube(Connection $connection): void
    {
        $slug = $connection->system_slug;

        // MetricsCube is linked to a WHMCS connection via settings.whmcs_connection_id
        $settings   = $connection->settings ?? [];
        $whmcsConn  = isset($settings['whmcs_connection_id'])
            ? \App\Models\Connection::find($settings['whmcs_connection_id'])
            : null;
        $whmcsSlug  = $whmcsConn?->system_slug ?? $slug;

        $normalizer = new MetricsCubeNormalizer($slug, $whmcsSlug);

        $cursor = $this->getCursor($slug, 'mc_activities');
        $this->streamItems($normalizer->normalizeActivities($cursor, $this->log), 'metricscube', $slug, 'mc_activities', $cursor);
    }

    // -------------------------------------------------------------------------
    // Streaming: collect items from generator, send in BATCH_SIZE chunks
    // -------------------------------------------------------------------------

    private function streamItems(
        \Generator $generator,
        string     $sourceType,
        string     $sourceSlug,
        string     $entity,
        ?string    $startCursor,
    ): void {
        $batch       = [];
        $maxUpdatedAt = $startCursor;
        $totalSent   = 0;

        $flushBatch = function () use (&$batch, $sourceType, $sourceSlug, &$totalSent, $entity, &$maxUpdatedAt) {
            if (empty($batch)) {
                return;
            }

            $batchPayload = [
                'batch_id'    => (string) Str::uuid(),
                'source_type' => $sourceType,
                'source_slug' => $sourceSlug,
                'items'       => array_column($batch, 'item'),
            ];

            $stats = $this->client->sendBatch($batchPayload);
            $totalSent += count($batch);

            ($this->log)(sprintf(
                "  [%s/%s %s] batch sent: %d items → processed=%d skipped=%d failed=%d (total sent: %d)",
                $sourceType, $sourceSlug, $entity,
                count($batch),
                $stats['processed'] ?? 0,
                $stats['skipped'] ?? 0,
                $stats['failed'] ?? 0,
                $totalSent,
            ));

            // Update cursor to the max updated_at in this batch
            $batchMax = max(array_column($batch, 'updated_at'));
            if ($batchMax > $maxUpdatedAt) {
                $maxUpdatedAt = $batchMax;
                $this->saveCursor($sourceSlug, $entity, $maxUpdatedAt);
            }

            $batch = [];
        };

        foreach ($generator as $entry) {
            $batch[] = $entry;

            if (count($batch) >= self::BATCH_SIZE) {
                $flushBatch();
            }
        }

        $flushBatch(); // final partial batch

        if ($totalSent === 0) {
            ($this->log)("  [{$sourceType}/{$sourceSlug} {$entity}] No new records to export.");
        }
    }

    // -------------------------------------------------------------------------
    // Cursor helpers (stored in import_checkpoints table)
    // -------------------------------------------------------------------------

    private function getCursor(string $systemSlug, string $entity): ?string
    {
        $row = DB::table('import_checkpoints')
            ->where('source_system', $systemSlug)
            ->where('importer', 'salesos_export')
            ->where('entity', $entity)
            ->first();

        if (!$row) {
            return null;
        }

        $meta = json_decode($row->cursor_meta ?? '{}', true);
        return $meta['last_updated_at'] ?? null;
    }

    private function saveCursor(string $systemSlug, string $entity, string $updatedAt): void
    {
        $now  = now();
        $data = [
            'last_processed_id' => 0,
            'cursor_meta'       => json_encode(['last_updated_at' => $updatedAt]),
            'last_run_at'       => $now,
            'updated_at'        => $now,
        ];

        $exists = DB::table('import_checkpoints')
            ->where('source_system', $systemSlug)
            ->where('importer', 'salesos_export')
            ->where('entity', $entity)
            ->exists();

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_system', $systemSlug)
                ->where('importer', 'salesos_export')
                ->where('entity', $entity)
                ->update($data);
        } else {
            DB::table('import_checkpoints')->insert(array_merge($data, [
                'source_system' => $systemSlug,
                'importer'      => 'salesos_export',
                'entity'        => $entity,
                'cursor_type'   => 'updated_at',
                'created_at'    => $now,
            ]));
        }
    }
}
