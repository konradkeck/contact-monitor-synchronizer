<?php

namespace App\Importers\Gmail;

use App\Support\GmailTokenProvider;
use App\Support\ImportCheckpointStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportGmailMessages
{
    private string $checkpointKey;
    private string $partialSinceKey;
    private ImportCheckpointStore $checkpoints;

    private string $effectiveQuery;

    public function __construct(
        private string $system,
        private string $subjectEmail,
        private string $query,
        private array  $excludedLabels,
        private int    $pageSize,
        private int    $maxPages,
        private int    $concurrentRequests = 10,
        private string $mode = 'partial',
    ) {
        $this->checkpoints    = new ImportCheckpointStore();
        $this->partialSinceKey = "gmail|partial_since|{$system}|{$subjectEmail}";

        // Build effective query: base query + optional partial date filter + -label:X
        $q = $query;

        if ($mode === 'partial') {
            $sinceCheckpoint = $this->checkpoints->get($this->partialSinceKey);
            if ($sinceCheckpoint && isset($sinceCheckpoint['last_sync_date'])) {
                $q = trim("{$q} after:{$sinceCheckpoint['last_sync_date']}");
            }
            // If no checkpoint yet: first partial run → fall through and sync everything
        }

        foreach ($excludedLabels as $label) {
            $label = trim($label);
            if ($label !== '') {
                $q .= ' -label:' . str_replace(' ', '-', $label);
            }
        }
        $this->effectiveQuery = trim($q);
        $this->checkpointKey  = "gmail|messages|{$system}|{$subjectEmail}|{$this->effectiveQuery}";
    }

    public function resetCheckpoint(): void
    {
        $this->checkpoints->forget($this->checkpointKey);
    }

    public function run(callable $log): void
    {
        // For partial mode, record today's date before we start fetching.
        // This ensures messages that arrive *during* the sync are captured next time.
        $syncDate = now()->format('Y/m/d');

        $log("mode: {$this->mode}" . ($this->mode === 'partial' ? ", effective query: {$this->effectiveQuery}" : ''));

        $tokenRow = DB::table('oauth_google_tokens')
            ->where('system', $this->system)
            ->where('subject_email', $this->subjectEmail)
            ->first();

        if (!$tokenRow) {
            throw new \RuntimeException(
                "No OAuth token found for system={$this->system} email={$this->subjectEmail}.\n" .
                "Authorize via: /google/auth/{$this->system}?email={$this->subjectEmail}"
            );
        }

        $refreshToken = Crypt::decryptString($tokenRow->refresh_token);
        $accessToken  = GmailTokenProvider::getAccessToken($this->system, $refreshToken);

        $checkpoint    = $this->checkpoints->get($this->checkpointKey);
        $nextPageToken = $checkpoint['next_page_token'] ?? null;
        $importedCount = $checkpoint['imported_count'] ?? 0;

        $pageNum = 0;

        while (true) {
            $pageNum++;

            if ($this->maxPages > 0 && $pageNum > $this->maxPages) {
                break;
            }

            $listParams = ['maxResults' => $this->pageSize];

            if ($this->effectiveQuery !== '') {
                $listParams['q'] = $this->effectiveQuery;
            }
            if ($nextPageToken) {
                $listParams['pageToken'] = $nextPageToken;
            }

            $listResponse = Http::withToken($accessToken)
                ->timeout(30)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', $listParams);

            if ($listResponse->failed()) {
                throw new \RuntimeException("Gmail messages.list failed: " . $listResponse->body());
            }

            $listData = $listResponse->json();
            $messages = $listData['messages'] ?? [];

            if (empty($messages)) {
                $this->saveCheckpoint(null, $importedCount);
                $log("page {$pageNum}: no messages, done");
                break;
            }

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;

            // Fetch all messages on this page — sequential or batched based on settings
            $payloads = $this->fetchMessages($accessToken, $messages);

            foreach ($payloads as $messageId => $payload) {
                $result = $this->processPayload($messageId, $payload);
                match ($result) {
                    'inserted' => $inserted++,
                    'updated'  => $updated++,
                    default    => $skipped++,
                };
                $importedCount++;
            }

            $log("page {$pageNum}: insert {$inserted} update {$updated} skip {$skipped}");

            $nextPageToken = $listData['nextPageToken'] ?? null;
            $this->saveCheckpoint($nextPageToken, $importedCount);

            if (!$nextPageToken) {
                break;
            }
        }

        // Partial mode: persist the sync date so the next partial run only fetches new messages
        if ($this->mode === 'partial') {
            $this->checkpoints->put($this->partialSinceKey, [
                'last_sync_date' => $syncDate,
                'last_run_at'    => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Dispatch fetching strategy based on concurrentRequests setting.
     *
     * concurrentRequests == 1  →  sequential (one HTTP GET per message, original method)
     * concurrentRequests  > 1  →  Gmail Batch API, chunked by concurrentRequests per call
     */
    private function fetchMessages(string $accessToken, array $messages): array
    {
        if ($this->concurrentRequests === 1) {
            return $this->fetchSequential($accessToken, $messages);
        }

        $payloads = [];
        foreach (array_chunk($messages, $this->concurrentRequests) as $chunk) {
            $payloads += $this->fetchBatchChunk($accessToken, $chunk);
        }
        return $payloads;
    }

    /**
     * Sequential fetch: one messages.get HTTP request per message.
     * Slowest, but guaranteed to never trigger rate-limit errors.
     */
    private function fetchSequential(string $accessToken, array $messages): array
    {
        $payloads = [];
        foreach ($messages as $msg) {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msg['id']}", [
                    'format' => 'full',
                ]);
            if ($response->failed()) {
                throw new \RuntimeException("Gmail messages.get failed for id={$msg['id']}: " . $response->body());
            }
            $payloads[$msg['id']] = $response->json();
        }
        return $payloads;
    }

    /**
     * Fetch a chunk of messages via the Gmail Batch API.
     *
     * Sends all messages.get requests as parts of a single multipart/mixed HTTP
     * request to https://www.googleapis.com/batch/gmail/v1, which avoids the
     * "Too many concurrent requests" (429) error that Http::pool() triggers.
     *
     * Returns an array keyed by message ID.
     */
    private function fetchBatchChunk(string $accessToken, array $messages): array
    {
        $boundary = 'batch_' . bin2hex(random_bytes(8));

        // Build the multipart request body
        $body = '';
        foreach ($messages as $msg) {
            $id    = $msg['id'];
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: application/http\r\n";
            $body .= "Content-ID: <{$id}>\r\n";
            $body .= "\r\n";
            $body .= "GET /gmail/v1/users/me/messages/{$id}?format=full HTTP/1.1\r\n";
            $body .= "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $response = Http::withToken($accessToken)
            ->timeout(120)
            ->withBody($body, "multipart/mixed; boundary={$boundary}")
            ->post('https://www.googleapis.com/batch/gmail/v1');

        if ($response->failed()) {
            throw new \RuntimeException('Gmail batch request failed: ' . $response->body());
        }

        // Extract boundary from the response Content-Type header
        $ct = $response->header('Content-Type');
        // Match quoted ("value") or bare (value) boundary — use !empty() because
        // preg_match sets unmatched groups to "" (not null), so ?? wouldn't help.
        preg_match('/boundary="?([^\s;"]+)/', $ct, $m);
        $resBoundary = $m[1] ?? '';

        if ($resBoundary === '') {
            throw new \RuntimeException("Cannot parse boundary from batch response Content-Type: {$ct}");
        }

        return $this->parseBatchResponse($response->body(), $resBoundary);
    }

    /**
     * Parse a multipart/mixed Gmail Batch API response.
     * Each part contains an embedded HTTP/1.1 response with a JSON body.
     */
    private function parseBatchResponse(string $body, string $boundary): array
    {
        $payloads = [];
        $parts    = explode("--{$boundary}", $body);

        // First element is the preamble; skip it. Last ends with '--'.
        foreach (array_slice($parts, 1) as $part) {
            $part = ltrim($part, "\r\n");

            // Closing delimiter
            if (str_starts_with($part, '--')) {
                break;
            }

            // Find end of MIME wrapper headers (blank line)
            $mimeEnd = strpos($part, "\r\n\r\n");
            if ($mimeEnd === false) {
                continue;
            }
            $inner = substr($part, $mimeEnd + 4);

            // inner is the embedded HTTP/1.1 response; find end of its headers
            $httpEnd = strpos($inner, "\r\n\r\n");
            if ($httpEnd === false) {
                continue;
            }
            $httpHeaders = substr($inner, 0, $httpEnd);
            $json        = trim(substr($inner, $httpEnd + 4));

            // Parse the HTTP status code
            preg_match('/HTTP\/[\d.]+ (\d+)/', $httpHeaders, $sm);
            $statusCode = (int) ($sm[1] ?? 0);

            $data = json_decode($json, true);

            if ($statusCode !== 200) {
                $errMsg = $data['error']['message'] ?? $json;
                throw new \RuntimeException("Gmail batch sub-request failed ({$statusCode}): {$errMsg}");
            }

            if (is_array($data) && isset($data['id'])) {
                $payloads[$data['id']] = $data;
            }
        }

        return $payloads;
    }

    private function processPayload(string $messageId, array $payload): string
    {
        // Strip volatile fields before hashing:
        //   historyId    — changes with every mailbox event, unrelated to message content
        //   attachmentId — a temporary access token Gmail regenerates on every API call;
        //                  lives inside payload.parts[i].body, NOT at the top level
        $payloadForHash = $payload;
        unset($payloadForHash['historyId']);
        if (isset($payloadForHash['payload'])) {
            $payloadForHash['payload'] = $this->stripVolatileFields($payloadForHash['payload']);
        }

        $record = [
            'system'        => $this->system,
            'subject_email' => $this->subjectEmail,
            'external_id'   => $messageId,
            'payload'       => $payloadForHash,
        ];

        $rowHash      = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $threadId     = $payload['threadId'] ?? null;
        $internalDate = isset($payload['internalDate']) ? (int) $payload['internalDate'] : null;
        $now          = now();

        $existing = DB::table('source_gmail_messages')
            ->where('system', $this->system)
            ->where('subject_email', $this->subjectEmail)
            ->where('external_id', $messageId)
            ->first();

        if ($existing === null) {
            DB::table('source_gmail_messages')->insert([
                'system'        => $this->system,
                'subject_email' => $this->subjectEmail,
                'external_id'   => $messageId,
                'thread_id'     => $threadId,
                'internal_date' => $internalDate,
                'row_hash'      => $rowHash,
                'payload_json'  => json_encode($payload),
                'fetched_at'    => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            return 'inserted';
        }

        if ($existing->row_hash === $rowHash) {
            return 'skipped';
        }

        DB::table('source_gmail_messages')
            ->where('id', $existing->id)
            ->update([
                'thread_id'     => $threadId,
                'internal_date' => $internalDate,
                'row_hash'      => $rowHash,
                'payload_json'  => json_encode($payload),
                'fetched_at'    => $now,
                'updated_at'    => $now,
            ]);

        return 'updated';
    }

    /**
     * Recursively remove volatile fields from the Gmail message payload so the
     * row hash is stable across API calls for the same message content.
     */
    private function stripVolatileFields(array $part): array
    {
        // attachmentId is a short-lived token; strip it from the body
        if (isset($part['body']['attachmentId'])) {
            unset($part['body']['attachmentId']);
        }

        if (!empty($part['parts'])) {
            foreach ($part['parts'] as $i => $child) {
                $part['parts'][$i] = $this->stripVolatileFields($child);
            }
        }

        return $part;
    }

    private function saveCheckpoint(?string $nextPageToken, int $importedCount): void
    {
        $this->checkpoints->put($this->checkpointKey, [
            'next_page_token' => $nextPageToken,
            'imported_count'  => $importedCount,
            'last_run_at'     => now()->toIso8601String(),
        ]);
    }
}
