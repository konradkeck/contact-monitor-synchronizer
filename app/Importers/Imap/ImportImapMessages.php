<?php

namespace App\Importers\Imap;

use App\Support\ImapConfig;
use App\Support\ImportCheckpointStore;
use Illuminate\Support\Facades\DB;

class ImportImapMessages
{
    private ImportCheckpointStore $checkpoints;

    public function __construct(
        private string $account,
        private string $mailbox    = 'INBOX',
        private int    $batchSize  = 100,
        private int    $maxBatches = 0,
    ) {
        $this->checkpoints = new ImportCheckpointStore();
    }

    public function resetCheckpoint(): void
    {
        $key = "imap|messages|{$this->account}|{$this->mailbox}";
        $this->checkpoints->forget($key);
    }

    public function run(callable $log): void
    {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException('PHP imap extension is not loaded.');
        }

        $config    = ImapConfig::fromAccount($this->account);
        $serverRef = ImapConfig::buildServerRef($config);

        $mailboxFull = $serverRef . $this->mailbox;

        $log("--- {$this->mailbox} ---");
        $this->importMailbox($config, $mailboxFull, $this->mailbox, $log);
    }

    private function importMailbox(array $config, string $mailboxFull, string $mailbox, callable $log): void
    {
        $mbox = @imap_open($mailboxFull, $config['username'], $config['password'], 0, 1);

        if ($mbox === false) {
            $log("Failed to open {$mailbox}: " . (imap_last_error() ?: 'unknown'), 'error');
            return;
        }

        try {
            $this->import($mbox, $mailbox, $log);
        } finally {
            imap_close($mbox);
        }
    }

    private function import($mbox, string $mailbox, callable $log): void
    {
        $checkpointKey = "imap|messages|{$this->account}|{$mailbox}";
        $checkpoint    = $this->checkpoints->get($checkpointKey);

        // Full mode ignores the UID checkpoint and fetches everything.
        // Partial mode (default) only fetches UIDs newer than the last known.
        $lastUid = $this->mode === 'full' ? 0 : (int) ($checkpoint['last_uid'] ?? 0);

        if ($this->mode === 'full' && ($checkpoint['last_uid'] ?? 0) > 0) {
            $log("full sync: ignoring checkpoint (last_uid was " . $checkpoint['last_uid'] . "), fetching ALL");
        }

        // Avoid RFC 3501 edge case: when * (max existing UID) < start UID,
        // some servers return the max-UID message. Use a large fixed cap instead.
        $searchCriteria = $lastUid > 0 ? 'UID ' . ($lastUid + 1) . ':999999999' : 'ALL';
        $uids           = @imap_search($mbox, $searchCriteria, SE_UID);

        if ($uids === false || empty($uids)) {
            $log("No new messages (last_uid: {$lastUid})");
            return;
        }

        sort($uids);

        $total    = count($uids);
        $batchNum = 0;
        $batches  = array_chunk($uids, $this->batchSize);

        foreach ($batches as $batch) {
            $batchNum++;

            if ($this->maxBatches > 0 && $batchNum > $this->maxBatches) {
                break;
            }

            $inserted = $updated = $skipped = 0;

            foreach ($batch as $uid) {
                $result = $this->processMessage($mbox, $uid, $mailbox);
                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };
                $lastUid = max($lastUid, $uid);
            }

            $log("batch {$batchNum}: insert {$inserted} update {$updated} skip {$skipped} (uid up to {$lastUid})");

            $this->checkpoints->put($checkpointKey, [
                'last_uid'    => $lastUid,
                'last_run_at' => now()->toIso8601String(),
            ]);
        }

        $log("Done. Total UIDs: {$total}, last_uid: {$lastUid}");
    }

    private function processMessage($mbox, int $uid, string $mailbox): string
    {
        $msgno = imap_msgno($mbox, $uid);

        if ($msgno === 0) {
            return 'skipped';
        }

        $rawHeaders    = imap_fetchheader($mbox, $msgno);
        $parsedHeaders = imap_rfc822_parse_headers($rawHeaders);
        $structure     = imap_fetchstructure($mbox, $msgno);

        $overviewArr = imap_fetch_overview($mbox, (string) $msgno);
        $ov          = !empty($overviewArr) ? $overviewArr[0] : null;
        $flags       = [];
        if ($ov) {
            if (!empty($ov->seen))     $flags[] = '\\Seen';
            if (!empty($ov->answered)) $flags[] = '\\Answered';
            if (!empty($ov->flagged))  $flags[] = '\\Flagged';
            if (!empty($ov->deleted))  $flags[] = '\\Deleted';
            if (!empty($ov->draft))    $flags[] = '\\Draft';
            if (!empty($ov->recent))   $flags[] = '\\Recent';
        }

        [$textBody, $htmlBody] = $this->extractTextParts($mbox, $msgno, $structure, '');

        $messageId  = trim($parsedHeaders->message_id ?? '');
        $subject    = isset($parsedHeaders->subject) ? $this->decodeHeader($parsedHeaders->subject) : '';
        $from       = isset($parsedHeaders->from[0]) ? $this->addressToString($parsedHeaders->from[0]) : '';
        $to         = isset($parsedHeaders->to[0]) ? $this->addressToString($parsedHeaders->to[0]) : '';
        $cc         = isset($parsedHeaders->cc[0]) ? $this->addressToString($parsedHeaders->cc[0]) : '';
        $date       = $parsedHeaders->date ?? '';

        // Thread headers (parsed from raw because imap_rfc822_parse_headers may miss them)
        $inReplyTo = '';
        if (preg_match('/^In-Reply-To:\s*(.+)$/im', $rawHeaders, $m)) {
            $inReplyTo = trim($m[1]);
        }
        $references = '';
        if (preg_match('/^References:\s*((?:[^\n]|\n[ \t])+)/im', $rawHeaders, $m)) {
            $references = preg_replace('/\s+/', ' ', trim($m[1]));
        }

        // All To/Cc recipients (not just first)
        $toAll = [];
        foreach ($parsedHeaders->to ?? [] as $addr) {
            $toAll[] = $this->addressToString($addr);
        }
        $ccAll = [];
        foreach ($parsedHeaders->cc ?? [] as $addr) {
            $ccAll[] = $this->addressToString($addr);
        }

        $payload = [
            'uid'         => $uid,
            'mailbox'     => $mailbox,
            'message_id'  => $messageId,
            'in_reply_to' => $inReplyTo,
            'references'  => $references,
            'subject'     => $subject,
            'from'        => $from,
            'to'          => $to,
            'to_all'      => $toAll,
            'cc'          => $cc,
            'cc_all'      => $ccAll,
            'date'        => $date,
            'flags'       => $flags,
            'text_body'   => $textBody,
            'html_body'   => $htmlBody,
        ];

        $rowHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $now     = now();

        $existing = DB::table('source_imap_messages')
            ->where('account', $this->account)
            ->where('mailbox', $mailbox)
            ->where('uid', $uid)
            ->first();

        if ($existing === null) {
            DB::table('source_imap_messages')->insert([
                'account'      => $this->account,
                'mailbox'      => $mailbox,
                'uid'          => $uid,
                'message_id'   => $messageId ?: null,
                'row_hash'     => $rowHash,
                'payload_json' => json_encode($payload),
                'fetched_at'   => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            return 'inserted';
        }

        if ($existing->row_hash === $rowHash) {
            return 'skipped';
        }

        DB::table('source_imap_messages')
            ->where('id', $existing->id)
            ->update([
                'row_hash'     => $rowHash,
                'payload_json' => json_encode($payload),
                'fetched_at'   => $now,
                'updated_at'   => $now,
            ]);

        return 'updated';
    }

    private function extractTextParts($mbox, int $msgno, \stdClass $structure, string $section): array
    {
        $textBody = null;
        $htmlBody = null;

        if ($structure->type === TYPEMULTIPART) {
            foreach ($structure->parts as $i => $part) {
                $subSection = $section !== '' ? "{$section}." . ($i + 1) : (string) ($i + 1);
                [$t, $h] = $this->extractTextParts($mbox, $msgno, $part, $subSection);
                $textBody = $textBody ?? $t;
                $htmlBody = $htmlBody ?? $h;
            }
            return [$textBody, $htmlBody];
        }

        if ($structure->type !== TYPETEXT) {
            return [null, null];
        }

        $fetchSection = $section !== '' ? $section : '1';
        $raw          = imap_fetchbody($mbox, $msgno, $fetchSection, FT_PEEK);
        $decoded      = $this->decodeBody($raw, $structure->encoding ?? ENC7BIT);

        $charset = null;
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = $param->value;
                    break;
                }
            }
        }

        if ($charset && strtolower($charset) !== 'utf-8') {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        $subtype = strtolower($structure->subtype ?? 'plain');

        if ($subtype === 'plain') {
            $textBody = $decoded;
        } elseif ($subtype === 'html') {
            $htmlBody = $decoded;
        }

        return [$textBody, $htmlBody];
    }

    private function decodeBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            ENCBASE64          => base64_decode(str_replace(["\r", "\n"], '', $body)),
            ENCQUOTEDPRINTABLE => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private function decodeHeader(string $header): string
    {
        $parts  = imap_mime_header_decode($header);
        $result = '';
        foreach ($parts as $part) {
            $text = $part->text;
            if ($part->charset !== 'default' && strtolower($part->charset) !== 'utf-8') {
                $converted = @mb_convert_encoding($text, 'UTF-8', $part->charset);
                $text      = $converted !== false ? $converted : $text;
            }
            $result .= $text;
        }
        return $result;
    }

    private function addressToString(\stdClass $addr): string
    {
        $name    = isset($addr->personal) ? $this->decodeHeader($addr->personal) : '';
        $mailbox = $addr->mailbox ?? '';
        $host    = $addr->host ?? '';
        $email   = $mailbox && $host ? "{$mailbox}@{$host}" : '';

        if ($name && $email) {
            return "{$name} <{$email}>";
        }

        return $email ?: $name;
    }
}
