<?php

namespace App\Exporters\Normalizers;

use App\Exporters\SalesOsClient;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes Slack source data to SalesOS canonical items.
 *
 * Conversations: source_slack_channels (one per channel)
 * Messages:      source_slack_messages (one per message)
 * Thread replies: message with thread_key = parent ts
 */
class SlackNormalizer
{
    private string $systemSlug;

    public function __construct(string $systemSlug)
    {
        $this->systemSlug = $systemSlug;
    }

    /**
     * Yield conversation items from source_slack_channels updated since $sinceAt.
     */
    public function normalizeChannels(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_slack_channels')
            ->where('system_slug', $this->systemSlug)
            ->where('is_private', true)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $payload = json_decode($row->payload_json, true);

            $convPayload = [
                'channel_type' => 'slack',
                'subject'      => '#' . ($row->channel_name ?: $row->channel_id),
                'meta'         => [
                    'channel_id'  => $row->channel_id,
                    'is_private'  => (bool) $row->is_private,
                    'topic'       => $row->topic,
                    'purpose'     => $row->purpose,
                    'num_members' => $row->num_members,
                ],
            ];

            yield [
                'item'       => SalesOsClient::buildItem('slack', $this->systemSlug, 'conversation', 'upsert', $row->channel_id, $convPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    /**
     * Yield one activity item per (private channel × calendar day) where messages were seen.
     */
    public function normalizeActivities(?string $sinceAt, callable $log): \Generator
    {
        $privateChannelIds = DB::table('source_slack_channels')
            ->where('system_slug', $this->systemSlug)
            ->where('is_private', true)
            ->pluck('channel_id');

        if ($privateChannelIds->isEmpty()) {
            return;
        }

        // Map channel_id → channel_name
        $channels = DB::table('source_slack_channels')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateChannelIds)
            ->pluck('channel_name', 'channel_id');

        $query = DB::table('source_slack_messages')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateChannelIds)
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
                    'channel_type'             => 'slack',
                    'channel_id'               => $bucket->channel_id,
                    'conversation_external_id' => $bucket->channel_id,
                ],
            ];

            yield [
                'item'       => SalesOsClient::buildItem('slack', $this->systemSlug, 'activity', 'upsert', $extId, $activityPayload),
                'updated_at' => $bucket->max_updated_at,
            ];
        }
    }

    /**
     * Yield message (and identity) items from source_slack_messages updated since $sinceAt.
     */
    public function normalizeMessages(?string $sinceAt, callable $log): \Generator
    {
        // Only export messages from private channels
        $privateChannelIds = DB::table('source_slack_channels')
            ->where('system_slug', $this->systemSlug)
            ->where('is_private', true)
            ->pluck('channel_id');

        if ($privateChannelIds->isEmpty()) {
            return;
        }

        $query = DB::table('source_slack_messages')
            ->where('system_slug', $this->systemSlug)
            ->whereIn('channel_id', $privateChannelIds)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $payload   = json_decode($row->payload_json, true);
            $userId    = $row->user_id;
            $botId     = $row->bot_id;
            $ts        = $row->ts;
            $threadTs  = $row->thread_ts;

            // Deleted messages → emit delete action
            $action = $row->is_deleted ? 'delete' : 'upsert';

            // Identity hint
            if ($userId && $action === 'upsert') {
                $userProfile = DB::table('source_slack_users')
                    ->where('system_slug', $this->systemSlug)
                    ->where('user_id', $userId)
                    ->first();

                $identPayload = [
                    'identity_type' => 'slack_user',
                    'value'         => $userId,
                    'display_name'  => $userProfile?->display_name ?? $userProfile?->real_name ?? ($payload['username'] ?? null),
                    'email_hint'    => $userProfile?->email ?? null,
                    'avatar'        => $userProfile?->avatar_url ?? null,
                ];
                yield [
                    'item'       => SalesOsClient::buildItem('slack', $this->systemSlug, 'identity', 'upsert', $userId, $identPayload),
                    'updated_at' => $row->updated_at,
                ];
            }

            // thread_key: if this message is a reply (thread_ts exists and ≠ ts)
            $threadKey = ($threadTs && $threadTs !== $ts) ? $threadTs : null;

            // Attachments from files
            $attachments = [];
            foreach ($payload['files'] ?? [] as $file) {
                $attachments[] = [
                    'external_id'  => $file['id'] ?? null,
                    'filename'     => $file['name'] ?? $file['title'] ?? 'file',
                    'content_type' => $file['mimetype'] ?? null,
                    'size'         => $file['size'] ?? null,
                    'source_url'   => $file['url_private'] ?? null,
                ];
            }

            $msgPayload = [
                'conversation_external_id'  => $row->channel_id,
                'conversation_channel_type' => 'slack',
                'thread_parent_message_id'  => $threadKey,
                'sender_external_id'        => $userId ?: $botId,
                'sender_identity_type'      => 'slack_user',
                'sender_name'               => $payload['username'] ?? ($botId ? 'bot' : ($userId ?: 'Unknown')),
                'body_text'                 => $row->text,
                'occurred_at'               => $row->sent_at,
                'direction_hint'            => 'internal',
                'attachments'               => $attachments,
                'meta'                      => [
                    'ts'       => $ts,
                    'subtype'  => $row->subtype,
                ],
            ];

            yield [
                'item'       => SalesOsClient::buildItem('slack', $this->systemSlug, 'message', $action, $ts, $msgPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }
}
