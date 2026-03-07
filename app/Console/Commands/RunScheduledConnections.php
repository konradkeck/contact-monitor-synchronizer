<?php

namespace App\Console\Commands;

use App\Jobs\RunConnection;
use App\Models\Connection;
use App\Models\ConnectionRun;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunScheduledConnections extends Command
{
    protected $signature   = 'connections:run-scheduled';
    protected $description = 'Dispatch jobs for connections whose cron schedule is due';

    public function handle(): int
    {
        $now = now();

        $connections = Connection::where('is_active', true)
            ->where(function ($q) {
                $q->where('schedule_enabled', true)
                  ->orWhere('schedule_full_enabled', true);
            })
            ->get();

        foreach ($connections as $connection) {
            // Skip metricscube — it runs as a sibling of its WHMCS connection
            if ($connection->type === 'metricscube') {
                continue;
            }

            // Skip if a run is already in flight
            $inFlight = ConnectionRun::where('connection_id', $connection->id)
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($inFlight) {
                $this->line("Skipping #{$connection->id} {$connection->name} — already running");
                continue;
            }

            $dispatched = false;

            // Full schedule takes priority when both are due simultaneously
            if ($connection->schedule_full_enabled && $connection->schedule_full_cron) {
                $isDue = $this->isDue($connection->schedule_full_cron, $now->toDateTimeString());
                if ($isDue === null) {
                    $this->warn("Invalid full cron for #{$connection->id} {$connection->name}: {$connection->schedule_full_cron}");
                } elseif ($isDue) {
                    $this->dispatch($connection, 'full');
                    $dispatched = true;
                }
            }

            if (!$dispatched && $connection->schedule_enabled && $connection->schedule_cron) {
                $isDue = $this->isDue($connection->schedule_cron, $now->toDateTimeString());
                if ($isDue === null) {
                    $this->warn("Invalid partial cron for #{$connection->id} {$connection->name}: {$connection->schedule_cron}");
                } elseif ($isDue) {
                    $this->dispatch($connection, 'partial');
                }
            }
        }

        return self::SUCCESS;
    }

    /** Returns true/false if cron is due, null if cron expression is invalid. */
    private function isDue(string $cron, string $dateTime): ?bool
    {
        try {
            return (new CronExpression($cron))->isDue($dateTime);
        } catch (\Exception) {
            return null;
        }
    }

    private function dispatch(Connection $connection, string $mode): void
    {
        $run = ConnectionRun::create([
            'connection_id' => $connection->id,
            'status'        => 'pending',
            'triggered_by'  => "scheduler:{$mode}",
        ]);

        RunConnection::dispatch($connection->id, $run->id, $mode);

        $this->line("Dispatched #{$connection->id} {$connection->name} [{$mode}] → run #{$run->id}");
    }
}
