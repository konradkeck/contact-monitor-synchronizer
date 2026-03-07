<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function loginForm()
    {
        if (session('admin_auth')) {
            return redirect()->route('admin.connections.index');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $password = env('ADMIN_PASSWORD', 'admin');

        if ($request->input('password') !== $password) {
            return back()->with('error', 'Invalid password.');
        }

        $request->session()->put('admin_auth', true);

        return redirect()->route('admin.connections.index');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_auth');

        return redirect()->route('admin.login');
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function dashboard()
    {
        return view('admin.dashboard', [
            'system'     => $this->systemInfo(),
            'migrations' => $this->migrationStatus(),
            'whmcs'      => $this->whmcsData(),
            'gmail'      => $this->gmailData(),
            'imap'       => $this->imapData(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Data gathering
    // -------------------------------------------------------------------------

    private function systemInfo(): array
    {
        $dbOk    = false;
        $dbError = null;

        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Exception $e) {
            $dbError = $e->getMessage();
        }

        return [
            'php_version' => PHP_VERSION,
            'imap_loaded' => extension_loaded('imap'),
            'db_ok'       => $dbOk,
            'db_error'    => $dbError,
        ];
    }

    private function migrationStatus(): array
    {
        $ran = $this->safe(
            fn() => DB::table('migrations')->pluck('migration')->toArray(),
            []
        );

        $files  = glob(database_path('migrations/*.php')) ?: [];
        $result = [];

        foreach ($files as $file) {
            $name     = pathinfo($file, PATHINFO_FILENAME);
            $result[] = ['name' => $name, 'ran' => in_array($name, $ran)];
        }

        return $result;
    }

    private function whmcsData(): array
    {
        $systems = $this->detectSystems('WHMCS', 'BASE_URL');
        $result  = [];

        foreach ($systems as $system) {
            $prefix = strtoupper(str_replace('-', '_', $system));

            $entities = [
                'clients'  => 'after_id',
                'contacts' => 'after_id',
                'services' => 'after_id',
                'tickets'  => 'after_sent_at',
            ];

            $entityData = [];

            foreach ($entities as $entity => $cursorType) {
                $table = "source_whmcs_{$entity}";

                $count = $this->safe(
                    fn() => DB::table($table)->where('source_system', $system)->count(),
                    0
                );

                $checkpoint = $this->safe(
                    fn() => DB::table('import_checkpoints')
                        ->where('source_system', $system)
                        ->where('importer', 'whmcs_api')
                        ->where('entity', $entity)
                        ->where('cursor_type', $cursorType)
                        ->first()
                );

                $entityData[$entity] = [
                    'count'      => (int) $count,
                    'last_run'   => $checkpoint?->last_run_at,
                    'cursor_pos' => $entity === 'tickets'
                        ? ($checkpoint ? json_decode($checkpoint->cursor_meta ?? '{}', true)['after_sent_at'] ?? null : null)
                        : $checkpoint?->last_processed_id,
                ];
            }

            $lastSync = collect($entityData)->map(fn($e) => $e['last_run'])->filter()->max();

            $result[] = [
                'system'    => $system,
                'base_url'  => env("WHMCS_{$prefix}_BASE_URL"),
                'entities'  => $entityData,
                'total'     => (int) array_sum(array_column($entityData, 'count')),
                'last_sync' => $lastSync,
            ];
        }

        return $result;
    }

    private function gmailData(): array
    {
        $tokens = $this->safe(
            fn() => DB::table('oauth_google_tokens')
                ->orderBy('system')
                ->orderBy('subject_email')
                ->get(),
            collect()
        );

        return $tokens->map(function ($token) {
            $count = $this->safe(
                fn() => DB::table('source_gmail_messages')
                    ->where('system', $token->system)
                    ->where('subject_email', $token->subject_email)
                    ->count(),
                0
            );

            $lastFetched = $this->safe(
                fn() => DB::table('source_gmail_messages')
                    ->where('system', $token->system)
                    ->where('subject_email', $token->subject_email)
                    ->max('fetched_at')
            );

            return [
                'system'        => $token->system,
                'email'         => $token->subject_email,
                'count'         => (int) $count,
                'last_fetched'  => $lastFetched,
                'token_updated' => $token->updated_at,
                'token_created' => $token->created_at,
            ];
        })->toArray();
    }

    private function imapData(): array
    {
        $accounts = $this->detectSystems('IMAP', 'HOST');
        $result   = [];

        foreach ($accounts as $account) {
            $prefix = strtoupper(str_replace('-', '_', $account));

            $mailboxes = $this->safe(
                fn() => DB::table('source_imap_messages')
                    ->where('account', $account)
                    ->selectRaw('mailbox, count(*) as count, max(fetched_at) as last_fetched')
                    ->groupBy('mailbox')
                    ->orderBy('mailbox')
                    ->get()
                    ->toArray(),
                []
            );

            $result[] = [
                'account'      => $account,
                'host'         => env("IMAP_{$prefix}_HOST"),
                'port'         => env("IMAP_{$prefix}_PORT"),
                'encryption'   => env("IMAP_{$prefix}_ENCRYPTION", 'ssl'),
                'username'     => env("IMAP_{$prefix}_USERNAME"),
                'total_count'  => (int) array_sum(array_column($mailboxes, 'count')),
                'last_fetched' => collect($mailboxes)->max('last_fetched'),
                'mailboxes'    => $mailboxes,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function detectSystems(string $prefix, string $suffix): array
    {
        $systems = [];
        $allEnv  = array_merge(getenv() ?: [], $_ENV ?? []);

        foreach ($allEnv as $key => $value) {
            if (preg_match("/^{$prefix}_(.+?)_{$suffix}$/", $key, $m)) {
                $systems[] = strtolower(str_replace('_', '-', $m[1]));
            }
        }

        return array_values(array_unique($systems));
    }

    private function safe(callable $fn, mixed $default = null): mixed
    {
        try {
            return $fn();
        } catch (\Exception $e) {
            return $default;
        }
    }
}
