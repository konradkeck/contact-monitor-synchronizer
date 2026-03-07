<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionRun extends Model
{
    protected $fillable = [
        'connection_id', 'status', 'triggered_by',
        'started_at', 'finished_at', 'duration_seconds',
        'log_lines', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'log_lines'   => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $duration = $this->started_at ? (int) $this->started_at->diffInSeconds(now()) : null;

        $this->update([
            'status'           => 'completed',
            'finished_at'      => now(),
            'duration_seconds' => $duration,
        ]);
    }

    public function markFailed(string $error): void
    {
        $duration = $this->started_at ? (int) $this->started_at->diffInSeconds(now()) : null;

        $this->update([
            'status'           => 'failed',
            'finished_at'      => now(),
            'duration_seconds' => $duration,
            'error_message'    => $error,
        ]);
    }

    public function appendLogs(array $lines): void
    {
        $current = $this->fresh()->log_lines ?? [];
        $this->update(['log_lines' => array_merge($current, array_values($lines))]);
    }
}
