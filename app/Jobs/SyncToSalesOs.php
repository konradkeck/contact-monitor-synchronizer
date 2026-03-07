<?php

namespace App\Jobs;

use App\Exporters\SalesOsExporter;
use App\Models\Connection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncToSalesOs implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function handle(): void
    {
        $connection = Connection::findOrFail($this->connectionId);

        $log = function (string $message, string $level = 'info'): void {
            Log::channel('stack')->{$level === 'error' ? 'error' : ($level === 'warning' ? 'warning' : 'info')}(
                "[SalesOS sync] {$message}"
            );
        };

        $exporter = new SalesOsExporter($log);
        $exporter->exportConnection($connection);
    }

    public function displayName(): string
    {
        return static::class . " connection={$this->connectionId}";
    }
}
