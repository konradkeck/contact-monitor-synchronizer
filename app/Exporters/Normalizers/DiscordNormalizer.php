<?php

namespace App\Exporters\Normalizers;

use App\Exporters\ContactMonitorClient;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes Discord source data to Contact Monitor canonical items.
 *
 * Only exports channels where @everyone (role id = guild_id) has VIEW_CHANNEL (bit 1024) denied.
 * This filters out stale public-channel data that may exist in source_discord_channels
 * from earlier imports before the private-only filter was added to the importer.
 */
class DiscordNormalizer
{
    private string $systemSlug;

    public function __construct(string $systemSlug)
    {
        $this->systemSlug = $systemSlug;
    }

    /**
     * Returns channel_ids that the bot has confirmed access to (bot_accessible = true).
     */
    private function getPrivateChannelIds(): array
    {
        return DB::table('source_discord_channels')
            ->where('system_slug', $this->systemSlug)
            ->where('bot_accessible', true)
            ->pluck('channel_id')
            ->all();
    }

    /**
     * Yield conversation items from source_discord_channels — private channels only.
     */
    public function normalizeChannels(?string $sinceAt, callable $log): \Generator
    {
        $privateIds = $this->getPrivateChannelIds();

        if (empty($privateIds)) {
            $log('Discord: no private channels found, skipping.', 'warning');
            return;
        }

        $query = DB::table('source_discord_channels')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateIds)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $convPayload = [
                'channel_type' => 'discord',
                'subject'      => '#' . ($row->channel_name ?: $row->channel_id),
                'meta'         => [
                    'channel_id'   => $row->channel_id,
                    'channel_type' => $row->channel_type,
                    'guild_id'     => $row->guild_id,
                ],
            ];

            yield [
                'item'       => ContactMonitorClient::buildItem('discord', $this->systemSlug, 'conversation', 'upsert', $row->channel_id, $convPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    /**
     * Yield identity items from source_discord_members — all non-bot guild members.
     */
    public function normalizeMembers(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_discord_members')
            ->where('system_slug', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $identPayload = [
                'identity_type' => 'discord_user',
                'value'         => $row->user_id,
                'display_name'  => $row->display_name,
                'avatar'        => $row->avatar ?? null,
                'is_bot'        => (bool) $row->is_bot,
            ];

            yield [
                'item'       => ContactMonitorClient::buildItem('discord', $this->systemSlug, 'identity', 'upsert', $row->user_id, $identPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    /**
     * Yield one activity item per (private channel × calendar day) where messages were seen.
     * Provides a high-level "activity in #channel on date" signal on the company timeline.
     */
    public function normalizeActivities(?string $sinceAt, callable $log): \Generator
    {
        $privateIds = $this->getPrivateChannelIds();
        if (empty($privateIds)) {
            return;
        }

        // Map channel_id → channel_name for display
        $channels = DB::table('source_discord_channels')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateIds)
            ->pluck('channel_name', 'channel_id');

        $query = DB::table('source_discord_messages')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateIds)
            ->whereNotNull('sent_at')
            ->where('is_deleted', false);

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        $buckets = $query
            ->selectRaw('channel_id, DATE(sent_at) as day, MAX(updated_at) as max_updated_at')
            ->groupBy('channel_id', DB::raw('DATE(sent_at)'))
            ->orderBy('max_updated_at')
            ->get();

        foreach ($buckets as $bucket) {
            $channelName = $channels[$bucket->channel_id] ?? $bucket->channel_id;
            $extId       = $bucket->channel_id . '_' . $bucket->day;

            $activityPayload = [
                'activity_type' => 'conversation',
                'occurred_at'   => $bucket->day . 'T12:00:00+00:00',
                'description'   => '#' . $channelName,
                'meta'          => [
                    'channel_type'             => 'discord',
                    'channel_id'               => $bucket->channel_id,
                    'conversation_external_id' => $bucket->channel_id,
                ],
            ];

            yield [
                'item'       => ContactMonitorClient::buildItem('discord', $this->systemSlug, 'activity', 'upsert', $extId, $activityPayload),
                'updated_at' => $bucket->max_updated_at,
            ];
        }
    }

    /**
     * Yield message items from source_discord_messages — private channels only.
     */
    public function normalizeMessages(?string $sinceAt, callable $log): \Generator
    {
        $privateIds = $this->getPrivateChannelIds();

        if (empty($privateIds)) {
            return;
        }

        $query = DB::table('source_discord_messages')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateIds)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        // Build lookup from guild members — authoritative source for display_name and avatar
        $memberData = DB::table('source_discord_members')
            ->where('system_slug', $this->systemSlug)
            ->get(['user_id', 'display_name', 'avatar'])
            ->keyBy('user_id');

        $memberNames = $memberData->mapWithKeys(fn($r) => [$r->user_id => $r->display_name])->filter()->all();

        foreach ($query->cursor() as $row) {
            $payload  = json_decode($row->payload_json, true);
            $authorId = $row->author_id;
            $action   = $row->is_deleted ? 'delete' : 'upsert';

            // Identity hint from author — member table is authoritative for display_name and avatar
            if ($authorId && $action === 'upsert') {
                $author       = $payload['author'] ?? [];
                $member       = $memberData->get($authorId);
                $identPayload = [
                    'identity_type' => 'discord_user',
                    'value'         => $authorId,
                    'display_name'  => $memberNames[$authorId] ?? $author['global_name'] ?? $author['username'] ?? null,
                    'avatar'        => $member ? $member->avatar : ($author['avatar'] ?? null),
                    'is_bot'        => !empty($author['bot']),
                ];
                yield [
                    'item'       => ContactMonitorClient::buildItem('discord', $this->systemSlug, 'identity', 'upsert', $authorId, $identPayload),
                    'updated_at' => $row->updated_at,
                ];
            }

            // Load attachments for this message
            $attachments = [];
            $attRows = DB::table('source_discord_attachments')
                ->where('system_slug', $this->systemSlug)
                ->where('message_id', $row->message_id)
                ->get();

            foreach ($attRows as $att) {
                $attachments[] = [
                    'external_id'  => $att->attachment_id,
                    'filename'     => $att->filename,
                    'content_type' => $att->content_type,
                    'size'         => $att->size,
                    'source_url'   => $att->url,
                ];
            }

            $convExtId  = $row->channel_id;
            $threadKey  = $row->thread_id ?: null;
            $author     = $payload['author'] ?? [];
            $authorName = $memberNames[$authorId] ?? $author['global_name'] ?? $author['username'] ?? $authorId ?? 'Unknown';

            $msgPayload = [
                'conversation_external_id'  => $convExtId,
                'conversation_channel_type' => 'discord',
                'thread_parent_message_id'  => $threadKey,
                'sender_external_id'        => $authorId,
                'sender_identity_type'      => 'discord_user',
                'sender_name'               => $authorName,
                'body_text'                 => $row->content,
                'occurred_at'               => $row->sent_at,
                'edited_at'                 => $row->edited_at ?: null,
                'direction_hint'            => 'internal',
                'attachments'               => $attachments,
                'meta'                      => [
                    'message_id' => $row->message_id,
                    'guild_id'   => $row->guild_id,
                ],
            ];

            yield [
                'item'       => ContactMonitorClient::buildItem('discord', $this->systemSlug, 'message', $action, $row->message_id, $msgPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }
}
