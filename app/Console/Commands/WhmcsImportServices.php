<?php

namespace App\Console\Commands;

use App\Importers\Whmcs\ImportWhmcsServices;
use Illuminate\Console\Command;

class WhmcsImportServices extends Command
{
    protected $signature = 'whmcs:import-services {system}';
    protected $description = 'Fetch services from WHMCS API with cursor pagination';

    public function handle(): int
    {
        $importer = new ImportWhmcsServices(strtolower($this->argument('system')));

        try {
            $importer->run(fn(string $line) => $this->line($line));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
