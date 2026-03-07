<?php

namespace App\Console\Commands;

use App\Importers\Whmcs\ImportWhmcsContacts;
use Illuminate\Console\Command;

class WhmcsImportContacts extends Command
{
    protected $signature = 'whmcs:import-contacts {system}';
    protected $description = 'Fetch contacts from WHMCS API with cursor pagination';

    public function handle(): int
    {
        $importer = new ImportWhmcsContacts(strtolower($this->argument('system')));

        try {
            $importer->run(fn(string $line) => $this->line($line));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
