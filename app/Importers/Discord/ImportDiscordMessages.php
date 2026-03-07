<?php

namespace App\Importers\Discord;

use App\Support\ImportCheckpointStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportDiscordMessages
{
    private const BASE_URL = 'https://discord.com/api/v10';

    /** @var callable|null */
    private $log = null;

    public function __construct(
        private string $system,
        private string $botToken,
        private array  $guildAllowlist    = [],
        private array  $channelAllowlist  = [],
        private bool   $includeThreads    = true,
        private int    $maxMessagesPerRun = 0,
        private string $mode              = 'partial',
    ) {}

    public function run(callable $log): void
    {
        $this->log = $log;

        $log("Discord import starting — mode={$this->mode}, system={$this->system}");

        $guilds = $this->fetchGuilds();

        if (empty($guilds)) {
            $log('No guilds found (or none matched allowlist). Nothing to import.');
            return;
        }

        $totalMessages = 0;
        $limitReached  = false;

        foreach ($guilds as $guild) {
            if ($limitReached) {
                break;
            }

            $log("Guild: {$guild['name']} ({$guild['id']})");

            $channels = $this->fetchChannels($guild);

            if (empty($channels)) {
                $log("  No importable channels found.");
                continue;
            }

            foreach ($channels as $channel) {
                if ($limitReached) {
                    break;
                }

                $count = $this->importChannel($guild, $channel, $totalMessages);
                $totalMessages += $count;

                if ($this->maxMessagesPerRun > 0 && $totalMessages >= $this->maxMessagesPerRun) {
                    $log("Max messages per run ({$this->maxMessagesPerRun}) reached, stopping.", 'warning');
                    $limitReached = true;
                }
            }
        }

        $log("Discord import complete. Total messages processed: {$totalMessages}.");
    }

    // -------------------------------------------------------------------------
    // Fetching
    // -------------------------------------------------------------------------

    private function fetchGuilds(): array
    {
        ($this->log)('Fetching guild list...');

        $guilds = $this->apiGet('/users/@me/guilds');

        if (!empty($this->guildAllowlist)) {
            $guilds = array_values(array_filter(
                $guilds,
                fn ($g) => in_array($g['id'], $this->guildAllowlist, true)
            ));
            ($this->log)('After allowlist filter: ' . count($guilds) . ' guild(s).');
        } else {
            ($this->log)('Found ' . count($guilds) . ' guild(s).');
        }

        return $guilds;
    }

    private function fetchChannels(array $guild): array
    {
        $guildId  = $guild['id'];
        $channels = $this->apiGet("/guilds/{$guildId}/channels");

        // Keep only private text channels (VIEW_CHANNEL denied for @everyone)
        $channels = array_filter($channels, function ($ch) use ($guildId) {
            $type = (int) ($ch['type'] ?? -1);

            if ($type === 12) return true; // PRIVATE_THREAD — always private by type

            $isText = $type === 0 || $type === 5; // GUILD_TEXT, GUILD_ANNOUNCEMENT
            if ($this->includeThreads && $type === 11) $isText = true; // PUBLIC_THREAD

            if (!$isText) return false;

            return $this->isPrivateChannel($ch, $guildId);
        });

        if (!empty($this->channelAllowlist)) {
            $channels = array_filter(
                $channels,
                fn ($ch) => in_array($ch['id'], $this->channelAllowlist, true)
            );
        }

        $channels = array_values($channels);

        if (empty($channels)) {
            ($this->log)('  No private channels found.');
            return [];
        }

        // Pre-check read access — skip channels where bot lacks READ_MESSAGE_HISTORY
        $accessible = [];
        $denied     = [];
        foreach ($channels as $channel) {
            if ($this->canReadMessages($channel['id'])) {
                $accessible[] = $channel;
                $this->upsertChannel($guildId, $channel);
            } else {
                $denied[] = '#' . ($channel['name'] ?? $channel['id']);
            }
        }

        if (!empty($denied)) {
            ($this->log)(sprintf(
                '  %d channel(s) skipped (no READ_MESSAGE_HISTORY): %s',
                count($denied),
                implode(', ', $denied)
            ), 'warning');
        }

        ($this->log)(sprintf('  %d private channel(s) accessible.', count($accessible)));

        return $accessible;
    }

    /**
     * A channel is private if the @everyone role (id = guild_id) has VIEW_CHANNEL (bit 1024) denied.
     */
    private function isPrivateChannel(array $channel, string $guildId): bool
    {
        foreach ($channel['permission_overwrites'] ?? [] as $ow) {
            if ((string) ($ow['id'] ?? '') === $guildId && (int) ($ow['type'] ?? -1) === 0) {
                return (bool) ((int) ($ow['deny'] ?? 0) & 1024);
            }
        }
        return false; // No @everyone overwrite = public channel
    }

    /**
     * Quick check: can the bot read at least 1 message from this channel?
     */
    private function canReadMessages(string $channelId): bool
    {
        $response = Http::withHeaders(['Authorization' => "Bot {$this->botToken}"])
            ->timeout(10)
            ->get(self::BASE_URL . "/channels/{$channelId}/messages", ['limit' => 1]);

        return $response->successful();
    }

    // -------------------------------------------------------------------------
    // Channel import
    // -------------------------------------------------------------------------

    private function importChannel(array $guild, array $channel, int $runningTotal): int
    {
        $guildId     = $guild['id'];
        $channelId   = $channel['id'];
        $channelName = $channel['name'] ?? $channelId;

        $checkpoints   = app(ImportCheckpointStore::class);
        $checkpointKey = "discord|messages|{$this->system}|{$channelId}";
        $checkpoint    = $checkpoints->get($checkpointKey);
        $lastMessageId = $checkpoint['last_message_id'] ?? null;

        $newestMessageId = null;
        $count           = 0;
        $inserted        = 0;
        $updated         = 0;
        $skipped         = 0;

        if ($this->mode === 'full') {
            ($this->log)("  #{$channelName}: full sync (all history)...");
            [$count, $inserted, $updated, $skipped, $newestMessageId] =
                $this->importFull($guildId, $channelId, $runningTotal);
        } else {
            if (!$lastMessageId) {
                ($this->log)("  #{$channelName}: no checkpoint, falling back to full scan...");
                [$count, $inserted, $updated, $skipped, $newestMessageId] =
                    $this->importFull($guildId, $channelId, $runningTotal);
            } else {
                ($this->log)("  #{$channelName}: partial sync after {$lastMessageId}...");
                [$count, $inserted, $updated, $skipped, $newestMessageId] =
                    $this->importPartial($guildId, $channelId, $lastMessageId, $runningTotal);
            }
        }

        ($this->log)("  #{$channelName}: {$count} processed ({$inserted} new, {$updated} updated, {$skipped} unchanged).");

        if ($newestMessageId !== null) {
            $checkpoints->put($checkpointKey, [
                'last_message_id' => $newestMessageId,
                'last_run_at'     => now()->toIso8601String(),
            ]);
        }

        return $count;
    }

    /**
     * Full import: paginate backward using before= (Discord returns descending by default).
     * Returns [count, inserted, updated, skipped, newestMessageId].
     */
    private function importFull(string $guildId, string $channelId, int $runningTotal): array
    {
        $count           = 0;
        $inserted        = 0;
        $updated         = 0;
        $skipped         = 0;
        $newestMessageId = null;
        $beforeId        = null;

        while (true) {
            $params = ['limit' => 100];
            if ($beforeId !== null) {
                $params['before'] = $beforeId;
            }

            $messages = $this->apiGet('/channels/' . $channelId . '/messages?' . http_build_query($params));

            if (empty($messages)) {
                break;
            }

            // First message in first page is newest (Discord returns descending)
            if ($newestMessageId === null) {
                $newestMessageId = $messages[0]['id'];
            }

            foreach ($messages as $message) {
                $result = $this->upsertMessage($guildId, $channelId, $message);
                $count++;
                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };

                if ($this->maxMessagesPerRun > 0 && ($runningTotal + $count) >= $this->maxMessagesPerRun) {
                    return [$count, $inserted, $updated, $skipped, $newestMessageId];
                }
            }

            // Next page: before the oldest message in this page
            $beforeId = end($messages)['id'];

            if (count($messages) < 100) {
                break; // Last page
            }
        }

        return [$count, $inserted, $updated, $skipped, $newestMessageId];
    }

    /**
     * Partial import: paginate forward using after= (Discord returns ascending when after= used).
     * Returns [count, inserted, updated, skipped, newestMessageId].
     */
    private function importPartial(string $guildId, string $channelId, string $afterId, int $runningTotal): array
    {
        $count           = 0;
        $inserted        = 0;
        $updated         = 0;
        $skipped         = 0;
        $newestMessageId = null;
        $currentAfter    = $afterId;

        while (true) {
            $params   = ['limit' => 100, 'after' => $currentAfter];
            $messages = $this->apiGet('/channels/' . $channelId . '/messages?' . http_build_query($params));

            if (empty($messages)) {
                break;
            }

            // Discord returns ascending order when using after=, so last = newest
            $newestInBatch   = end($messages)['id'];
            $newestMessageId = $newestInBatch;

            foreach ($messages as $message) {
                $result = $this->upsertMessage($guildId, $channelId, $message);
                $count++;
                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };

                if ($this->maxMessagesPerRun > 0 && ($runningTotal + $count) >= $this->maxMessagesPerRun) {
                    return [$count, $inserted, $updated, $skipped, $newestMessageId];
                }
            }

            $currentAfter = end($messages)['id'];

            if (count($messages) < 100) {
                break; // No more pages
            }
        }

        return [$count, $inserted, $updated, $skipped, $newestMessageId];
    }

    // -------------------------------------------------------------------------
    // Upserts
    // -------------------------------------------------------------------------

    private function upsertChannel(string $guildId, array $channel): void
    {
        $hash = hash('sha256', json_encode($channel));

        DB::table('source_discord_channels')->upsert(
            [[
                'system_slug'    => $this->system,
                'guild_id'       => $guildId,
                'channel_id'     => $channel['id'],
                'channel_name'   => $channel['name'] ?? '',
                'channel_type'   => (int) ($channel['type'] ?? 0),
                'bot_accessible' => true,
                'payload_json'   => json_encode($channel),
                'row_hash'       => $hash,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]],
            ['system_slug', 'channel_id'],
            ['channel_name', 'channel_type', 'bot_accessible', 'payload_json', 'row_hash', 'updated_at']
        );
    }

    private function upsertMessage(string $guildId, string $channelId, array $message): string
    {
        $hash = hash('sha256', json_encode($message));

        $existingHash = DB::table('source_discord_messages')
            ->where('system_slug', $this->system)
            ->where('channel_id', $channelId)
            ->where('message_id', $message['id'])
            ->value('row_hash');

        if ($existingHash === $hash) {
            // Process attachments even on skip, in case they changed
            foreach ($message['attachments'] ?? [] as $attachment) {
                $this->upsertAttachment($channelId, $message['id'], $attachment);
            }
            return 'skipped';
        }

        $isNew = ($existingHash === null);

        DB::table('source_discord_messages')->upsert(
            [[
                'system_slug'  => $this->system,
                'guild_id'     => $guildId,
                'channel_id'   => $channelId,
                'thread_id'    => $message['thread']['id'] ?? null,
                'message_id'   => $message['id'],
                'author_id'    => $message['author']['id'] ?? null,
                'content'      => $message['content'] ?? null,
                'sent_at'      => $this->parseTimestamp($message['timestamp'] ?? null),
                'edited_at'    => $this->parseTimestamp($message['edited_timestamp'] ?? null),
                'is_deleted'   => false,
                'payload_json' => json_encode($message),
                'row_hash'     => $hash,
                'fetched_at'   => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['system_slug', 'channel_id', 'message_id'],
            ['author_id', 'content', 'sent_at', 'edited_at', 'payload_json', 'row_hash', 'updated_at']
        );

        foreach ($message['attachments'] ?? [] as $attachment) {
            $this->upsertAttachment($channelId, $message['id'], $attachment);
        }

        return $isNew ? 'inserted' : 'updated';
    }

    private function upsertAttachment(string $channelId, string $messageId, array $attachment): void
    {
        $hash = hash('sha256', json_encode($attachment));

        DB::table('source_discord_attachments')->upsert(
            [[
                'system_slug'   => $this->system,
                'channel_id'    => $channelId,
                'message_id'    => $messageId,
                'attachment_id' => $attachment['id'] ?? null,
                'url'           => $attachment['url'] ?? '',
                'filename'      => $attachment['filename'] ?? '',
                'content_type'  => $attachment['content_type'] ?? null,
                'size'          => isset($attachment['size']) ? (int) $attachment['size'] : null,
                'payload_json'  => json_encode($attachment),
                'row_hash'      => $hash,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]],
            ['system_slug', 'channel_id', 'message_id', 'url'],
            ['filename', 'content_type', 'size', 'payload_json', 'row_hash', 'updated_at']
        );
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function apiGet(string $path): array
    {
        $maxRetries = 5;
        $backoff    = 1;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$this->botToken}",
            ])->timeout(30)->get(self::BASE_URL . $path);

            if ($response->status() === 429) {
                $retryAfter = max(1, (int) $response->header('Retry-After', 1));
                if ($this->log) {
                    ($this->log)("Rate limited by Discord. Waiting {$retryAfter}s before retry...", 'warning');
                }
                sleep($retryAfter);
                continue;
            }

            if ($response->status() >= 500) {
                if ($this->log) {
                    ($this->log)("Discord server error {$response->status()}, retrying in {$backoff}s...", 'warning');
                }
                sleep($backoff);
                $backoff = min($backoff * 2, 32);
                continue;
            }

            if ($response->status() === 401) {
                throw new \RuntimeException(
                    'Discord API authentication failed (401). Check the bot token.'
                );
            }

            if ($response->status() === 403) {
                if ($this->log) {
                    ($this->log)("Discord 403 Forbidden for: {$path} — bot lacks permission (READ_MESSAGE_HISTORY or VIEW_CHANNEL)", 'warning');
                }
                return [];
            }

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Discord API error {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }

            return $response->json() ?? [];
        }

        throw new \RuntimeException(
            "Discord API request failed after {$maxRetries} attempts: " . self::BASE_URL . $path
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function parseTimestamp(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($timestamp)->toDateTimeString();
        } catch (\Exception) {
            return null;
        }
    }
}
