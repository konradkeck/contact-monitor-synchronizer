<?php

namespace App\Support;

use App\Models\Connection;

class GoogleOAuthConfig
{
    public static function fromSystem(string $system): array
    {
        $connection = Connection::where('type', 'gmail')
            ->where('system_slug', $system)
            ->first();

        if (!$connection) {
            throw new \RuntimeException("No Gmail connection found with slug: {$system}");
        }

        $clientId     = $connection->settings['client_id'] ?? null;
        $clientSecret = $connection->settings['client_secret'] ?? null;

        if (!$clientId) {
            throw new \RuntimeException("Gmail connection '{$system}' is missing client_id.");
        }
        if (!$clientSecret) {
            throw new \RuntimeException("Gmail connection '{$system}' is missing client_secret.");
        }

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ];
    }
}
