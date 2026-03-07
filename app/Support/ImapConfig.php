<?php

namespace App\Support;

use App\Models\Connection;

class ImapConfig
{
    public static function fromAccount(string $account): array
    {
        $connection = Connection::where('type', 'imap')
            ->where('system_slug', $account)
            ->first();

        if (!$connection) {
            throw new \RuntimeException("No IMAP connection found with slug: {$account}");
        }

        $s          = $connection->settings ?? [];
        $host       = $s['host'] ?? null;
        $port       = $s['port'] ?? null;
        $username   = $s['username'] ?? null;
        $password   = $s['password'] ?? null;
        $encryption = $s['encryption'] ?? 'ssl';

        if (!$host)     throw new \RuntimeException("IMAP connection '{$account}' is missing host.");
        if (!$port)     throw new \RuntimeException("IMAP connection '{$account}' is missing port.");
        if (!$username) throw new \RuntimeException("IMAP connection '{$account}' is missing username.");
        if (!$password) throw new \RuntimeException("IMAP connection '{$account}' is missing password.");

        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new \RuntimeException("IMAP connection '{$account}' has invalid encryption: {$encryption}");
        }

        return [
            'host'       => $host,
            'port'       => (int) $port,
            'username'   => $username,
            'password'   => $password,
            'encryption' => $encryption,
        ];
    }

    public static function buildServerRef(array $config): string
    {
        return self::buildMailboxString($config, '');
    }

    public static function buildMailboxString(array $config, string $mailbox): string
    {
        $flags = '/imap';

        $flags .= match ($config['encryption']) {
            'ssl'   => '/ssl',
            'tls'   => '/tls',
            default => '/notls',
        };

        return '{' . $config['host'] . ':' . $config['port'] . $flags . '}' . $mailbox;
    }
}
