<?php

namespace App\Console\Commands;

use App\Importers\Whmcs\ImportWhmcsTickets;
use Illuminate\Console\Command;

class WhmcsImportTickets extends Command
{
    protected $signature = 'whmcs:import-tickets {system}';
    protected $description = 'Fetch tickets from WHMCS API with cursor pagination';

    public function handle(): int
    {
        $importer = new ImportWhmcsTickets(strtolower($this->argument('system')));

        try {
            $importer->run(fn(string $line) => $this->line($line));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
