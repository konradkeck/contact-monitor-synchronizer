<?php

namespace App\Jobs;

use App\Importers\Discord\ImportDiscordMembers;
use App\Importers\Discord\ImportDiscordMessages;
use App\Importers\Gmail\ImportGmailMessages;
use App\Importers\Imap\ImportImapMessages;
use App\Importers\Slack\ImportSlackMessages;
use App\Importers\Whmcs\ImportWhmcsClients;
use App\Importers\Whmcs\ImportWhmcsContacts;
use App\Importers\Whmcs\ImportWhmcsServices;
use App\Importers\Whmcs\ImportWhmcsTickets;
use App\Models\Connection;
use App\Models\ConnectionRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunConnection implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(
        public readonly int    $connectionId,
        public readonly int    $runId,
        public readonly string $mode = 'partial',
    ) {}

    public function handle(): void
    {
        $run        = ConnectionRun::findOrFail($this->runId);
        $connection = Connection::findOrFail($this->connectionId);

        $run->markRunning();

        // For WHMCS connections, create a sibling run for any linked MetricsCube connector
        $siblingRun = null;
        if ($connection->type === 'whmcs') {
            $mcConnection = Connection::where('type', 'metricscube')
                ->get()
                ->first(fn ($c) => (int) ($c->settings['whmcs_connection_id'] ?? 0) === $connection->id);

            if ($mcConnection) {
                $siblingRun = ConnectionRun::create([
                    'connection_id' => $mcConnection->id,
                    'status'        => 'running',
                    'triggered_by'  => $run->triggered_by,
                    'started_at'    => now(),
                ]);
            }
        }

        $buffer    = [];
        $lastFlush = microtime(true);

        $log = function (string $message, string $level = 'info') use ($run, &$buffer, &$lastFlush): void {
            $buffer[] = ['t' => (int) round(microtime(true) * 1000), 'level' => $level, 'msg' => $message];

            if (count($buffer) >= 10 || (microtime(true) - $lastFlush) >= 3.0) {
                $run->appendLogs($buffer);
                $buffer    = [];
                $lastFlush = microtime(true);
            }
        };

        try {
            $this->runImporter($connection, $log);

            if (!empty($buffer)) {
                $run->appendLogs($buffer);
            }

            $run->markCompleted();

            // Sync freshly imported data to Contact Monitor
            SyncToSalesOs::dispatch($this->connectionId);

            if ($siblingRun) {
                $duration = $siblingRun->started_at ? (int) $siblingRun->started_at->diffInSeconds(now()) : null;
                $siblingRun->update([
                    'log_lines'        => $run->fresh()->log_lines ?? [],
                    'status'           => 'completed',
                    'finished_at'      => now(),
                    'duration_seconds' => $duration,
                ]);
            }

        } catch (\Throwable $e) {
            if (!empty($buffer)) {
                $run->appendLogs($buffer);
            }

            $run->appendLogs([['t' => (int) round(microtime(true) * 1000), 'level' => 'error', 'msg' => $e->getMessage()]]);
            $run->markFailed($e->getMessage());

            if ($siblingRun) {
                $duration = $siblingRun->started_at ? (int) $siblingRun->started_at->diffInSeconds(now()) : null;
                $siblingRun->update([
                    'log_lines'        => $run->fresh()->log_lines ?? [],
                    'status'           => 'failed',
                    'finished_at'      => now(),
                    'duration_seconds' => $duration,
                    'error_message'    => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function runImporter(Connection $connection, callable $log): void
    {
        match ($connection->type) {
            'whmcs'   => $this->runWhmcs($connection, $log),
            'gmail'   => $this->runGmail($connection, $log),
            'imap'    => $this->runImap($connection, $log),
            'discord' => $this->runDiscord($connection, $log),
            'slack'   => $this->runSlack($connection, $log),
            default   => throw new \RuntimeException("Unknown connection type: {$connection->type}"),
        };
    }

    public function displayName(): string
    {
        return static::class . " [{$this->mode}] connection={$this->connectionId}";
    }

    private function runWhmcs(Connection $connection, callable $log): void
    {
        $system   = $connection->system_slug;
        $entities = $connection->settings['entities'] ?? ['clients', 'contacts', 'services', 'tickets'];

        $importers = [
            'clients'  => fn() => new ImportWhmcsClients($system),
            'contacts' => fn() => new ImportWhmcsContacts($system),
            'services' => fn() => new ImportWhmcsServices($system),
            'tickets'  => fn() => new ImportWhmcsTickets($system),
        ];

        foreach ($entities as $entity) {
            if (!isset($importers[$entity])) {
                $log("Unknown entity: {$entity}, skipping.", 'error');
                continue;
            }

            $log("--- {$entity} ---");
            ($importers[$entity]())->run($log);
        }
    }

    private function runGmail(Connection $connection, callable $log): void
    {
        $settings = $connection->settings ?? [];

        $importer = new ImportGmailMessages(
            system:             $connection->system_slug,
            subjectEmail:       $settings['subject_email'] ?? '',
            query:              $settings['query'] ?? '',
            excludedLabels:     $settings['excluded_labels'] ?? [],
            pageSize:           (int) ($settings['page_size'] ?? 100),
            maxPages:           (int) ($settings['max_pages'] ?? 0),
            concurrentRequests: max(1, (int) ($settings['concurrent_requests'] ?? 10)),
            mode:               $this->mode,
        );

        $importer->run($log);
    }

    private function runImap(Connection $connection, callable $log): void
    {
        $settings = $connection->settings ?? [];

        $importer = new ImportImapMessages(
            account:           $connection->system_slug,
            excludedMailboxes: $settings['excluded_mailboxes'] ?? [],
            batchSize:         (int) ($settings['batch_size'] ?? 100),
            maxBatches:        (int) ($settings['max_batches'] ?? 0),
            mode:              $this->mode,
        );

        $importer->run($log);
    }

    private function runDiscord(Connection $connection, callable $log): void
    {
        $settings = $connection->settings ?? [];

        // Import messages first
        $msgImporter = new ImportDiscordMessages(
            system:            $connection->system_slug,
            botToken:          $settings['bot_token'] ?? '',
            guildAllowlist:    $settings['guild_allowlist'] ?? [],
            channelAllowlist:  $settings['channel_allowlist'] ?? [],
            includeThreads:    (bool) ($settings['include_threads'] ?? true),
            maxMessagesPerRun: (int) ($settings['max_messages_per_run'] ?? 0),
            mode:              $this->mode,
        );
        $msgImporter->run($log);

        // Import guild member list to capture people who haven't messaged yet
        $memberImporter = new ImportDiscordMembers(
            system:         $connection->system_slug,
            botToken:       $settings['bot_token'] ?? '',
            guildAllowlist: $settings['guild_allowlist'] ?? [],
        );
        $memberImporter->run($log);
    }

    private function runSlack(Connection $connection, callable $log): void
    {
        $settings = $connection->settings ?? [];

        $importer = new ImportSlackMessages(
            system:            $connection->system_slug,
            botToken:          $settings['bot_token'] ?? '',
            channelAllowlist:  $settings['channel_allowlist'] ?? [],
            includeThreads:    (bool) ($settings['include_threads'] ?? true),
            maxMessagesPerRun: (int) ($settings['max_messages_per_run'] ?? 0),
            mode:              $this->mode,
        );

        $importer->run($log);
    }
}
