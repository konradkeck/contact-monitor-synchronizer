<?php

namespace App\Exporters\Normalizers;

use App\Exporters\SalesOsClient;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes IMAP source data to SalesOS canonical items.
 *
 * Threading: uses References (first entry = thread root) → In-Reply-To → own message_id.
 * Direction: Sent-folder messages → 'internal'; inbox → 'customer'.
 */
class ImapNormalizer
{
    private string $systemSlug;

    public function __construct(string $systemSlug)
    {
        $this->systemSlug = $systemSlug;
    }

    /**
     * Yield canonical items from source_imap_messages updated since $sinceAt.
     */
    public function normalize(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_imap_messages')
            ->where('account', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $record    = json_decode($row->payload_json, true);
            $messageId = $record['message_id'] ?: null;
            $mailbox   = $record['mailbox'] ?? $row->mailbox ?? '';

            // ── Thread root detection ──────────────────────────────────────────
            // 1. References header: first message-id = original thread starter
            // 2. In-Reply-To: direct parent (good for 1-level replies)
            // 3. Own message-id: this is the thread root
            $threadRoot = $this->resolveThreadRoot(
                $record['in_reply_to'] ?? '',
                $record['references']  ?? '',
                $messageId,
                $record['subject'] ?? '',
            );

            $convExtId = $threadRoot
                ? 'msgid_' . preg_replace('/[^a-zA-Z0-9@._-]/', '_', trim($threadRoot, '<>'))
                : "uid_{$mailbox}_{$row->uid}";

            $msgExtId   = "uid_{$mailbox}_{$row->uid}";
            $occurredAt = $this->parseDate($record['date'] ?? null);
            $from       = $record['from'] ?? '';
            $fromEmail  = $this->extractEmail($from);
            $fromName   = $this->extractName($from);

            // ── Direction: Sent folder = outgoing (internal) ───────────────────
            $isSent    = $this->isSentMailbox($mailbox);
            $direction = $isSent ? 'internal' : 'customer';

            // ── Conversation item ──────────────────────────────────────────────
            $convPayload = [
                'channel_type'    => 'email',
                'subject'         => $record['subject'] ?? '(No subject)',
                'started_at'      => $occurredAt,
                'last_message_at' => $occurredAt,
            ];

            yield [
                'item'       => SalesOsClient::buildItem('imap', $this->systemSlug, 'conversation', 'upsert', $convExtId, $convPayload),
                'updated_at' => $row->updated_at,
            ];

            // ── Sender identity ────────────────────────────────────────────────
            if ($fromEmail) {
                yield [
                    'item'       => SalesOsClient::buildItem('imap', $this->systemSlug, 'identity', 'upsert', $fromEmail, [
                        'identity_type' => 'email',
                        'value'         => $fromEmail,
                        'display_name'  => $fromName ?: null,
                    ]),
                    'updated_at' => $row->updated_at,
                ];
            }

            // ── Recipient identities (To + Cc) ─────────────────────────────────
            $recipients = array_merge(
                $record['to_all'] ?? ($record['to'] ? [$record['to']] : []),
                $record['cc_all'] ?? ($record['cc'] ? [$record['cc']] : []),
            );
            foreach ($recipients as $recipHeader) {
                $recipEmail = $this->extractEmail($recipHeader);
                $recipName  = $this->extractName($recipHeader);
                if ($recipEmail) {
                    yield [
                        'item'       => SalesOsClient::buildItem('imap', $this->systemSlug, 'identity', 'upsert', $recipEmail, [
                            'identity_type' => 'email',
                            'value'         => $recipEmail,
                            'display_name'  => $recipName ?: null,
                        ]),
                        'updated_at' => $row->updated_at,
                    ];
                }
            }

            // ── Message item ───────────────────────────────────────────────────
            $msgPayload = [
                'conversation_external_id'  => $convExtId,
                'conversation_channel_type' => 'email',
                'sender_external_id'        => $fromEmail,
                'sender_identity_type'      => 'email',
                'sender_name'               => $fromName ?: $fromEmail ?: 'Unknown',
                'body_text'                 => $record['text_body'] ?? null,
                'body_html'                 => $record['html_body'] ?? null,
                'occurred_at'               => $occurredAt,
                'direction_hint'            => $direction,
                'meta'                      => [
                    'message_id'  => $messageId,
                    'in_reply_to' => $record['in_reply_to'] ?? null,
                    'references'  => $record['references'] ?? null,
                    'to'          => $record['to'] ?? null,
                    'cc'          => $record['cc'] ?? null,
                    'flags'       => $record['flags'] ?? [],
                    'mailbox'     => $mailbox,
                ],
            ];

            yield [
                'item'       => SalesOsClient::buildItem('imap', $this->systemSlug, 'message', 'upsert', $msgExtId, $msgPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    /**
     * Yield one activity item per email message.
     * Each message is a separate timeline event linked to its conversation thread.
     */
    public function normalizeActivities(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_imap_messages')
            ->where('account', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $record    = json_decode($row->payload_json, true);
            $messageId = $record['message_id'] ?: null;
            $mailbox   = $record['mailbox'] ?? $row->mailbox ?? '';
            $subject   = $record['subject'] ?? '(No subject)';

            $threadRoot = $this->resolveThreadRoot(
                $record['in_reply_to'] ?? '',
                $record['references']  ?? '',
                $messageId,
                $subject,
            );

            $convExtId = $threadRoot
                ? 'msgid_' . preg_replace('/[^a-zA-Z0-9@._-]/', '_', trim($threadRoot, '<>'))
                : "uid_{$mailbox}_{$row->uid}";

            // Per-message external ID (stable per message UID)
            $msgExtId   = "uid_{$mailbox}_{$row->uid}";
            $occurredAt = $this->parseDate($record['date'] ?? null);

            // Determine contact email: the external party (not our team)
            $isSent = $this->isSentMailbox($mailbox);
            if ($isSent) {
                // Outgoing: contact is the first recipient
                $toRaw        = $record['to_all'][0] ?? $record['to'] ?? '';
                $contactEmail = $this->extractEmail((string) $toRaw) ?: null;
            } else {
                // Incoming: contact is the sender
                $contactEmail = $this->extractEmail($record['from'] ?? '') ?: null;
            }

            $activityPayload = [
                'activity_type' => 'conversation',
                'occurred_at'   => $occurredAt ?? now()->toIso8601String(),
                'description'   => $subject,
                'meta'          => [
                    'channel_type'             => 'email',
                    'conversation_external_id' => $convExtId,
                    'contact_email'            => $contactEmail,
                ],
            ];

            yield [
                'item'       => SalesOsClient::buildItem('imap', $this->systemSlug, 'activity', 'upsert', $msgExtId, $activityPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    // ── Thread root resolution ─────────────────────────────────────────────────

    private function resolveThreadRoot(
        string $inReplyTo,
        string $references,
        ?string $ownMessageId,
        string $subject,
    ): ?string {
        // 1. References: space-separated list of message-ids, oldest first = thread root
        if ($references !== '') {
            if (preg_match('/<([^>]+)>/', $references, $m)) {
                return '<' . $m[1] . '>';
            }
        }

        // 2. In-Reply-To: single parent message-id
        $irt = trim($inReplyTo);
        if ($irt !== '') {
            if (preg_match('/<([^>]+)>/', $irt, $m)) {
                return '<' . $m[1] . '>';
            }
            return $irt;
        }

        // 3. Subject-based fallback: group emails with same normalized subject
        //    (works when in_reply_to/references are not available in old data)
        $normSubj = $this->normalizeSubject($subject);
        if ($normSubj !== '') {
            // Use a subject-based pseudo message-id as thread root
            return 'SUBJ:' . $normSubj . '@' . $this->systemSlug;
        }

        return $ownMessageId ?: null;
    }

    /**
     * Strip reply/forward prefixes and normalize for threading.
     */
    private function normalizeSubject(string $subject): string
    {
        $s = trim($subject);
        // Repeatedly strip known prefixes
        do {
            $before = $s;
            $s = preg_replace('/^(Re|RE|re|Fwd|FWD|fwd|FW|fw|AW|aw|SV|sv|WG|wg)\s*:\s*/u', '', $s);
            $s = trim($s);
        } while ($s !== $before);
        return strtolower($s);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function isSentMailbox(string $mailbox): bool
    {
        $lower = strtolower($mailbox);
        return str_contains($lower, 'sent') || str_contains($lower, 'gesendet');
    }

    private function extractEmail(string $header): ?string
    {
        if (preg_match('/<([^>]+@[^>]+)>/', $header, $m)) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/^([^\s<>]+@[^\s<>]+)$/', trim($header), $m)) {
            return strtolower(trim($m[1]));
        }
        return null;
    }

    private function extractName(string $header): string
    {
        if (preg_match('/^(.+?)\s*<[^>]+>/', trim($header), $m)) {
            return trim($m[1], ' "\'');
        }
        return '';
    }

    private function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($date)->toIso8601String();
        } catch (\Exception) {
            return null;
        }
    }
}
