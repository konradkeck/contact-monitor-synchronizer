<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (app()->runningInConsole()) return;

        $regToken = env('CM_REGISTRATION_TOKEN');
        $cmRegUrl = env('CM_REGISTRATION_URL');

        if (!$regToken || !$cmRegUrl) return;
        if (cache()->get('cm_registered')) return;

        try {
            $res = Http::timeout(5)->post($cmRegUrl, [
                'verify_token' => $regToken,
                'api_token'    => env('API_TOKEN'),
                'url'          => env('APP_URL'),
            ]);
            if ($res->successful() && ($res->json('ok') ?? false)) {
                cache()->forever('cm_registered', true);
            }
        } catch (\Throwable) {
            // Will retry on next request
        }
    }
}
