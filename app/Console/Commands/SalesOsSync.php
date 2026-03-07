<?php

namespace App\Console\Commands;

use App\Exporters\SalesOsExporter;
use App\Models\Connection;
use Illuminate\Console\Command;

class SalesOsSync extends Command
{
    protected $signature = 'salesos:sync
        {--connection= : Sync a single connection by system_slug (default: all active)}
        {--reset-cursor : Reset the export cursor (re-export everything from the beginning)}';

    protected $description = 'Export data from Mielonka sources to SalesOS via ingest API';

    public function handle(): int
    {
        $slug        = $this->option('connection');
        $resetCursor = $this->option('reset-cursor');

        if ($resetCursor) {
            $this->resetCursors($slug);
        }

        $log = function (string $message, string $level = 'info'): void {
            $prefix = match ($level) {
                'error'   => '<error>',
                'warning' => '<comment>',
                default   => '<info>',
            };
            $suffix = match ($level) {
                'error'   => '</error>',
                'warning' => '</comment>',
                default   => '</info>',
            };
            $this->line($prefix . '[' . now()->format('H:i:s') . '] ' . $message . $suffix);
        };

        try {
            $exporter = new SalesOsExporter($log);

            if ($slug) {
                $connection = Connection::where('system_slug', $slug)->firstOrFail();
                $log("Single connection mode: {$connection->type}/{$slug}");
                $exporter->exportConnection($connection);
            } else {
                $log('Exporting all active connections to SalesOS...');
                $exporter->exportAll();
            }

            $log('Done.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Export failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function resetCursors(?string $slug): void
    {
        $query = \Illuminate\Support\Facades\DB::table('import_checkpoints')
            ->where('importer', 'salesos_export');

        if ($slug) {
            $query->where('source_system', $slug);
        }

        $deleted = $query->delete();
        $this->info("Reset {$deleted} export cursor(s).");
    }
}
