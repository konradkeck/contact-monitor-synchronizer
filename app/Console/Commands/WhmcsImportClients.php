<?php

namespace App\Console\Commands;

use App\Importers\MetricsCube\ImportMetricsCubeClientActivity;
use App\Support\MetricsCubeConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WhmcsImportClients extends Command
{
    protected $signature = 'whmcs:import-clients {system}';
    protected $description = 'Fetch clients from WHMCS API with cursor pagination';

    public function handle(): int
    {
        $importer = new \App\Importers\Whmcs\ImportWhmcsClients(strtolower($this->argument('system')));

        try {
            $importer->run(fn(string $line) => $this->line($line));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
