<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mielonka — Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        gray: {
                            925: '#0f1117',
                            950: '#080b10',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { background: #0d1117; }
        .card { background: #161b22; border: 1px solid #30363d; }
        .section-header { border-bottom: 1px solid #21262d; }
        .tbl th { background: #0d1117; color: #8b949e; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.5rem 1rem; text-align: left; }
        .tbl td { padding: 0.5rem 1rem; border-top: 1px solid #21262d; font-size: 0.875rem; }
        .tbl tr:hover td { background: #1c2128; }
        code { background: #1c2128; border: 1px solid #30363d; border-radius: 4px; padding: 1px 5px; font-size: 0.75rem; }
    </style>
</head>
<body class="text-gray-300 min-h-screen">

{{-- =========================================================================
     HEADER
     ========================================================================= --}}
<header class="border-b border-gray-800 bg-gray-900/50 sticky top-0 z-10 backdrop-blur">
    <div class="max-w-6xl mx-auto px-6 h-14 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="font-bold text-white text-lg">Mielonka</span>
            <span class="text-gray-600 text-sm">Admin Panel</span>
        </div>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="text-gray-500 hover:text-gray-300 text-sm transition">
                Sign out
            </button>
        </form>
    </div>
</header>

<main class="max-w-6xl mx-auto px-6 py-8 space-y-8">

{{-- =========================================================================
     SYSTEM STATUS BAR
     ========================================================================= --}}
<div class="flex flex-wrap gap-2">
    {{-- PHP --}}
    <span class="inline-flex items-center gap-1.5 bg-gray-800 border border-gray-700 rounded-full px-3 py-1 text-xs">
        <span class="text-gray-400">PHP</span>
        <span class="text-white font-mono">{{ $system['php_version'] }}</span>
    </span>

    {{-- DB --}}
    @if ($system['db_ok'])
        <span class="inline-flex items-center gap-1.5 bg-green-950/50 border border-green-800/60 rounded-full px-3 py-1 text-xs text-green-400">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            Database connected
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 bg-red-950/50 border border-red-800/60 rounded-full px-3 py-1 text-xs text-red-400" title="{{ $system['db_error'] }}">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            Database error
        </span>
    @endif

    {{-- IMAP ext --}}
    @if ($system['imap_loaded'])
        <span class="inline-flex items-center gap-1.5 bg-green-950/50 border border-green-800/60 rounded-full px-3 py-1 text-xs text-green-400">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            imap extension loaded
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 bg-yellow-950/50 border border-yellow-800/60 rounded-full px-3 py-1 text-xs text-yellow-400">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            imap extension missing
        </span>
    @endif

    {{-- Migrations warning --}}
    @php $pendingMigrations = collect($migrations)->where('ran', false)->count(); @endphp
    @if ($pendingMigrations > 0)
        <span class="inline-flex items-center gap-1.5 bg-red-950/50 border border-red-800/60 rounded-full px-3 py-1 text-xs text-red-400">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            {{ $pendingMigrations }} pending migration{{ $pendingMigrations > 1 ? 's' : '' }}
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 bg-green-950/50 border border-green-800/60 rounded-full px-3 py-1 text-xs text-green-400">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="4"/></svg>
            All migrations ran
        </span>
    @endif

    <span class="ml-auto text-gray-600 text-xs self-center">
        {{ now()->format('Y-m-d H:i:s') }} UTC
    </span>
</div>

{{-- =========================================================================
     SUMMARY CARDS
     ========================================================================= --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    @php
        $totalWhmcs = collect($whmcs)->sum('total');
        $totalGmail = collect($gmail)->sum('count');
        $totalImap  = collect($imap)->sum('total_count');
    @endphp

    <div class="card rounded-xl p-5">
        <div class="text-gray-500 text-xs uppercase tracking-wider mb-1">WHMCS records</div>
        <div class="text-3xl font-bold text-white">{{ number_format($totalWhmcs) }}</div>
        <div class="text-gray-600 text-xs mt-1">{{ count($whmcs) }} system{{ count($whmcs) !== 1 ? 's' : '' }} configured</div>
    </div>

    <div class="card rounded-xl p-5">
        <div class="text-gray-500 text-xs uppercase tracking-wider mb-1">Gmail messages</div>
        <div class="text-3xl font-bold text-white">{{ number_format($totalGmail) }}</div>
        <div class="text-gray-600 text-xs mt-1">{{ count($gmail) }} account{{ count($gmail) !== 1 ? 's' : '' }} connected</div>
    </div>

    <div class="card rounded-xl p-5">
        <div class="text-gray-500 text-xs uppercase tracking-wider mb-1">IMAP messages</div>
        <div class="text-3xl font-bold text-white">{{ number_format($totalImap) }}</div>
        <div class="text-gray-600 text-xs mt-1">{{ count($imap) }} account{{ count($imap) !== 1 ? 's' : '' }} configured</div>
    </div>
</div>

{{-- =========================================================================
     WHMCS
     ========================================================================= --}}
<div class="card rounded-xl overflow-hidden">
    <div class="section-header px-5 py-4 flex items-center gap-3">
        <h2 class="font-semibold text-white">WHMCS</h2>
        <span class="text-gray-600 text-sm">{{ count($whmcs) }} system{{ count($whmcs) !== 1 ? 's' : '' }}</span>
    </div>

    @if (empty($whmcs))
        <div class="px-5 py-6 text-gray-600 text-sm">
            No WHMCS systems configured. Add <code>WHMCS_&lt;SYSTEM&gt;_BASE_URL</code> to <code>.env</code>.
        </div>
    @else
        @foreach ($whmcs as $w)
            <div class="{{ !$loop->first ? 'border-t border-gray-800' : '' }}">
                <div class="px-5 py-3 flex items-center justify-between bg-gray-900/30">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-indigo-400 text-sm font-semibold">{{ $w['system'] }}</span>
                        <span class="text-gray-600 text-xs">{{ $w['base_url'] }}</span>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-gray-500">
                        <span>{{ number_format($w['total']) }} total records</span>
                        @if ($w['last_sync'])
                            @php $sync = \Carbon\Carbon::parse($w['last_sync']); @endphp
                            <span class="{{ $sync->diffInHours() > 24 ? 'text-yellow-500' : 'text-green-500' }}">
                                Last sync {{ $sync->diffForHumans() }}
                            </span>
                        @else
                            <span class="text-gray-600">Never synced</span>
                        @endif
                    </div>
                </div>
                <table class="w-full tbl">
                    <thead>
                        <tr>
                            <th>Entity</th>
                            <th>Records</th>
                            <th>Last sync</th>
                            <th>Cursor position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($w['entities'] as $entity => $data)
                            <tr>
                                <td class="font-mono text-gray-400">{{ $entity }}</td>
                                <td class="text-white font-medium">{{ number_format($data['count']) }}</td>
                                <td>
                                    @if ($data['last_run'])
                                        @php $t = \Carbon\Carbon::parse($data['last_run']); @endphp
                                        <span class="{{ $t->diffInHours() > 24 ? 'text-yellow-500' : 'text-gray-300' }}"
                                              title="{{ $t->format('Y-m-d H:i:s') }}">
                                            {{ $t->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="font-mono text-gray-500 text-xs">
                                    {{ $data['cursor_pos'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>

{{-- =========================================================================
     GMAIL
     ========================================================================= --}}
<div class="card rounded-xl overflow-hidden">
    <div class="section-header px-5 py-4 flex items-center gap-3">
        <h2 class="font-semibold text-white">Gmail / Google OAuth</h2>
        <span class="text-gray-600 text-sm">{{ count($gmail) }} account{{ count($gmail) !== 1 ? 's' : '' }}</span>
    </div>

    @if (empty($gmail))
        <div class="px-5 py-6 text-gray-600 text-sm">
            No Gmail accounts connected. Visit
            <code>/google/auth/&lt;system&gt;</code> to authorize an account.
        </div>
    @else
        <table class="w-full tbl">
            <thead>
                <tr>
                    <th>System</th>
                    <th>Account</th>
                    <th>Messages</th>
                    <th>Last fetched</th>
                    <th>Token authorized</th>
                    <th>Token refreshed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gmail as $g)
                    <tr>
                        <td class="font-mono text-indigo-400">{{ $g['system'] }}</td>
                        <td class="text-white">{{ $g['email'] }}</td>
                        <td class="text-white font-medium">{{ number_format($g['count']) }}</td>
                        <td>
                            @if ($g['last_fetched'])
                                @php $t = \Carbon\Carbon::parse($g['last_fetched']); @endphp
                                <span class="{{ $t->diffInHours() > 24 ? 'text-yellow-500' : 'text-gray-300' }}"
                                      title="{{ $t->format('Y-m-d H:i:s') }}">
                                    {{ $t->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-gray-600">Never</span>
                            @endif
                        </td>
                        <td class="text-gray-500 text-xs" title="{{ $g['token_created'] }}">
                            {{ \Carbon\Carbon::parse($g['token_created'])->format('Y-m-d') }}
                        </td>
                        <td class="text-gray-500 text-xs" title="{{ $g['token_updated'] }}">
                            {{ \Carbon\Carbon::parse($g['token_updated'])->diffForHumans() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- =========================================================================
     IMAP
     ========================================================================= --}}
<div class="card rounded-xl overflow-hidden">
    <div class="section-header px-5 py-4 flex items-center gap-3">
        <h2 class="font-semibold text-white">IMAP</h2>
        <span class="text-gray-600 text-sm">{{ count($imap) }} account{{ count($imap) !== 1 ? 's' : '' }}</span>
        @if (!$system['imap_loaded'])
            <span class="ml-auto text-yellow-500 text-xs">php-imap extension not loaded — import will fail</span>
        @endif
    </div>

    @if (empty($imap))
        <div class="px-5 py-6 text-gray-600 text-sm">
            No IMAP accounts configured. Add <code>IMAP_&lt;ACCOUNT&gt;_HOST</code> to <code>.env</code>.
        </div>
    @else
        @foreach ($imap as $acc)
            <div class="{{ !$loop->first ? 'border-t border-gray-800' : '' }}">
                <div class="px-5 py-3 flex items-start justify-between bg-gray-900/30">
                    <div>
                        <span class="font-mono text-indigo-400 text-sm font-semibold">{{ $acc['account'] }}</span>
                        <div class="text-gray-600 text-xs mt-0.5">
                            {{ $acc['encryption'] }}://{{ $acc['host'] }}:{{ $acc['port'] }}
                            &nbsp;·&nbsp; {{ $acc['username'] }}
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-gray-500 mt-0.5">
                        <span>{{ number_format($acc['total_count']) }} messages total</span>
                        @if ($acc['last_fetched'])
                            @php $t = \Carbon\Carbon::parse($acc['last_fetched']); @endphp
                            <span class="{{ $t->diffInHours() > 24 ? 'text-yellow-500' : 'text-green-500' }}">
                                Last fetch {{ $t->diffForHumans() }}
                            </span>
                        @else
                            <span class="text-gray-600">Never fetched</span>
                        @endif
                    </div>
                </div>
                @if (!empty($acc['mailboxes']))
                    <table class="w-full tbl">
                        <thead>
                            <tr>
                                <th>Mailbox</th>
                                <th>Messages</th>
                                <th>Last fetched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($acc['mailboxes'] as $mb)
                                <tr>
                                    <td class="font-mono text-gray-400">{{ $mb->mailbox }}</td>
                                    <td class="text-white font-medium">{{ number_format($mb->count) }}</td>
                                    <td>
                                        @if ($mb->last_fetched)
                                            @php $t = \Carbon\Carbon::parse($mb->last_fetched); @endphp
                                            <span class="{{ $t->diffInHours() > 24 ? 'text-yellow-500' : 'text-gray-300' }}"
                                                  title="{{ $t->format('Y-m-d H:i:s') }}">
                                                {{ $t->diffForHumans() }}
                                            </span>
                                        @else
                                            <span class="text-gray-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-5 py-3 text-gray-600 text-sm">
                        No messages imported yet. Run <code>php artisan imap:import-messages {{ $acc['account'] }}</code>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>

{{-- =========================================================================
     MIGRATIONS
     ========================================================================= --}}
<div class="card rounded-xl overflow-hidden">
    <div class="section-header px-5 py-4 flex items-center gap-3">
        <h2 class="font-semibold text-white">Migrations</h2>
        @php
            $ranCount     = collect($migrations)->where('ran', true)->count();
            $pendingCount = collect($migrations)->where('ran', false)->count();
        @endphp
        <span class="text-gray-600 text-sm">{{ $ranCount }} ran</span>
        @if ($pendingCount > 0)
            <span class="text-red-400 text-sm font-medium">{{ $pendingCount }} pending</span>
        @endif
    </div>

    <table class="w-full tbl">
        <thead>
            <tr>
                <th>Status</th>
                <th>Migration</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($migrations as $m)
                <tr>
                    <td class="w-20">
                        @if ($m['ran'])
                            <span class="text-green-500 text-xs font-medium">✓ Ran</span>
                        @else
                            <span class="text-red-400 text-xs font-medium">✗ Pending</span>
                        @endif
                    </td>
                    <td class="font-mono text-gray-500 text-xs">{{ $m['name'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

</main>
</body>
</html>
