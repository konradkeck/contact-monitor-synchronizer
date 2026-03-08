<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class Register extends Command
{
    protected $signature   = 'synchronizer:register';
    protected $description = 'Register this synchronizer with the configured Contact Monitor instance';

    public function handle(): int
    {
        $regToken = env('CM_REGISTRATION_TOKEN');
        $regUrl   = env('CM_REGISTRATION_URL');

        if (!$regToken || !$regUrl) {
            $this->line('No CM_REGISTRATION_TOKEN / CM_REGISTRATION_URL set — skipping.');
            return 0;
        }

        if (cache()->get('cm_registered')) {
            $this->line('Already registered.');
            return 0;
        }

        $this->info("Registering with Contact Monitor at {$regUrl}...");

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $res = Http::timeout(10)->post($regUrl, [
                    'verify_token' => $regToken,
                    'api_token'    => env('API_TOKEN'),
                    'url'          => env('APP_URL'),
                ]);

                if ($res->successful() && ($res->json('ok') ?? false)) {
                    cache()->forever('cm_registered', true);
                    $this->info('✓ Registered successfully.');
                    return 0;
                }

                $this->warn("Attempt {$attempt}: HTTP {$res->status()} — " . $res->body());
            } catch (\Throwable $e) {
                $this->warn("Attempt {$attempt}: " . $e->getMessage());
            }

            if ($attempt < 5) sleep(3);
        }

        $this->error('Registration failed after 5 attempts.');
        return 1;
    }
}
