<?php

namespace App\Importers\Slack;

use App\Support\ImportCheckpointStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportSlackMessages
{
    private const BASE_URL = 'https://slack.com/api';

    /** @var callable|null */
    private $log = null;

    public function __construct(
        private string $system,
        private string $botToken,
        private array  $channelAllowlist  = [],
        private bool   $includeThreads    = true,
        private int    $maxMessagesPerRun = 0,
        private string $mode              = 'partial',
    ) {}

    public function run(callable $log): void
    {
        $this->log = $log;

        $log("Slack import starting — mode={$this->mode}, system={$this->system}");

        $this->importUsers($log);

        $channels = $this->fetchChannels();

        if (empty($channels)) {
            $log('No channels found (or none matched allowlist). Nothing to import.');
            return;
        }

        $totalMessages = 0;
        $limitReached  = false;

        foreach ($channels as $channel) {
            if ($limitReached) {
                break;
            }

            $count = $this->importChannel($channel, $totalMessages);
            $totalMessages += $count;

            if ($this->maxMessagesPerRun > 0 && $totalMessages >= $this->maxMessagesPerRun) {
                $log("Max messages per run ({$this->maxMessagesPerRun}) reached, stopping.", 'warning');
                $limitReached = true;
            }
        }

        $log("Slack import complete. Total messages processed: {$totalMessages}.");
    }

    // -------------------------------------------------------------------------
    // Fetching channels
    // -------------------------------------------------------------------------

    private function fetchChannels(): array
    {
        ($this->log)('Fetching channel list...');

        $all    = [];
        $cursor = null;

        do {
            $params = ['types' => 'public_channel,private_channel', 'limit' => 200];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $body   = $this->apiGet('/conversations.list', $params);
            $all    = array_merge($all, $body['channels'] ?? []);
            $cursor = $body['response_metadata']['next_cursor'] ?? null;
        } while (!empty($cursor));

        // Only keep channels the bot is a member of
        $all = array_filter($all, fn ($ch) => (bool) ($ch['is_member'] ?? false));

        if (!empty($this->channelAllowlist)) {
            $all = array_filter(
                $all,
                fn ($ch) => in_array($ch['id'], $this->channelAllowlist, true)
            );
        }

        $all = array_values($all);

        ($this->log)('Found ' . count($all) . ' channel(s) to import.');

        foreach ($all as $channel) {
            $this->upsertChannel($channel);
        }

        return $all;
    }

    // -------------------------------------------------------------------------
    // Channel import
    // -------------------------------------------------------------------------

    private function importChannel(array $channel, int $runningTotal): int
    {
        $channelId   = $channel['id'];
        $channelName = $channel['name'] ?? $channelId;

        $checkpoints   = app(ImportCheckpointStore::class);
        $checkpointKey = "slack|messages|{$this->system}|{$channelId}";
        $checkpoint    = $checkpoints->get($checkpointKey);
        $latestTs      = $checkpoint['latest_ts'] ?? null;

        $newestTs  = null;
        $count     = 0;
        $inserted  = 0;
        $updated   = 0;
        $skipped   = 0;

        if ($this->mode === 'full') {
            ($this->log)("  #{$channelName}: full sync (all history)...");
            [$count, $inserted, $updated, $skipped, $newestTs] =
                $this->importFull($channelId, $runningTotal);
        } else {
            if (!$latestTs) {
                ($this->log)("  #{$channelName}: no checkpoint, falling back to full scan...");
                [$count, $inserted, $updated, $skipped, $newestTs] =
                    $this->importFull($channelId, $runningTotal);
            } else {
                ($this->log)("  #{$channelName}: partial sync after ts={$latestTs}...");
                [$count, $inserted, $updated, $skipped, $newestTs] =
                    $this->importPartial($channelId, $latestTs, $runningTotal);
            }
        }

        ($this->log)("  #{$channelName}: {$count} processed ({$inserted} new, {$updated} updated, {$skipped} unchanged).");

        if ($newestTs !== null) {
            $checkpoints->put($checkpointKey, [
                'latest_ts'   => $newestTs,
                'last_run_at' => now()->toIso8601String(),
            ]);
        }

        return $count;
    }

    /**
     * Full import: fetch all history (Slack returns descending — newest first).
     * Paginate with cursor until exhausted.
     * Returns [count, inserted, updated, skipped, newestTs].
     */
    private function importFull(string $channelId, int $runningTotal): array
    {
        $count    = 0;
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $newestTs = null;
        $cursor   = null;

        do {
            $params = ['channel' => $channelId, 'limit' => 200];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $body     = $this->apiGet('/conversations.history', $params);
            $messages = $body['messages'] ?? [];

            if (empty($messages)) {
                break;
            }

            // First page, first message is newest (Slack returns descending)
            if ($newestTs === null) {
                $newestTs = $messages[0]['ts'];
            }

            foreach ($messages as $message) {
                [$result, $threadCount] = $this->processMessage($channelId, $message, $runningTotal + $count);
                $count += 1 + $threadCount;

                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };

                if ($this->maxMessagesPerRun > 0 && ($runningTotal + $count) >= $this->maxMessagesPerRun) {
                    return [$count, $inserted, $updated, $skipped, $newestTs];
                }
            }

            $cursor = $body['response_metadata']['next_cursor'] ?? null;
        } while (!empty($cursor));

        return [$count, $inserted, $updated, $skipped, $newestTs];
    }

    /**
     * Partial import: fetch messages newer than latestTs using oldest= parameter.
     * Slack returns ascending order when oldest= is used.
     * Returns [count, inserted, updated, skipped, newestTs].
     */
    private function importPartial(string $channelId, string $latestTs, int $runningTotal): array
    {
        $count    = 0;
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $newestTs = null;
        $cursor   = null;

        do {
            $params = ['channel' => $channelId, 'limit' => 200, 'oldest' => $latestTs];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $body     = $this->apiGet('/conversations.history', $params);
            $messages = $body['messages'] ?? [];

            if (empty($messages)) {
                break;
            }

            foreach ($messages as $message) {
                // Skip the boundary message itself (already imported)
                if ($message['ts'] === $latestTs) {
                    continue;
                }

                [$result, $threadCount] = $this->processMessage($channelId, $message, $runningTotal + $count);
                $count += 1 + $threadCount;
                $newestTs = $message['ts'];

                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };

                if ($this->maxMessagesPerRun > 0 && ($runningTotal + $count) >= $this->maxMessagesPerRun) {
                    return [$count, $inserted, $updated, $skipped, $newestTs];
                }
            }

            $cursor = $body['response_metadata']['next_cursor'] ?? null;
        } while (!empty($cursor));

        return [$count, $inserted, $updated, $skipped, $newestTs];
    }

    /**
     * Process a single message: upsert it and optionally fetch thread replies.
     * Returns [result ('inserted'|'updated'|'skipped'), threadMessageCount].
     */
    private function processMessage(string $channelId, array $message, int $runningTotal): array
    {
        $result      = $this->upsertMessage($channelId, $message);
        $threadCount = 0;

        // Fetch thread replies if this is a parent thread message with replies
        if (
            $this->includeThreads
            && isset($message['thread_ts'])
            && $message['thread_ts'] === $message['ts']
            && (int) ($message['reply_count'] ?? 0) > 0
        ) {
            $threadCount = $this->importThread($channelId, $message['thread_ts'], $runningTotal + 1);
        }

        return [$result, $threadCount];
    }

    /**
     * Import replies in a thread.
     */
    private function importThread(string $channelId, string $threadTs, int $runningTotal): int
    {
        $checkpoints   = app(ImportCheckpointStore::class);
        $checkpointKey = "slack|thread|{$this->system}|{$channelId}|{$threadTs}";
        $checkpoint    = $checkpoints->get($checkpointKey);
        $latestTs      = $checkpoint['latest_ts'] ?? null;

        $count    = 0;
        $newestTs = null;
        $cursor   = null;

        do {
            $params = ['channel' => $channelId, 'ts' => $threadTs, 'limit' => 200];
            if ($latestTs && $this->mode === 'partial') {
                $params['oldest'] = $latestTs;
            }
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $body     = $this->apiGet('/conversations.replies', $params);
            $messages = $body['messages'] ?? [];

            if (empty($messages)) {
                break;
            }

            foreach ($messages as $reply) {
                // Skip boundary and parent message itself
                if ($reply['ts'] === $threadTs || ($latestTs && $reply['ts'] === $latestTs)) {
                    continue;
                }

                $this->upsertMessage($channelId, $reply);
                $newestTs = $reply['ts'];
                $count++;

                if ($this->maxMessagesPerRun > 0 && ($runningTotal + $count) >= $this->maxMessagesPerRun) {
                    break 2;
                }
            }

            $cursor = $body['response_metadata']['next_cursor'] ?? null;
        } while (!empty($cursor));

        if ($newestTs !== null) {
            $checkpoints->put($checkpointKey, [
                'latest_ts'   => $newestTs,
                'last_run_at' => now()->toIso8601String(),
            ]);
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Upserts
    // -------------------------------------------------------------------------

    private function importUsers(callable $log): void
    {
        $cursor  = null;
        $total   = 0;

        try {
            do {
                $params = ['limit' => 200];
                if ($cursor) {
                    $params['cursor'] = $cursor;
                }

                $body   = $this->apiGet('/users.list', $params);
                $users  = $body['members'] ?? [];
                $cursor = $body['response_metadata']['next_cursor'] ?? null;

                foreach ($users as $user) {
                    $this->upsertUser($user);
                    $total++;
                }
            } while (!empty($cursor));

            $log("  Imported {$total} Slack user profiles.");
        } catch (\RuntimeException $e) {
            $log("  Skipping user profiles: {$e->getMessage()} — add users:read scope to bot token for display names.", 'warning');
        }
    }

    private function upsertUser(array $user): void
    {
        $profile     = $user['profile'] ?? [];
        $displayName = trim($profile['display_name'] ?? '') ?: trim($profile['real_name'] ?? '') ?: null;
        $realName    = trim($profile['real_name'] ?? '') ?: null;
        $email       = $profile['email'] ?? null;
        $avatarUrl   = $profile['image_72'] ?? $profile['image_192'] ?? $profile['image_48'] ?? null;
        $hash        = hash('sha256', json_encode([$displayName, $realName, $email, $avatarUrl, $user['deleted'] ?? false]));

        DB::table('source_slack_users')->upsert(
            [[
                'system_slug'  => $this->system,
                'user_id'      => $user['id'],
                'display_name' => $displayName,
                'real_name'    => $realName,
                'email'        => $email,
                'avatar_url'   => $avatarUrl,
                'is_bot'       => (bool) ($user['is_bot'] ?? false),
                'deleted'      => (bool) ($user['deleted'] ?? false),
                'row_hash'     => $hash,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['system_slug', 'user_id'],
            ['display_name', 'real_name', 'email', 'avatar_url', 'is_bot', 'deleted', 'row_hash', 'updated_at']
        );
    }

    private function upsertChannel(array $channel): void
    {
        $hash = hash('sha256', json_encode($channel));

        DB::table('source_slack_channels')->upsert(
            [[
                'system_slug'  => $this->system,
                'channel_id'   => $channel['id'],
                'channel_name' => $channel['name'] ?? '',
                'is_private'   => (bool) ($channel['is_private'] ?? false),
                'is_member'    => (bool) ($channel['is_member'] ?? false),
                'topic'        => $channel['topic']['value'] ?? null,
                'purpose'      => $channel['purpose']['value'] ?? null,
                'num_members'  => isset($channel['num_members']) ? (int) $channel['num_members'] : null,
                'payload_json' => json_encode($channel),
                'row_hash'     => $hash,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['system_slug', 'channel_id'],
            ['channel_name', 'is_private', 'is_member', 'topic', 'purpose', 'num_members', 'payload_json', 'row_hash', 'updated_at']
        );
    }

    private function upsertMessage(string $channelId, array $message): string
    {
        $hash = hash('sha256', json_encode($message));

        $existingHash = DB::table('source_slack_messages')
            ->where('system_slug', $this->system)
            ->where('channel_id', $channelId)
            ->where('ts', $message['ts'])
            ->value('row_hash');

        if ($existingHash === $hash) {
            foreach ($message['files'] ?? [] as $file) {
                $this->upsertFile($channelId, $message['ts'], $file);
            }
            return 'skipped';
        }

        $isNew  = ($existingHash === null);
        $sentAt = $this->tsToDatetime($message['ts'] ?? null);

        DB::table('source_slack_messages')->upsert(
            [[
                'system_slug'  => $this->system,
                'channel_id'   => $channelId,
                'ts'           => $message['ts'],
                'thread_ts'    => $message['thread_ts'] ?? null,
                'user_id'      => $message['user'] ?? null,
                'bot_id'       => $message['bot_id'] ?? null,
                'text'         => $message['text'] ?? null,
                'subtype'      => $message['subtype'] ?? null,
                'is_deleted'   => false,
                'payload_json' => json_encode($message),
                'row_hash'     => $hash,
                'sent_at'      => $sentAt,
                'fetched_at'   => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['system_slug', 'channel_id', 'ts'],
            ['user_id', 'bot_id', 'text', 'subtype', 'payload_json', 'row_hash', 'updated_at']
        );

        foreach ($message['files'] ?? [] as $file) {
            $this->upsertFile($channelId, $message['ts'], $file);
        }

        return $isNew ? 'inserted' : 'updated';
    }

    private function upsertFile(string $channelId, string $messageTss, array $file): void
    {
        $hash = hash('sha256', json_encode($file));

        DB::table('source_slack_files')->upsert(
            [[
                'system_slug'  => $this->system,
                'channel_id'   => $channelId,
                'message_ts'   => $messageTss,
                'file_id'      => $file['id'],
                'name'         => $file['name'] ?? null,
                'title'        => $file['title'] ?? null,
                'mimetype'     => $file['mimetype'] ?? null,
                'url_private'  => $file['url_private'] ?? null,
                'size'         => isset($file['size']) ? (int) $file['size'] : null,
                'payload_json' => json_encode($file),
                'row_hash'     => $hash,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]],
            ['system_slug', 'channel_id', 'message_ts', 'file_id'],
            ['name', 'title', 'mimetype', 'url_private', 'size', 'payload_json', 'row_hash', 'updated_at']
        );
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function apiGet(string $path, array $params = []): array
    {
        $maxRetries = 5;
        $backoff    = 1;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withToken($this->botToken)
                ->timeout(30)
                ->get(self::BASE_URL . $path, $params);

            if ($response->status() === 429) {
                $retryAfter = max(1, (int) $response->header('Retry-After', 1));
                if ($this->log) {
                    ($this->log)("Rate limited by Slack. Waiting {$retryAfter}s before retry...", 'warning');
                }
                sleep($retryAfter);
                continue;
            }

            if ($response->status() >= 500) {
                if ($this->log) {
                    ($this->log)("Slack server error {$response->status()}, retrying in {$backoff}s...", 'warning');
                }
                sleep($backoff);
                $backoff = min($backoff * 2, 32);
                continue;
            }

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Slack API HTTP error {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }

            $body = $response->json() ?? [];

            // Slack always returns HTTP 200 but signals errors via "ok": false
            if (($body['ok'] ?? false) === false) {
                $error = $body['error'] ?? 'unknown_error';

                if ($error === 'not_in_channel') {
                    if ($this->log) {
                        ($this->log)("Bot is not a member of channel (not_in_channel): {$path}", 'warning');
                    }
                    return ['messages' => [], 'channels' => [], 'response_metadata' => ['next_cursor' => '']];
                }

                if (in_array($error, ['invalid_auth', 'not_authed', 'token_revoked'], true)) {
                    throw new \RuntimeException(
                        "Slack API authentication failed: {$error}. Check the bot token."
                    );
                }

                throw new \RuntimeException("Slack API error: {$error}");
            }

            return $body;
        }

        throw new \RuntimeException(
            "Slack API request failed after {$maxRetries} attempts: {$path}"
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function tsToDatetime(?string $ts): ?string
    {
        if (!$ts) {
            return null;
        }

        try {
            // Slack ts is a Unix timestamp with microseconds: "1234567890.123456"
            return \Carbon\Carbon::createFromTimestamp((float) $ts)->toDateTimeString();
        } catch (\Exception) {
            return null;
        }
    }
}
