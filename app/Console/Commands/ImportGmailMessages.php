<?php

namespace App\Console\Commands;

use App\Importers\Gmail\ImportGmailMessages as GmailImporter;
use Illuminate\Console\Command;

class ImportGmailMessages extends Command
{
    protected $signature = 'gmail:import-messages
        {system        : The system name (e.g. salesos)}
        {subject_email : The Gmail account to import from}
        {--query=      : Gmail search query (e.g. "in:inbox")}
        {--page-size=100 : Messages per page}
        {--max-pages=0   : Max pages to fetch (0 = unlimited)}
        {--reset-checkpoint : Reset checkpoint and start from the beginning}';

    protected $description = 'Import Gmail messages into source_gmail_messages';

    public function handle(): int
    {
        $system       = $this->argument('system');
        $subjectEmail = $this->argument('subject_email');
        $query        = (string) ($this->option('query') ?? '');
        $pageSize     = max(1, (int) $this->option('page-size'));
        $maxPages     = max(0, (int) $this->option('max-pages'));

        $importer = new GmailImporter(
            system:       $system,
            subjectEmail: $subjectEmail,
            query:        $query,
            pageSize:     $pageSize,
            maxPages:     $maxPages,
        );

        if ($this->option('reset-checkpoint')) {
            $importer->resetCheckpoint();
            $this->info('Checkpoint reset.');
        }

        try {
            $importer->run(fn(string $line) => $this->line($line));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
