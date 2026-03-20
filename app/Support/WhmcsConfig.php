<?php

namespace App\Support;

use App\Models\Connection;

class WhmcsConfig
{
    public static function fromSystem(string $system): array
    {
        $connection = Connection::where('type', 'whmcs')
            ->where('system_slug', $system)
            ->first();

        if (!$connection) {
            throw new \RuntimeException("No WHMCS connection found with slug: {$system}");
        }

        $s       = $connection->settings ?? [];
        $baseUrl = $s['base_url'] ?? null;
        $token   = $s['token'] ?? null;

        if (!$baseUrl) throw new \RuntimeException("WHMCS connection '{$system}' is missing base_url.");
        if (!$token)   throw new \RuntimeException("WHMCS connection '{$system}' is missing token.");

        return [
            'base_url'  => $baseUrl,
            'admin_dir' => trim(trim($s['admin_dir'] ?? 'admin'), '/') ?: 'admin',
            'token'     => $token,
        ];
    }
}
