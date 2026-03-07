<?php

namespace App\Console\Commands;

use App\Importers\Imap\ImportImapMessages as ImapImporter;
use Illuminate\Console\Command;

class ImportImapMessages extends Command
{
    protected $signature = 'imap:import-messages
        {account          : The account slug (e.g. office)}
        {--mailbox=INBOX  : IMAP mailbox to import from}
        {--batch-size=100 : Messages per batch}
        {--max-batches=0  : Max batches to process (0 = unlimited)}
        {--reset-checkpoint : Reset checkpoint and start from the beginning}';

    protected $description = 'Import IMAP messages into source_imap_messages';

    public function handle(): int
    {
        $account    = $this->argument('account');
        $mailbox    = (string) $this->option('mailbox');
        $batchSize  = max(1, (int) $this->option('batch-size'));
        $maxBatches = max(0, (int) $this->option('max-batches'));

        $importer = new ImapImporter(
            account:    $account,
            mailbox:    $mailbox,
            batchSize:  $batchSize,
            maxBatches: $maxBatches,
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
