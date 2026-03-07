<?php

namespace App\Console\Commands;

use App\Importers\MetricsCube\ImportMetricsCubeClientActivity;
use App\Support\MetricsCubeConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MetricsCubeImportClientActivity extends Command
{
    protected $signature = 'metricscube:import-client-activity {system} {client_ids*}';
    protected $description = 'Fetch and upsert MetricsCube activity snapshot for a batch of clients';

    public function handle(): int
    {
        $system    = strtolower($this->argument('system'));
        $clientIds = $this->argument('client_ids');

        if (empty($clientIds)) {
            $this->error('No client_ids provided.');
            return self::FAILURE;
        }

        try {
            $config = MetricsCubeConfig::fromSystem($system);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $importer = new ImportMetricsCubeClientActivity($system, $config);
        $total    = count($clientIds);

        // Resume from checkpoint if previous run was interrupted
        $checkpoint = DB::table('import_checkpoints')
            ->where('source_system', $system)
            ->where('importer', 'metricscube_api')
            ->where('entity', 'client_activity')
            ->where('cursor_type', 'offset')
            ->first();

        $startOffset = $checkpoint ? (int) $checkpoint->last_processed_id + 1 : 0;

        if ($startOffset > 0) {
            $this->line("Resuming from offset {$startOffset} (skipping {$startOffset} already processed).");
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = 0;

        for ($i = $startOffset; $i < $total; $i++) {
            $clientId = $clientIds[$i];

            try {
                $result = $importer->run($clientId);
            } catch (\RuntimeException $e) {
                $this->error("[{$system}] client {$clientId}: ERROR — " . $e->getMessage());
                $errors++;
                $this->saveCheckpoint($system, $i);
                continue;
            }

            $this->line("[{$system}] client {$clientId}: {$result}");

            match ($result) {
                'inserted' => $inserted++,
                'updated'  => $updated++,
                'skipped'  => $skipped++,
            };

            $this->saveCheckpoint($system, $i);
        }

        // Clear checkpoint — batch complete
        DB::table('import_checkpoints')
            ->where('source_system', $system)
            ->where('importer', 'metricscube_api')
            ->where('entity', 'client_activity')
            ->where('cursor_type', 'offset')
            ->delete();

        $this->line("Inserted: {$inserted}");
        $this->line("Updated:  {$updated}");
        $this->line("Skipped:  {$skipped}");
        $this->line("Errors:   {$errors}");

        return self::SUCCESS;
    }

    private function saveCheckpoint(string $system, int $offset): void
    {
        $now    = now();
        $exists = DB::table('import_checkpoints')
            ->where('source_system', $system)
            ->where('importer', 'metricscube_api')
            ->where('entity', 'client_activity')
            ->where('cursor_type', 'offset')
            ->exists();

        if ($exists) {
            DB::table('import_checkpoints')
                ->where('source_system', $system)
                ->where('importer', 'metricscube_api')
                ->where('entity', 'client_activity')
                ->where('cursor_type', 'offset')
                ->update([
                    'last_processed_id' => $offset,
                    'last_run_at'       => $now,
                    'updated_at'        => $now,
                ]);
        } else {
            DB::table('import_checkpoints')->insert([
                'source_system'     => $system,
                'importer'          => 'metricscube_api',
                'entity'            => 'client_activity',
                'cursor_type'       => 'offset',
                'last_processed_id' => $offset,
                'last_run_at'       => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
    }
}
