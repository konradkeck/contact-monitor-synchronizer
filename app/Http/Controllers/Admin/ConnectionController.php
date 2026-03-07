<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunConnection;
use App\Models\Connection;
use App\Models\ConnectionRun;
use App\Support\GmailTokenProvider;
use App\Support\ImapConfig;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectionController extends Controller
{
    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function index()
    {
        $connections = Connection::with('latestRun')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $whmcsMap = $connections->where('type', 'whmcs')->keyBy('id');

        return view('admin.connections.index', compact('connections', 'whmcsMap'));
    }

    public function create()
    {
        $whmcsConnections = Connection::where('type', 'whmcs')->orderBy('name')->get();

        return view('admin.connections.form', ['connection' => null, 'whmcsConnections' => $whmcsConnections]);
    }

    public function store(Request $request)
    {
        $data = $this->validateConnection($request);

        Connection::create($data);

        return redirect()->route('admin.connections.index')
            ->with('success', 'Connection created.');
    }

    public function edit(Connection $connection)
    {
        $gmailToken       = $connection->hasGmailToken();
        $whmcsConnections = Connection::where('type', 'whmcs')->orderBy('name')->get();

        return view('admin.connections.form', compact('connection', 'gmailToken', 'whmcsConnections'));
    }

    public function update(Request $request, Connection $connection)
    {
        $data = $this->validateConnection($request, $connection);

        $connection->update($data);

        return redirect()->route('admin.connections.edit', $connection)
            ->with('success', 'Connection saved.');
    }

    public function destroy(Connection $connection)
    {
        $connection->delete();

        return redirect()->route('admin.connections.index')
            ->with('success', 'Connection deleted.');
    }

    public function duplicate(Connection $connection)
    {
        $copy              = $connection->replicate();
        $copy->name        = $connection->name . ' (copy)';
        $copy->system_slug = $connection->system_slug . '-copy';
        $copy->save();

        return redirect()->route('admin.connections.edit', $copy)
            ->with('success', 'Connection duplicated. Update the name and slug before use.');
    }

    // -------------------------------------------------------------------------
    // Run management
    // -------------------------------------------------------------------------

    public function run(Request $request, Connection $connection): JsonResponse
    {
        $existing = ConnectionRun::where('connection_id', $connection->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        if ($existing) {
            return response()->json(['run_id' => $existing->id, 'already_active' => true]);
        }

        $mode = in_array($request->input('mode'), ['partial', 'full']) ? $request->input('mode') : 'partial';

        $run = ConnectionRun::create([
            'connection_id' => $connection->id,
            'status'        => 'pending',
            'triggered_by'  => "manual:{$mode}",
        ]);

        RunConnection::dispatch($connection->id, $run->id, $mode);

        return response()->json(['run_id' => $run->id]);
    }

    public function killAll(): JsonResponse
    {
        // Clear pending jobs from the queue
        DB::table('jobs')->delete();

        // Mark all active runs as failed
        $runs = ConnectionRun::whereIn('status', ['pending', 'running'])->get();

        foreach ($runs as $run) {
            $run->appendLogs([[
                't'     => (int) round(microtime(true) * 1000),
                'level' => 'warning',
                'msg'   => 'Killed by user.',
            ]]);
            $run->markFailed('Killed by user.');
        }

        return response()->json(['ok' => true, 'killed' => $runs->count()]);
    }

    public function stop(Connection $connection): JsonResponse
    {
        $run = $connection->latestRun;

        if (!$run || !in_array($run->status, ['pending', 'running'])) {
            return response()->json(['ok' => false, 'message' => 'No active run to stop.'], 422);
        }

        $run->appendLogs([[
            't'     => (int) round(microtime(true) * 1000),
            'level' => 'warning',
            'msg'   => 'Stopped by user.',
        ]]);
        $run->markFailed('Stopped by user.');

        return response()->json(['ok' => true]);
    }

    public function runsList(Request $request): JsonResponse
    {
        $status  = $request->input('status');  // completed | failed | running | all
        $since   = $request->input('since', '24h');
        $perPage = 50;
        $page    = max(1, (int) $request->input('page', 1));

        $query = ConnectionRun::with('connection')->orderBy('id', 'desc');

        if ($status === 'running') {
            $query->whereIn('status', ['pending', 'running']);
        } elseif (in_array($status, ['completed', 'failed', 'pending'])) {
            $query->where('status', $status);
        }

        if ($since === '24h') {
            $query->where('connection_runs.created_at', '>=', now()->subDay());
        }

        $total = (clone $query)->count();
        $runs  = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()
            ->map(fn ($run) => [
                'id'               => $run->id,
                'connection_id'    => $run->connection_id,
                'connection_name'  => $run->connection?->name,
                'connection_type'  => $run->connection?->type,
                'status'           => $run->status,
                'triggered_by'     => $run->triggered_by,
                'created_at'       => $run->created_at,
                'duration_seconds' => $run->duration_seconds,
                'error_message'    => $run->error_message,
            ]);

        return response()->json([
            'runs'        => $runs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function connectionRuns(Connection $connection): JsonResponse
    {
        $runs = ConnectionRun::where('connection_id', $connection->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get(['id', 'status', 'triggered_by', 'created_at', 'duration_seconds', 'error_message']);

        return response()->json(['runs' => $runs]);
    }

    public function runStatus(int $runId): JsonResponse
    {
        $run = ConnectionRun::findOrFail($runId);

        return response()->json([
            'status'           => $run->status,
            'started_at'       => $run->started_at?->toIso8601String(),
            'finished_at'      => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->duration_seconds,
            'error_message'    => $run->error_message,
        ]);
    }

    public function runLogs(int $runId): JsonResponse
    {
        $run = ConnectionRun::findOrFail($runId);

        return response()->json([
            'status'    => $run->status,
            'log_lines' => $run->log_lines ?? [],
        ]);
    }

    public function stream(int $runId): StreamedResponse
    {
        ConnectionRun::findOrFail($runId);

        return response()->stream(function () use ($runId): void {
            $lastSentIndex = 0;
            $maxSeconds    = 3600;
            $startTime     = time();

            while (true) {
                if (time() - $startTime > $maxSeconds) {
                    break;
                }

                $run = ConnectionRun::find($runId);

                if (!$run) {
                    break;
                }

                $lines    = $run->log_lines ?? [];
                $newLines = array_slice($lines, $lastSentIndex);

                foreach ($newLines as $line) {
                    echo 'data: ' . json_encode($line) . "\n\n";
                    $lastSentIndex++;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted()) {
                    break;
                }

                if (in_array($run->status, ['completed', 'failed'])) {
                    echo "event: done\n";
                    echo 'data: ' . json_encode([
                        'status'        => $run->status,
                        'error_message' => $run->error_message,
                    ]) . "\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    break;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test connection
    // -------------------------------------------------------------------------

    public function test(Request $request): JsonResponse
    {
        $type     = $request->input('type') ?? '';
        $slug     = $request->input('system_slug') ?? '';
        $settings = $request->input('settings') ?? [];

        // When editing an existing connection, fall back to stored sensitive values
        // for fields left blank (e.g. "leave blank to keep current")
        $connectionId = $request->input('connection_id');
        if ($connectionId) {
            $stored = Connection::find($connectionId)?->settings ?? [];
            foreach (['token', 'client_secret', 'password', 'connector_key', 'bot_token'] as $field) {
                if (($settings[$field] ?? '') === '' && !empty($stored[$field])) {
                    $settings[$field] = $stored[$field];
                }
            }
        }

        try {
            $message = match ($type) {
                'whmcs'       => $this->testWhmcs($slug, $settings),
                'gmail'       => $this->testGmail($slug, $settings),
                'imap'        => $this->testImap($slug, $settings),
                'metricscube' => $this->testMetricscube($slug, $settings),
                'discord'     => $this->testDiscord($slug, $settings),
                'slack'       => $this->testSlack($slug, $settings),
                default       => throw new \RuntimeException("Unknown type: {$type}"),
            };

            return response()->json(['ok' => true, 'message' => $message]);

        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function testWhmcs(string $slug, array $settings): string
    {
        $baseUrl = $settings['base_url'] ?? null;
        $token   = $settings['token'] ?? null;

        if (!$baseUrl) {
            throw new \RuntimeException('Base URL is required.');
        }
        if (!$token) {
            throw new \RuntimeException('API Token is required.');
        }

        $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
            ->timeout(10)
            ->get(rtrim($baseUrl, '/') . '/modules/addons/salesos_synch_api/api.php', [
                'resource' => 'clients',
                'limit'    => 1,
                'after_id' => 0,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . substr($response->body(), 0, 200));
        }

        $body = $response->json();

        if (($body['ok'] ?? null) !== true) {
            throw new \RuntimeException('API returned error: ' . ($body['error'] ?? 'unknown'));
        }

        $count = count($body['data'] ?? []);

        return "Connected. API returned {$count} client(s) in test page.";
    }

    private function testGmail(string $slug, array $settings): string
    {
        $clientId     = $settings['client_id'] ?? null;
        $clientSecret = $settings['client_secret'] ?? null;
        $subjectEmail = $settings['subject_email'] ?? null;

        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException('Client ID and Client Secret are required.');
        }
        if (!$subjectEmail) {
            throw new \RuntimeException('Subject email is required to test the Gmail token.');
        }

        $tokenRow = DB::table('oauth_google_tokens')
            ->where('system', $slug)
            ->where('subject_email', $subjectEmail)
            ->first();

        if (!$tokenRow) {
            throw new \RuntimeException("No OAuth token found for {$subjectEmail}. Save credentials and authorize via the link on this page.");
        }

        $refreshToken = Crypt::decryptString($tokenRow->refresh_token);

        // Temporarily put credentials into env so GmailTokenProvider can pick them up
        $envSlug = strtoupper(str_replace('-', '_', $slug));
        putenv("GOOGLE_{$envSlug}_CLIENT_ID={$clientId}");
        putenv("GOOGLE_{$envSlug}_CLIENT_SECRET={$clientSecret}");

        GmailTokenProvider::getAccessToken($slug, $refreshToken);

        $tokenAge = \Carbon\Carbon::parse($tokenRow->updated_at)->diffForHumans();

        return "OAuth token valid. Last authorized {$tokenAge}.";
    }

    private function testImap(string $slug, array $settings): string
    {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException('PHP imap extension is not loaded.');
        }

        $host       = $settings['host'] ?? null;
        $port       = $settings['port'] ?? null;
        $username   = $settings['username'] ?? null;
        $password   = $settings['password'] ?? null;
        $encryption = $settings['encryption'] ?? 'ssl';

        if (!$host)     throw new \RuntimeException('Host is required.');
        if (!$port)     throw new \RuntimeException('Port is required.');
        if (!$username) throw new \RuntimeException('Username is required.');
        if (!$password) throw new \RuntimeException('Password is required.');

        $config     = compact('host', 'port', 'username', 'password', 'encryption');
        $serverRef  = ImapConfig::buildServerRef($config);

        $mbox = @imap_open($serverRef, $username, $password, OP_HALFOPEN, 1);

        if ($mbox === false) {
            throw new \RuntimeException('IMAP connection failed: ' . (imap_last_error() ?: 'unknown error'));
        }

        imap_close($mbox);

        return "Connected to {$host}:{$port} ({$encryption}) as {$username}.";
    }

    private function testMetricscube(string $slug, array $settings): string
    {
        $appKey       = $settings['app_key'] ?? null;
        $connectorKey = $settings['connector_key'] ?? null;

        if (!$appKey)       throw new \RuntimeException('App Key is required.');
        if (!$connectorKey) throw new \RuntimeException('Connector Key is required.');

        $response = Http::asForm()
            ->timeout(10)
            ->post(\App\Support\MetricsCubeConfig::BASE_URL, [
                'METRICSCUBE_VERSION'          => '3.2.0',
                'CONNECTOR_TYPE'               => 'WHMCS',
                'METRICSCUBE_APP_KEY'          => $appKey,
                'METRICSCUBE_CONNECTOR_KEY'    => $connectorKey,
                'METRICSCUBE_CONNECTOR_ACTION' => 'GET_CLIENT_ACTIVITY',
                'CLIENT_ID'                    => 1,
            ]);

        if ($response->serverError()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . substr($response->body(), 0, 200));
        }

        $body = $response->json();

        if (!is_array($body)) {
            throw new \RuntimeException('Unexpected response: ' . substr($response->body(), 0, 200));
        }

        // Auth failure returns a "create account" message
        $message = $body['message'] ?? $body['msg'] ?? '';
        if (str_contains($message, 'account') || str_contains($message, 'sign-up') || str_contains($message, 'Register')) {
            throw new \RuntimeException('Authentication failed: ' . $message);
        }

        if (($body['status'] ?? '') === 'success') {
            $count = count($body['data']['activity']['data'] ?? []);
            return "Connected. Retrieved {$count} activity record(s) for client #1.";
        }

        // Other error (e.g. "Client not found") — credentials are valid
        return 'Connected. Credentials valid (' . ($message ?: 'OK') . ').';
    }

    private function testDiscord(string $slug, array $settings): string
    {
        $token = $settings['bot_token'] ?? null;

        if (!$token) {
            throw new \RuntimeException('Bot token is required.');
        }

        $discord = fn(string $path) => Http::withHeaders(['Authorization' => "Bot {$token}"])
            ->timeout(10)
            ->get('https://discord.com/api/v10' . $path);

        // 1. Validate token
        $meResp = $discord('/users/@me');
        if ($meResp->status() === 401) {
            throw new \RuntimeException('Invalid bot token (401). Ensure it is a bot token, not a user token.');
        }
        if ($meResp->failed()) {
            throw new \RuntimeException("Discord API error {$meResp->status()}: " . substr($meResp->body(), 0, 200));
        }
        $botName = $meResp->json()['username'] ?? 'unknown';

        // 2. Check guilds
        $guildsResp = $discord('/users/@me/guilds');
        if ($guildsResp->failed()) {
            return "Token OK (bot: {$botName}). Could not fetch guild list ({$guildsResp->status()}).";
        }
        $guilds = $guildsResp->json() ?? [];

        $guildAllowlist = $settings['guild_allowlist'] ?? [];
        if (!empty($guildAllowlist)) {
            $guilds = array_values(array_filter($guilds, fn($g) => in_array($g['id'], $guildAllowlist, true)));
        }

        if (empty($guilds)) {
            $hint = !empty($guildAllowlist) ? ' (none matched your guild allowlist)' : '';
            throw new \RuntimeException("Bot is not a member of any guilds{$hint}. Add the bot to your server first.");
        }

        // 3. Check private channels + message read access across all guilds
        $isPrivate = function (array $ch, string $guildId): bool {
            if ((int) ($ch['type'] ?? -1) === 12) return true; // PRIVATE_THREAD
            foreach ($ch['permission_overwrites'] ?? [] as $ow) {
                if ((string) ($ow['id'] ?? '') === $guildId && (int) ($ow['type'] ?? -1) === 0) {
                    return (bool) ((int) ($ow['deny'] ?? 0) & 1024);
                }
            }
            return false;
        };

        $channelAllowlist = $settings['channel_allowlist'] ?? [];
        $totalPrivate     = 0;
        $totalAccessible  = [];
        $totalDenied      = 0;

        foreach ($guilds as $g) {
            $gid    = $g['id'];
            $chResp = $discord("/guilds/{$gid}/channels");
            if (!$chResp->successful()) continue;

            $private = array_values(array_filter(
                $chResp->json() ?? [],
                function ($ch) use ($gid, $isPrivate) {
                    $type = (int) ($ch['type'] ?? -1);
                    if (!in_array($type, [0, 5, 12], true)) return false;
                    return $isPrivate($ch, $gid);
                }
            ));

            if (!empty($channelAllowlist)) {
                $private = array_values(array_filter($private, fn($ch) => in_array($ch['id'], $channelAllowlist, true)));
            }

            $totalPrivate += count($private);

            foreach ($private as $ch) {
                $r = $discord("/channels/{$ch['id']}/messages?limit=1");
                if ($r->successful()) {
                    $totalAccessible[] = '"' . ($ch['name'] ?? $ch['id']) . '" in ' . ($g['name'] ?? $gid);
                } else {
                    $totalDenied++;
                }
            }
        }

        if ($totalPrivate === 0) {
            throw new \RuntimeException(
                "Bot is in " . count($guilds) . " guild(s) but found no private channels. " .
                "Create private channels and add the bot to them."
            );
        }

        if (empty($totalAccessible)) {
            throw new \RuntimeException(sprintf(
                "Found %d private channel(s) across %d guild(s) but bot lacks READ_MESSAGE_HISTORY in all of them. " .
                "Go to each private channel → Edit Channel → Permissions → add bot with Read Message History.",
                $totalPrivate,
                count($guilds)
            ));
        }

        return sprintf(
            "OK — bot: %s | %d guild(s) | %d/%d private channel(s) readable: %s%s.",
            $botName,
            count($guilds),
            count($totalAccessible),
            $totalPrivate,
            implode(', ', array_slice($totalAccessible, 0, 5)),
            count($totalAccessible) > 5 ? ' and ' . (count($totalAccessible) - 5) . ' more' : ''
        );
    }

    private function testSlack(string $slug, array $settings): string
    {
        $token = $settings['bot_token'] ?? null;

        if (!$token) {
            throw new \RuntimeException('Bot token is required.');
        }

        $slack = fn(string $method, array $params = []) => Http::withToken($token)
            ->timeout(10)
            ->get('https://slack.com/api/' . $method, $params);

        // 1. Validate token
        $authResp = $slack('auth.test');
        if ($authResp->failed()) {
            throw new \RuntimeException("Slack API HTTP error {$authResp->status()}: " . substr($authResp->body(), 0, 200));
        }
        $auth = $authResp->json() ?? [];
        if (($auth['ok'] ?? false) === false) {
            $error = $auth['error'] ?? 'unknown';
            throw new \RuntimeException("Slack auth failed: {$error}. Ensure the token starts with xoxb- and has the required scopes.");
        }

        $botUser = $auth['user'] ?? 'unknown';
        $team    = $auth['team'] ?? 'unknown';

        // 2. List channels the bot is a member of
        $chResp = $slack('conversations.list', [
            'types'            => 'public_channel,private_channel',
            'exclude_archived' => true,
            'limit'            => 200,
        ]);

        if ($chResp->failed() || ($chResp->json()['ok'] ?? false) === false) {
            $err = $chResp->json()['error'] ?? $chResp->status();
            return "Token OK (bot: {$botUser}, workspace: {$team}). Could not list channels: {$err} — missing conversations:read scope?";
        }

        $allChannels = $chResp->json()['channels'] ?? [];
        $joined      = array_values(array_filter($allChannels, fn($c) => $c['is_member'] ?? false));

        $channelAllowlist = $settings['channel_allowlist'] ?? [];
        if (!empty($channelAllowlist)) {
            $joined = array_values(array_filter($joined, fn($c) => in_array($c['id'], $channelAllowlist, true)));
        }

        if (empty($joined)) {
            $hint = !empty($channelAllowlist)
                ? ' (none matched your channel allowlist)'
                : ' — invite the bot with /invite @' . $botUser;
            throw new \RuntimeException("Bot is not a member of any channels{$hint}.");
        }

        // 3. Try reading history from first channel
        $ch      = $joined[0];
        $chName  = $ch['name'] ?? $ch['id'];
        $histResp = $slack('conversations.history', ['channel' => $ch['id'], 'limit' => 1]);
        $histOk   = ($histResp->json()['ok'] ?? false) === true;
        $histErr  = $histResp->json()['error'] ?? null;

        if (!$histOk) {
            return sprintf(
                "OK — bot: %s | workspace: %s | %d channel(s) joined | #%s: cannot read history (%s) — missing channels:history scope?",
                $botUser, $team, count($joined), $chName, $histErr
            );
        }

        $msgCount = count($histResp->json()['messages'] ?? []);
        return sprintf(
            "OK — bot: %s | workspace: %s | %d channel(s) joined | #%s: %d msg(s) readable.",
            $botUser, $team, count($joined), $chName, $msgCount
        );
    }

    // -------------------------------------------------------------------------
    // Validation / settings builder
    // -------------------------------------------------------------------------

    private function validateConnection(Request $request, ?Connection $existing = null): array
    {
        $base = $request->validate([
            'name'                  => ['required', 'string', 'max:100'],
            'type'                  => ['required', 'in:whmcs,gmail,imap,metricscube,discord,slack'],
            'system_slug'           => ['required', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'schedule_enabled'      => ['boolean'],
            'schedule_cron'         => ['nullable', 'string'],
            'schedule_full_enabled' => ['boolean'],
            'schedule_full_cron'    => ['nullable', 'string'],
            'is_active'             => ['boolean'],
        ]);

        if (!empty($base['schedule_cron'])) {
            try {
                new CronExpression($base['schedule_cron']);
            } catch (\Exception $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'schedule_cron' => "Invalid cron expression: {$e->getMessage()}",
                ]);
            }
        }

        if (!empty($base['schedule_full_cron'])) {
            try {
                new CronExpression($base['schedule_full_cron']);
            } catch (\Exception $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'schedule_full_cron' => "Invalid full sync cron expression: {$e->getMessage()}",
                ]);
            }
        }

        $prev     = $existing?->settings ?? [];
        $s        = $request->input('settings', []);
        $settings = [];

        switch ($base['type']) {
            case 'whmcs':
                $settings = [
                    'base_url'  => trim($s['base_url'] ?? ''),
                    // Preserve token if field left blank (edit mode)
                    'token'     => (trim($s['token'] ?? '') !== '') ? trim($s['token']) : ($prev['token'] ?? ''),
                    'entities'  => array_values(array_intersect(
                        $s['entities'] ?? [],
                        ['clients', 'contacts', 'services', 'tickets']
                    )) ?: ['clients', 'contacts', 'services', 'tickets'],
                ];
                break;

            case 'gmail':
                $settings = [
                    'client_id'           => trim($s['client_id'] ?? ''),
                    'client_secret'       => (trim($s['client_secret'] ?? '') !== '') ? trim($s['client_secret']) : ($prev['client_secret'] ?? ''),
                    'subject_email'       => trim($s['subject_email'] ?? ''),
                    'query'               => trim($s['query'] ?? ''),
                    'excluded_labels'     => self::parseLines($s['excluded_labels'] ?? ''),
                    'page_size'           => max(1, (int) ($s['page_size'] ?? 100)),
                    'max_pages'           => max(0, (int) ($s['max_pages'] ?? 0)),
                    'concurrent_requests' => max(1, min(100, (int) ($s['concurrent_requests'] ?? 10))),
                ];
                break;

            case 'imap':
                $settings = [
                    'host'               => trim($s['host'] ?? ''),
                    'port'               => (int) ($s['port'] ?? 993),
                    'encryption'         => in_array($s['encryption'] ?? '', ['ssl', 'tls', 'none']) ? $s['encryption'] : 'ssl',
                    'username'           => trim($s['username'] ?? ''),
                    'password'           => (trim($s['password'] ?? '') !== '') ? $s['password'] : ($prev['password'] ?? ''),
                    'excluded_mailboxes' => self::parseLines($s['excluded_mailboxes'] ?? ''),
                    'batch_size'         => max(1, (int) ($s['batch_size'] ?? 100)),
                    'max_batches'        => max(0, (int) ($s['max_batches'] ?? 0)),
                ];
                break;

            case 'metricscube':
                $settings = [
                    'app_key'             => trim($s['app_key'] ?? ''),
                    'connector_key'       => (trim($s['connector_key'] ?? '') !== '') ? trim($s['connector_key']) : ($prev['connector_key'] ?? ''),
                    'whmcs_connection_id' => (int) ($s['whmcs_connection_id'] ?? 0),
                ];
                break;

            case 'discord':
                $settings = [
                    'bot_token'            => (trim($s['bot_token'] ?? '') !== '') ? trim($s['bot_token']) : ($prev['bot_token'] ?? ''),
                    'guild_allowlist'      => self::parseLines($s['guild_allowlist'] ?? ''),
                    'channel_allowlist'    => self::parseLines($s['channel_allowlist'] ?? ''),
                    'include_threads'      => filter_var($s['include_threads'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'max_messages_per_run' => max(0, (int) ($s['max_messages_per_run'] ?? 0)),
                ];
                break;

            case 'slack':
                $settings = [
                    'bot_token'            => (trim($s['bot_token'] ?? '') !== '') ? trim($s['bot_token']) : ($prev['bot_token'] ?? ''),
                    'channel_allowlist'    => self::parseLines($s['channel_allowlist'] ?? ''),
                    'include_threads'      => filter_var($s['include_threads'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'max_messages_per_run' => max(0, (int) ($s['max_messages_per_run'] ?? 0)),
                ];
                break;
        }

        return array_merge($base, [
            'settings'              => $settings,
            'schedule_enabled'      => $request->boolean('schedule_enabled'),
            'schedule_full_enabled' => $request->boolean('schedule_full_enabled'),
            'is_active'             => $request->boolean('is_active', true),
        ]);
    }

    /** Parse a textarea (one item per line) into a trimmed, non-empty array. */
    private static function parseLines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $value))));
    }
}
