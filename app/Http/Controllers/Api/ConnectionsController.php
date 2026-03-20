<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunConnection;
use App\Models\Connection;
use App\Models\ConnectionRun;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectionsController extends Controller
{
    // -------------------------------------------------------------------------
    // Connections CRUD
    // -------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $connections = Connection::with('latestRun')
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => $this->formatConnection($c));

        return response()->json(['connections' => $connections]);
    }

    public function show(Connection $connection): JsonResponse
    {
        $connection->load('latestRun');

        return response()->json(['connection' => $this->formatConnection($connection)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAndBuild($request);

        $connection = Connection::create($data);

        return response()->json(['connection' => $this->formatConnection($connection)], 201);
    }

    public function update(Request $request, Connection $connection): JsonResponse
    {
        $data = $this->validateAndBuild($request, $connection);

        $connection->update($data);

        return response()->json(['connection' => $this->formatConnection($connection->fresh())]);
    }

    public function destroy(Connection $connection): JsonResponse
    {
        $connection->delete();

        return response()->json(['ok' => true]);
    }

    public function duplicate(Connection $connection): JsonResponse
    {
        $copy              = $connection->replicate();
        $copy->name        = $connection->name . ' (copy)';
        $copy->system_slug = $connection->system_slug . '-copy';
        $copy->save();

        return response()->json(['connection' => $this->formatConnection($copy)], 201);
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
            'triggered_by'  => "api:{$mode}",
        ]);

        RunConnection::dispatch($connection->id, $run->id, $mode);

        return response()->json(['run_id' => $run->id]);
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
            'msg'   => 'Stopped via API.',
        ]]);
        $run->markFailed('Stopped via API.');

        return response()->json(['ok' => true]);
    }

    public function killAll(): JsonResponse
    {
        DB::table('jobs')->delete();

        $runs = ConnectionRun::whereIn('status', ['pending', 'running'])->get();

        foreach ($runs as $run) {
            $run->appendLogs([[
                't'     => (int) round(microtime(true) * 1000),
                'level' => 'warning',
                'msg'   => 'Killed via API.',
            ]]);
            $run->markFailed('Killed via API.');
        }

        return response()->json(['ok' => true, 'killed' => $runs->count()]);
    }

    // -------------------------------------------------------------------------
    // Runs
    // -------------------------------------------------------------------------

    public function runsList(Request $request): JsonResponse
    {
        $status  = $request->input('status');
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
            ->map(fn ($run) => $this->formatRun($run));

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
            ->get()
            ->map(fn ($run) => $this->formatRun($run));

        return response()->json(['runs' => $runs]);
    }

    public function runStatus(int $runId): JsonResponse
    {
        $run = ConnectionRun::findOrFail($runId);

        return response()->json([
            'id'               => $run->id,
            'connection_id'    => $run->connection_id,
            'status'           => $run->status,
            'triggered_by'     => $run->triggered_by,
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
        // Delegate to admin controller — test() is public and returns JsonResponse
        $admin = new \App\Http\Controllers\Admin\ConnectionController();

        return $admin->test($request);
    }

    // -------------------------------------------------------------------------
    // Validation / settings builder (JSON-aware, unlike the admin form version)
    // -------------------------------------------------------------------------

    private function validateAndBuild(Request $request, ?Connection $existing = null): array
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

        foreach (['schedule_cron', 'schedule_full_cron'] as $field) {
            if (!empty($base[$field])) {
                try {
                    new CronExpression($base[$field]);
                } catch (\Exception $e) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        $field => "Invalid cron expression: {$e->getMessage()}",
                    ]);
                }
            }
        }

        $prev = $existing?->settings ?? [];
        $s    = $request->input('settings', []);

        $settings = match ($base['type']) {
            'whmcs' => [
                'base_url'  => trim($s['base_url'] ?? ''),
                'admin_dir' => trim(trim($s['admin_dir'] ?? 'admin'), '/') ?: 'admin',
                'token'     => (trim($s['token'] ?? '') !== '') ? trim($s['token']) : ($prev['token'] ?? ''),
                'entities'  => array_values(array_intersect(
                    $this->toArray($s['entities'] ?? []),
                    ['clients', 'contacts', 'services', 'tickets']
                )) ?: ['clients', 'contacts', 'services', 'tickets'],
            ],
            'gmail' => [
                'client_id'           => trim($s['client_id'] ?? ''),
                'client_secret'       => (trim($s['client_secret'] ?? '') !== '') ? trim($s['client_secret']) : ($prev['client_secret'] ?? ''),
                'subject_email'       => trim($s['subject_email'] ?? ''),
                'query'               => trim($s['query'] ?? ''),
                'excluded_labels'     => $this->toArray($s['excluded_labels'] ?? []),
                'page_size'           => max(1, (int) ($s['page_size'] ?? 100)),
                'max_pages'           => max(0, (int) ($s['max_pages'] ?? 0)),
                'concurrent_requests' => max(1, min(100, (int) ($s['concurrent_requests'] ?? 10))),
            ],
            'imap' => [
                'host'               => trim($s['host'] ?? ''),
                'port'               => (int) ($s['port'] ?? 993),
                'encryption'         => in_array($s['encryption'] ?? '', ['ssl', 'tls', 'none']) ? $s['encryption'] : 'ssl',
                'username'           => trim($s['username'] ?? ''),
                'password'           => (trim($s['password'] ?? '') !== '') ? $s['password'] : ($prev['password'] ?? ''),
                'excluded_mailboxes' => $this->toArray($s['excluded_mailboxes'] ?? []),
                'batch_size'         => max(1, (int) ($s['batch_size'] ?? 100)),
                'max_batches'        => max(0, (int) ($s['max_batches'] ?? 0)),
            ],
            'metricscube' => [
                'app_key'             => trim($s['app_key'] ?? ''),
                'connector_key'       => (trim($s['connector_key'] ?? '') !== '') ? trim($s['connector_key']) : ($prev['connector_key'] ?? ''),
                'whmcs_connection_id' => (int) ($s['whmcs_connection_id'] ?? 0),
            ],
            'discord' => [
                'bot_token'            => (trim($s['bot_token'] ?? '') !== '') ? trim($s['bot_token']) : ($prev['bot_token'] ?? ''),
                'guild_allowlist'      => $this->toArray($s['guild_allowlist'] ?? []),
                'channel_allowlist'    => $this->toArray($s['channel_allowlist'] ?? []),
                'include_threads'      => filter_var($s['include_threads'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'max_messages_per_run' => max(0, (int) ($s['max_messages_per_run'] ?? 0)),
            ],
            'slack' => [
                'bot_token'            => (trim($s['bot_token'] ?? '') !== '') ? trim($s['bot_token']) : ($prev['bot_token'] ?? ''),
                'channel_allowlist'    => $this->toArray($s['channel_allowlist'] ?? []),
                'include_threads'      => filter_var($s['include_threads'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'max_messages_per_run' => max(0, (int) ($s['max_messages_per_run'] ?? 0)),
            ],
            default => [],
        };

        return array_merge($base, [
            'settings'              => $settings,
            'schedule_enabled'      => $request->boolean('schedule_enabled'),
            'schedule_full_enabled' => $request->boolean('schedule_full_enabled'),
            'is_active'             => $request->boolean('is_active', true),
        ]);
    }

    /** Accepts either a JSON array or a newline-separated string. */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $value))));
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function formatConnection(Connection $c): array
    {
        $latestRun = $c->relationLoaded('latestRun') ? $c->latestRun : null;

        return [
            'id'                    => $c->id,
            'name'                  => $c->name,
            'type'                  => $c->type,
            'system_slug'           => $c->system_slug,
            'is_active'             => $c->is_active,
            'schedule_enabled'      => $c->schedule_enabled,
            'schedule_cron'         => $c->schedule_cron,
            'schedule_full_enabled' => $c->schedule_full_enabled,
            'schedule_full_cron'    => $c->schedule_full_cron,
            'schedule_label'        => $c->scheduleLabel(),
            'schedule_full_label'   => $c->scheduleFullLabel(),
            'next_run_at'           => $c->nextRunDate()?->format('Y-m-d H:i:s'),
            'next_full_run_at'      => $c->nextFullRunDate()?->format('Y-m-d H:i:s'),
            'missing_credentials'   => $c->hasCredentials(),
            'settings'              => $c->settings ?? [],
            'created_at'            => $c->created_at?->toIso8601String(),
            'updated_at'            => $c->updated_at?->toIso8601String(),
            'latest_run'            => $latestRun ? $this->formatRun($latestRun) : null,
        ];
    }

    private function formatRun(ConnectionRun $run): array
    {
        return [
            'id'               => $run->id,
            'connection_id'    => $run->connection_id,
            'connection_name'  => $run->relationLoaded('connection') ? $run->connection?->name : null,
            'connection_type'  => $run->relationLoaded('connection') ? $run->connection?->type : null,
            'status'           => $run->status,
            'triggered_by'     => $run->triggered_by,
            'created_at'       => $run->created_at?->toIso8601String(),
            'started_at'       => $run->started_at?->toIso8601String(),
            'finished_at'      => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->duration_seconds,
            'error_message'    => $run->error_message,
        ];
    }

}
