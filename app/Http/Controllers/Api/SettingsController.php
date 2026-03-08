<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunConnection;
use App\Models\Connection;
use App\Models\ConnectionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'ingest_url'        => env('CONTACT_MONITOR_INGEST_URL', ''),
            'ingest_secret_set' => !empty(env('CONTACT_MONITOR_INGEST_SECRET')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $url    = rtrim($request->input('ingest_url', ''), '/');
        $secret = $request->input('ingest_secret', '');

        if (!$url) {
            return response()->json(['ok' => false, 'error' => 'ingest_url is required.'], 422);
        }

        $envPath = base_path('.env');
        $content = file_get_contents($envPath);
        $content = $this->setEnvValue($content, 'CONTACT_MONITOR_INGEST_URL', $url);
        if ($secret) {
            $content = $this->setEnvValue($content, 'CONTACT_MONITOR_INGEST_SECRET', $secret);
        }
        file_put_contents($envPath, $content);

        config(['services.contact_monitor.ingest_url'    => $url]);
        if ($secret) config(['services.contact_monitor.ingest_secret' => $secret]);

        return response()->json(['ok' => true]);
    }

    public function runAll(Request $request): JsonResponse
    {
        $mode        = in_array($request->input('mode'), ['partial', 'full']) ? $request->input('mode') : 'partial';
        $connections = Connection::where('is_active', true)->get();
        $queued      = 0;

        foreach ($connections as $conn) {
            $alreadyActive = ConnectionRun::where('connection_id', $conn->id)
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if (!$alreadyActive) {
                $run = ConnectionRun::create([
                    'connection_id' => $conn->id,
                    'status'        => 'pending',
                    'triggered_by'  => "api:run-all:{$mode}",
                ]);
                RunConnection::dispatch($conn->id, $run->id, $mode);
                $queued++;
            }
        }

        return response()->json(['ok' => true, 'queued' => $queued, 'total' => $connections->count()]);
    }

    public function resetRuns(): JsonResponse
    {
        DB::table('jobs')->delete();

        ConnectionRun::whereIn('status', ['pending', 'running'])->each(function ($run) {
            $run->markFailed('Reset via API.');
        });

        ConnectionRun::truncate();

        return response()->json(['ok' => true]);
    }

    private function setEnvValue(string $content, string $key, string $value): string
    {
        $escaped = str_contains($value, ' ') ? '"' . addslashes($value) . '"' : $value;

        if (preg_match("/^{$key}=.*/m", $content)) {
            return preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $content);
        }

        return rtrim($content) . "\n{$key}={$escaped}\n";
    }
}
