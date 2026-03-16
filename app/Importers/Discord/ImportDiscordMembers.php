<?php

namespace App\Importers\Discord;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fetches members who have explicit access to private (bot_accessible) channels.
 *
 * Two sources:
 *  1. User-specific permission overwrites (type=1) on private channels that grant
 *     VIEW_CHANNEL (bit 1024) — these are people explicitly added to the channel.
 *  2. Authors of messages already recorded in source_discord_messages for those channels.
 *
 * Each discovered user ID is fetched individually via /guilds/{guildId}/members/{userId}.
 * Bots are included and stored with is_bot=true.
 */
class ImportDiscordMembers
{
    private const BASE_URL    = 'https://discord.com/api/v10';
    private const VIEW_CHANNEL = 1024;

    public function __construct(
        private string $system,
        private string $botToken,
        private array  $guildAllowlist = [],
    ) {}

    public function run(callable $log): void
    {
        $log("Discord member import starting — system={$this->system}");

        $channels = DB::table('source_discord_channels')
            ->where('system_slug', $this->system)
            ->where('bot_accessible', true)
            ->get(['guild_id', 'channel_id', 'payload_json']);

        if ($channels->isEmpty()) {
            $log('Discord members: no private channels found, skipping.');
            return;
        }

        // guild_id => [user_id, ...]
        $guildUsers = [];

        // 1. Users from permission overwrites
        foreach ($channels as $channel) {
            $payload    = json_decode($channel->payload_json, true);
            $overwrites = $payload['permission_overwrites'] ?? [];

            foreach ($overwrites as $ow) {
                if ((int) ($ow['type'] ?? -1) !== 1) continue;          // must be user overwrite
                if (((int) ($ow['allow'] ?? 0)) & self::VIEW_CHANNEL) { // must grant VIEW_CHANNEL
                    $guildUsers[$channel->guild_id][$ow['id']] = true;
                }
            }
        }

        // 2. Users from messages in private channels
        $privateChannelIds = $channels->pluck('channel_id')->all();
        $guildByChannel    = $channels->keyBy('channel_id')->map(fn($c) => $c->guild_id);

        DB::table('source_discord_messages')
            ->where('system_slug', $this->system)
            ->whereIn('channel_id', $privateChannelIds)
            ->whereNotNull('author_id')
            ->distinct()
            ->pluck('channel_id', 'author_id')  // author_id => channel_id
            ->each(function ($channelId, $authorId) use ($guildByChannel, &$guildUsers) {
                $guildId = $guildByChannel->get($channelId);
                if ($guildId) {
                    $guildUsers[$guildId][$authorId] = true;
                }
            });

        // Apply guild allowlist
        if (!empty($this->guildAllowlist)) {
            $guildUsers = array_filter(
                $guildUsers,
                fn($guildId) => in_array($guildId, $this->guildAllowlist, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $total = 0;
        foreach ($guildUsers as $guildId => $userMap) {
            $userIds = array_keys($userMap);
            $log("  Guild {$guildId}: " . count($userIds) . " unique user(s) in private channels.");

            foreach ($userIds as $userId) {
                if ($this->importMember($guildId, $userId)) {
                    $total++;
                }
            }
        }

        $log("Discord member import complete. Total members upserted: {$total}.");
    }

    private function importMember(string $guildId, string $userId): bool
    {
        $member = $this->apiGet("/guilds/{$guildId}/members/{$userId}");
        if (empty($member)) {
            return false;
        }

        $user = $member['user'] ?? [];
        if (empty($user['id'])) return false;

        $isBot       = !empty($user['bot']);
        $username    = $user['username'] ?? null;
        $displayName = $member['nick'] ?? $user['global_name'] ?? $username;
        // Guild avatar takes priority over global user avatar
        $avatar      = $member['avatar'] ?? $user['avatar'] ?? null;

        $row = [
            'system_slug'  => $this->system,
            'guild_id'     => $guildId,
            'user_id'      => $userId,
            'username'     => $username,
            'display_name' => $displayName,
            'avatar'       => $avatar,
            'is_bot'       => $isBot,
            'row_hash'     => hash('sha256', $userId . '|' . $displayName . '|' . $username . '|' . $avatar),
            'fetched_at'   => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ];

        DB::table('source_discord_members')->upsert(
            [$row],
            ['system_slug', 'guild_id', 'user_id'],
            ['username', 'display_name', 'avatar', 'is_bot', 'row_hash', 'fetched_at', 'updated_at'],
        );

        return true;
    }

    private function apiGet(string $path): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->timeout(30)->get(self::BASE_URL . $path);

        if (in_array($response->status(), [403, 404], true)) {
            return [];
        }

        if ($response->failed()) {
            throw new \RuntimeException("Discord API error {$response->status()}: " . substr($response->body(), 0, 200));
        }

        return $response->json() ?? [];
    }
}
