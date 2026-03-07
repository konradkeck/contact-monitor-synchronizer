<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Connection extends Model
{
    protected $fillable = [
        'name', 'type', 'system_slug', 'settings',
        'schedule_enabled', 'schedule_cron',
        'schedule_full_enabled', 'schedule_full_cron',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings'              => 'array',
            'schedule_enabled'      => 'boolean',
            'schedule_full_enabled' => 'boolean',
            'is_active'             => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ConnectionRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ConnectionRun::class)->latestOfMany();
    }

    public function nextRunDate(): ?\DateTime
    {
        if (!$this->schedule_enabled || !$this->schedule_cron) {
            return null;
        }

        try {
            return (new CronExpression($this->schedule_cron))->getNextRunDate();
        } catch (\Exception) {
            return null;
        }
    }

    public function nextFullRunDate(): ?\DateTime
    {
        if (!$this->schedule_full_enabled || !$this->schedule_full_cron) {
            return null;
        }

        try {
            return (new CronExpression($this->schedule_full_cron))->getNextRunDate();
        } catch (\Exception) {
            return null;
        }
    }

    public function linkedWhmcsConnection(): ?self
    {
        if ($this->type !== 'metricscube') {
            return null;
        }

        $id = $this->settings['whmcs_connection_id'] ?? null;

        return $id ? self::find($id) : null;
    }

    public function hasCredentials(): array
    {
        $s = $this->settings ?? [];

        $required = match ($this->type) {
            'whmcs'        => ['base_url', 'token'],
            'gmail'        => ['client_id', 'client_secret'],
            'imap'         => ['host', 'port', 'username', 'password'],
            'metricscube'  => ['app_key', 'connector_key', 'whmcs_connection_id'],
            'discord'      => ['bot_token'],
            'slack'        => ['bot_token'],
            default        => [],
        };

        $missing = [];
        foreach ($required as $key) {
            if (empty($s[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function hasGmailToken(): ?object
    {
        if ($this->type !== 'gmail') {
            return null;
        }

        $subjectEmail = $this->settings['subject_email'] ?? '';

        return DB::table('oauth_google_tokens')
            ->where('system', $this->system_slug)
            ->where('subject_email', $subjectEmail)
            ->first();
    }

    public function scheduleLabel(): string
    {
        if ($this->type === 'metricscube') {
            $whmcsId   = $this->settings['whmcs_connection_id'] ?? null;
            $whmcsSlug = $whmcsId
                ? static::where('id', $whmcsId)->value('system_slug')
                : null;
            return 'Runs with ' . ($whmcsSlug ?? 'WHMCS');
        }

        if (!$this->schedule_enabled || !$this->schedule_cron) {
            return 'Disabled';
        }

        return self::cronLabel($this->schedule_cron);
    }

    public function scheduleFullLabel(): string
    {
        if (!$this->schedule_full_enabled || !$this->schedule_full_cron) {
            return 'Disabled';
        }

        return self::cronLabel($this->schedule_full_cron);
    }

    private static function cronLabel(string $cron): string
    {
        return match ($cron) {
            '*/5 * * * *'  => 'Every 5 min',
            '*/15 * * * *' => 'Every 15 min',
            '*/30 * * * *' => 'Every 30 min',
            '0 * * * *'    => 'Every hour',
            '0 */2 * * *'  => 'Every 2 hours',
            '0 */6 * * *'  => 'Every 6 hours',
            '0 0 * * *'    => 'Daily at midnight',
            default        => $cron,
        };
    }

    private function envPrefix(): string
    {
        $key = strtoupper(str_replace('-', '_', $this->system_slug));

        return match ($this->type) {
            'whmcs'       => "WHMCS_{$key}",
            'gmail'       => "GOOGLE_{$key}",
            'imap'        => "IMAP_{$key}",
            'metricscube' => "METRICSCUBE_{$key}",
            'discord'     => "DISCORD_{$key}",
            'slack'       => "SLACK_{$key}",
            default       => $key,
        };
    }
}
