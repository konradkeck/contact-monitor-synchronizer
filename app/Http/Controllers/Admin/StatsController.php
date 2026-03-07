<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $yesterday   = now()->subDay();
        $connections = Connection::with('latestRun')->orderBy('type')->orderBy('name')->get();

        $connStats = $connections->map(function (Connection $conn) use ($yesterday) {
            return [
                'connection' => $conn,
                'tables'     => $this->tablesForConnection($conn, $yesterday),
            ];
        });

        return view('admin.stats', [
            'connStats' => $connStats,
            'runStats'  => $this->runStats(),
        ]);
    }

    // -------------------------------------------------------------------------

    private function tablesForConnection(Connection $conn, $yesterday): array
    {
        return match ($conn->type) {
            'whmcs'        => $this->whmcsTables($conn, $yesterday),
            'gmail'        => $this->gmailTables($conn, $yesterday),
            'imap'         => $this->imapTables($conn, $yesterday),
            'metricscube'  => $this->metricscubeTables($conn, $yesterday),
            'discord'      => $this->discordTables($conn, $yesterday),
            'slack'        => $this->slackTables($conn, $yesterday),
            default        => [],
        };
    }

    private function whmcsTables(Connection $conn, $yesterday): array
    {
        $system   = $conn->system_slug;
        $entities = $conn->settings['entities'] ?? ['clients', 'contacts', 'services', 'tickets'];

        $map = [
            'clients'  => 'source_whmcs_clients',
            'contacts' => 'source_whmcs_contacts',
            'services' => 'source_whmcs_services',
            'tickets'  => 'source_whmcs_tickets',
        ];

        $result = [];
        foreach ($entities as $entity) {
            if (!isset($map[$entity])) {
                continue;
            }
            $result[] = $this->queryTable($map[$entity], 'source_system', $system, 'updated_at', $yesterday, $entity);
        }

        return $result;
    }

    private function gmailTables(Connection $conn, $yesterday): array
    {
        $system = $conn->system_slug;
        $email  = $conn->settings['subject_email'] ?? null;

        $label = $email ? "messages ({$email})" : 'messages';

        return [$this->queryTable('source_gmail_messages', 'system', $system, 'fetched_at', $yesterday, $label, function ($q) use ($email) {
            if ($email) {
                $q->where('subject_email', $email);
            }
        })];
    }

    private function imapTables(Connection $conn, $yesterday): array
    {
        $mailbox = $conn->settings['mailbox'] ?? 'INBOX';
        $label   = 'messages (' . $mailbox . ')';

        return [$this->queryTable('source_imap_messages', 'account', $conn->system_slug, 'fetched_at', $yesterday, $label)];
    }

    private function metricscubeTables(Connection $conn, $yesterday): array
    {
        $linked = $conn->linkedWhmcsConnection();
        $system = $linked?->system_slug ?? $conn->system_slug;

        return [$this->queryTable('source_metricscube_client_activities', 'system', $system, 'fetched_at', $yesterday, 'client activity')];
    }

    private function discordTables(Connection $conn, $yesterday): array
    {
        $system = $conn->system_slug;

        $guilds = DB::table('source_discord_channels')
            ->where('system_slug', $system)
            ->distinct()
            ->pluck('guild_id')
            ->toArray();

        $guildLabel = empty($guilds) ? 'messages' : count($guilds) . ' guild(s)';

        return [
            $this->queryTable('source_discord_messages',    'system_slug', $system, 'fetched_at', $yesterday, 'messages ('    . $guildLabel . ')'),
            $this->queryTable('source_discord_channels',    'system_slug', $system, 'updated_at', $yesterday, 'channels'),
            $this->queryTable('source_discord_attachments', 'system_slug', $system, 'updated_at', $yesterday, 'attachments'),
        ];
    }

    private function slackTables(Connection $conn, $yesterday): array
    {
        $system = $conn->system_slug;

        return [
            $this->queryTable('source_slack_messages', 'system_slug', $system, 'fetched_at', $yesterday, 'messages'),
            $this->queryTable('source_slack_channels', 'system_slug', $system, 'updated_at', $yesterday, 'channels'),
            $this->queryTable('source_slack_files',    'system_slug', $system, 'updated_at', $yesterday, 'files'),
        ];
    }

    // -------------------------------------------------------------------------

    private function queryTable(
        string $table,
        string $systemCol,
        string $systemVal,
        string $tsCol,
        $yesterday,
        string $label,
        ?callable $extraFilter = null,
    ): array {
        try {
            $base = DB::table($table)->where($systemCol, $systemVal);
            if ($extraFilter) {
                $extraFilter($base);
            }

            $total      = (clone $base)->count();
            $changed24h = (clone $base)->where($tsCol, '>=', $yesterday)->count();
            $newest     = (clone $base)->max($tsCol);

            return [
                'label'       => $label,
                'table'       => $table,
                'total'       => $total,
                'changed_24h' => $changed24h,
                'newest_at'   => $newest,
                'error'       => null,
            ];
        } catch (\Exception $e) {
            return [
                'label'       => $label,
                'table'       => $table,
                'total'       => null,
                'changed_24h' => null,
                'newest_at'   => null,
                'error'       => $e->getMessage(),
            ];
        }
    }

    private function runStats(): array
    {
        try {
            $yesterday = now()->subDay();

            return [
                'total_runs_24h'    => DB::table('connection_runs')->where('created_at', '>=', $yesterday)->count(),
                'completed_24h'     => DB::table('connection_runs')->where('status', 'completed')->where('created_at', '>=', $yesterday)->count(),
                'failed_24h'        => DB::table('connection_runs')->where('status', 'failed')->where('created_at', '>=', $yesterday)->count(),
                'currently_running' => DB::table('connection_runs')->whereIn('status', ['pending', 'running'])->count(),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
