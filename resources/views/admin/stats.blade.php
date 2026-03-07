@extends('admin.layout')

@section('title', 'Data Stats')

@section('content')

<div x-data="statsPanel()" x-init="init()" @keydown.escape.window="closeAll()">

<div class="mb-5">
    <h1 class="text-lg font-semibold text-white">Data Stats</h1>
    <p class="text-gray-500 text-xs mt-0.5">Record counts per connection. "24h" counts rows imported in the last 24 hours.</p>
</div>

{{-- Run stats cards --}}
@if (!empty($runStats))
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @php
        $cards = [
            ['label' => 'Runs (24h)',   'value' => $runStats['total_runs_24h'] ?? '—',      'color' => 'text-white',      'filter' => 'all',       'since' => '24h'],
            ['label' => 'Completed',    'value' => $runStats['completed_24h'] ?? '—',        'color' => 'text-green-400',  'filter' => 'completed', 'since' => '24h'],
            ['label' => 'Failed',       'value' => $runStats['failed_24h'] ?? '—',           'color' => 'text-red-400',    'filter' => 'failed',    'since' => '24h'],
            ['label' => 'Running now',  'value' => $runStats['currently_running'] ?? '—',   'color' => 'text-blue-400',   'filter' => 'running',   'since' => ''],
        ];
    @endphp
    @foreach ($cards as $card)
        <button
            @click="openRunsModal('{{ $card['filter'] }}', '{{ $card['since'] }}', '{{ $card['label'] }}')"
            class="card rounded-xl px-4 py-3 text-left hover:ring-1 hover:ring-gray-600 transition-all group">
            <div class="text-gray-500 text-xs mb-1 group-hover:text-gray-400 transition-colors">{{ $card['label'] }}</div>
            <div class="text-2xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</div>
            <div class="text-gray-700 text-xs mt-1 group-hover:text-gray-500 transition-colors">click to browse →</div>
        </button>
    @endforeach
</div>
@endif

{{-- Per-connection stats --}}
@if ($connStats->isEmpty())
    <div class="card rounded-xl p-12 text-center">
        <div class="text-gray-600 text-sm mb-3">No connections configured yet.</div>
        <a href="{{ route('admin.connections.create') }}" class="btn btn-primary">Create your first connection</a>
    </div>
@else
    <div class="space-y-4">
        @foreach ($connStats as $item)
            @php
                $conn = $item['connection'];
                $run  = $conn->latestRun;
            @endphp
            <div class="card rounded-xl overflow-hidden">

                {{-- Connection header --}}
                <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="badge badge-{{ $conn->type }}">{{ strtoupper($conn->type) }}</span>
                        <span class="font-semibold text-white text-sm">{{ $conn->name }}</span>
                        <span class="font-mono text-gray-500 text-xs">{{ $conn->system_slug }}</span>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-gray-500">
                        @if ($run)
                            <span>Last run:
                                <span class="{{ match($run->status) {
                                    'completed' => 'text-green-400',
                                    'failed'    => 'text-red-400',
                                    'running'   => 'text-blue-400',
                                    default     => 'text-gray-400',
                                } }}">{{ $run->status }}</span>
                                · {{ $run->created_at->diffForHumans() }}
                            </span>
                        @else
                            <span class="text-gray-600">Never run</span>
                        @endif
                        @if ($run)
                            <button
                                @click="openLogsModal({{ $conn->id }}, {{ $run->id }}, '{{ addslashes($conn->name) }}')"
                                class="btn btn-secondary text-xs">
                                Logs
                            </button>
                        @endif
                        <a href="{{ route('admin.connections.edit', $conn) }}" class="text-gray-500 hover:text-gray-300 transition">Edit →</a>
                    </div>
                </div>

                {{-- Tables for this connection --}}
                @if (empty($item['tables']))
                    <div class="px-5 py-4 text-gray-600 text-xs">No data tables mapped for this connection type.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500 text-xs uppercase tracking-wider" style="background:#0d1117">
                                <th class="px-5 py-2.5 text-left font-medium">Entity</th>
                                <th class="px-5 py-2.5 text-left font-medium">Table</th>
                                <th class="px-5 py-2.5 text-right font-medium">Total records</th>
                                <th class="px-5 py-2.5 text-right font-medium">Imported 24h</th>
                                <th class="px-5 py-2.5 text-left font-medium">Latest record</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item['tables'] as $tbl)
                                <tr class="tbl-row">
                                    <td class="px-5 py-3 text-gray-300 text-xs font-medium">{{ $tbl['label'] }}</td>
                                    <td class="px-5 py-3 font-mono text-gray-600 text-xs">{{ $tbl['table'] }}</td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($tbl['error'])
                                            <span class="text-red-500 text-xs" title="{{ $tbl['error'] }}">error</span>
                                        @else
                                            <span class="text-white font-semibold">{{ number_format($tbl['total']) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($tbl['changed_24h'] !== null)
                                            <span class="{{ $tbl['changed_24h'] > 0 ? 'text-green-400' : 'text-gray-600' }} font-medium">
                                                +{{ number_format($tbl['changed_24h']) }}
                                            </span>
                                        @else
                                            <span class="text-gray-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">
                                        @if ($tbl['newest_at'])
                                            {{ \Carbon\Carbon::parse($tbl['newest_at'])->diffForHumans() }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    </div>
@endif

{{-- =========================================================================
     RUNS LIST MODAL (cards click)
     ========================================================================= --}}
<div x-cloak x-show="runsModal.open"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">

    <div class="card rounded-xl w-full max-w-2xl flex flex-col" style="max-height:80vh">

        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-800">
            <div>
                <div class="font-semibold text-white text-sm" x-text="runsModal.label"></div>
                <div class="text-xs text-gray-500 mt-0.5" x-text="runsModal.loading ? 'Loading…' : (runsModal.runs.length + ' runs')"></div>
            </div>
            <button @click="runsModal.open = false" class="text-gray-500 hover:text-gray-300 text-xl leading-none">&times;</button>
        </div>

        {{-- Search --}}
        <div class="px-4 py-2 border-b border-gray-800" style="background:#0d1117">
            <input
                type="text"
                x-model="runsModal.search"
                placeholder="Search by connection, ID, status…"
                class="w-full rounded px-3 py-1.5 text-xs font-mono border border-gray-700 focus:border-blue-500 focus:outline-none"
                style="background:#161b22; color:#e6edf3">
        </div>

        {{-- Runs list --}}
        <div class="flex-1 overflow-y-auto">
            <template x-if="runsModal.loading">
                <div class="px-5 py-8 text-center text-gray-600 text-sm">Loading…</div>
            </template>
            <template x-if="!runsModal.loading && filteredRunsList.length === 0">
                <div class="px-5 py-8 text-center text-gray-600 text-sm">No runs found.</div>
            </template>
            <template x-if="!runsModal.loading && filteredRunsList.length > 0">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-gray-500 uppercase tracking-wider" style="background:#0d1117">
                            <th class="px-4 py-2 text-left font-medium">#</th>
                            <th class="px-4 py-2 text-left font-medium">Connection</th>
                            <th class="px-4 py-2 text-left font-medium">Status</th>
                            <th class="px-4 py-2 text-left font-medium">When</th>
                            <th class="px-4 py-2 text-left font-medium">Duration</th>
                            <th class="px-4 py-2 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="run in filteredRunsList" :key="run.id">
                            <tr class="tbl-row">
                                <td class="px-4 py-2.5 font-mono text-gray-500" x-text="'#' + run.id"></td>
                                <td class="px-4 py-2.5">
                                    <span :class="{
                                        'badge-whmcs':        run.connection_type === 'whmcs',
                                        'badge-gmail':        run.connection_type === 'gmail',
                                        'badge-imap':         run.connection_type === 'imap',
                                        'badge-metricscube':  run.connection_type === 'metricscube',
                                    }" class="badge text-xs mr-1.5" x-text="(run.connection_type ?? '').toUpperCase()"></span>
                                    <span class="text-gray-300" x-text="run.connection_name ?? '—'"></span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span :class="{
                                        'text-yellow-400': run.status === 'pending',
                                        'text-blue-400':   run.status === 'running',
                                        'text-green-400':  run.status === 'completed',
                                        'text-red-400':    run.status === 'failed',
                                    }" x-text="run.status"></span>
                                    <template x-if="run.error_message">
                                        <div class="text-red-500 text-xs mt-0.5 max-w-[200px] truncate" x-text="run.error_message" :title="run.error_message"></div>
                                    </template>
                                </td>
                                <td class="px-4 py-2.5 text-gray-500" x-text="run.created_at ? new Date(run.created_at).toLocaleString('pl-PL', {dateStyle:'short',timeStyle:'short'}) : '—'"></td>
                                <td class="px-4 py-2.5 text-gray-500 font-mono" x-text="run.duration_seconds != null ? run.duration_seconds + 's' : '—'"></td>
                                <td class="px-4 py-2.5 text-right">
                                    <button
                                        @click="openLogsModal(run.connection_id, run.id, run.connection_name)"
                                        class="btn btn-secondary text-xs">
                                        Logs
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>
        </div>

        {{-- Pagination footer --}}
        <div class="px-5 py-3 border-t border-gray-800 flex items-center justify-between">
            <span class="text-xs text-gray-600"
                  x-text="runsModal.total > 0 ? runsModal.total + ' total · page ' + runsModal.page + ' of ' + runsModal.totalPages : ''">
            </span>
            <div class="flex items-center gap-2">
                <button
                    @click="goToPage(runsModal.page - 1)"
                    :disabled="runsModal.page <= 1 || runsModal.loading"
                    class="btn btn-secondary text-xs disabled:opacity-40 disabled:cursor-not-allowed">
                    ← Prev
                </button>
                <span class="text-xs text-gray-500 font-mono min-w-[60px] text-center"
                      x-text="runsModal.totalPages > 0 ? runsModal.page + ' / ' + runsModal.totalPages : '—'">
                </span>
                <button
                    @click="goToPage(runsModal.page + 1)"
                    :disabled="runsModal.page >= runsModal.totalPages || runsModal.loading"
                    class="btn btn-secondary text-xs disabled:opacity-40 disabled:cursor-not-allowed">
                    Next →
                </button>
                <button @click="runsModal.open = false" class="btn btn-secondary text-xs">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- =========================================================================
     LOG MODAL
     ========================================================================= --}}
<div x-cloak x-show="logsModal.open"
     class="fixed inset-0 z-[60] flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">

    <div class="card rounded-xl w-full max-w-3xl flex flex-col" style="max-height:85vh">

        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-800">
            <div>
                <div class="font-semibold text-white text-sm" x-text="logsModal.name"></div>
                <div class="text-xs text-gray-500 mt-0.5">
                    Run
                    <template x-if="logsModal.runId">
                        <span x-text="'#' + logsModal.runId"></span>
                    </template>
                    &nbsp;—&nbsp;
                    <span :class="{
                        'text-yellow-400': logsModal.status === 'pending',
                        'text-blue-400':   logsModal.status === 'running',
                        'text-green-400':  logsModal.status === 'completed',
                        'text-red-400':    logsModal.status === 'failed',
                    }" x-text="logsModal.status"></span>
                </div>
            </div>
            <button @click="logsModal.open = false" class="text-gray-500 hover:text-gray-300 text-xl leading-none">&times;</button>
        </div>

        {{-- Run history dropdown --}}
        <div class="px-5 py-2 border-b border-gray-800" style="background:#0d1117">
            <div class="relative" @click.outside="logsModal.runsOpen = false">
                <div class="flex items-center gap-2">
                    <span class="text-gray-600 text-xs shrink-0">History:</span>
                    <button
                        @click="logsModal.runsOpen = !logsModal.runsOpen; if (logsModal.runsOpen) $nextTick(() => $refs.logsRunsSearch?.focus())"
                        class="flex-1 text-left flex items-center justify-between gap-2 rounded px-2 py-1 text-xs font-mono border border-gray-700 hover:border-gray-500 transition-colors"
                        style="background:#161b22; color:#e6edf3">
                        <span x-text="logsModal.runsLoading ? 'Loading…' : (logsModal.runs.length ? formatRunLabel(logsModal.runs.find(r => r.id == logsModal.runId) ?? logsModal.runs[0]) : 'No runs')"></span>
                        <svg class="w-3 h-3 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                </div>
                <div x-show="logsModal.runsOpen" x-cloak
                     class="absolute left-0 right-0 mt-1 rounded-lg border border-gray-700 shadow-xl z-10 overflow-hidden"
                     style="background:#161b22; top:100%">
                    <div class="p-2 border-b border-gray-700">
                        <input type="text" x-model="logsModal.runsSearch"
                               placeholder="Search by ID, status, date…"
                               class="w-full rounded px-2 py-1 text-xs font-mono border border-gray-600 focus:border-blue-500 focus:outline-none"
                               style="background:#0d1117; color:#e6edf3"
                               @click.stop x-ref="logsRunsSearch">
                    </div>
                    <div class="overflow-y-auto" style="max-height:220px">
                        <template x-if="filteredLogsRuns.length === 0">
                            <div class="px-3 py-2 text-xs text-gray-600">No runs match.</div>
                        </template>
                        <template x-for="run in filteredLogsRuns" :key="run.id">
                            <button @click="selectLogRun(run.id)"
                                    class="w-full text-left px-3 py-1.5 text-xs font-mono hover:bg-gray-800 flex items-center justify-between gap-3 transition-colors"
                                    :class="run.id == logsModal.runId ? 'bg-gray-800' : ''"
                                    :style="run.id == logsModal.runId ? 'color:#e6edf3' : 'color:#8b949e'">
                                <span>
                                    <span class="text-gray-500">#</span><span x-text="run.id"></span>
                                    &nbsp;
                                    <span :class="{
                                        'text-yellow-400': run.status === 'pending',
                                        'text-blue-400':   run.status === 'running',
                                        'text-green-400':  run.status === 'completed',
                                        'text-red-400':    run.status === 'failed',
                                    }" x-text="run.status"></span>
                                </span>
                                <span class="text-gray-600 shrink-0" x-text="run.created_at ? new Date(run.created_at).toLocaleString('pl-PL', {dateStyle:'short',timeStyle:'short'}) : ''"></span>
                            </button>
                        </template>
                    </div>
                    <div class="px-3 py-1.5 border-t border-gray-800 text-xs text-gray-700">Newest 50 runs shown</div>
                </div>
            </div>
        </div>

        {{-- Log output --}}
        <div x-ref="logEl" class="flex-1 overflow-y-auto font-mono text-xs p-4 space-y-0.5" style="background:#0d1117; min-height:300px">
            <template x-if="logsModal.status === 'loading'">
                <div class="text-gray-600">Loading…</div>
            </template>
            <template x-if="logsModal.status !== 'loading' && logsModal.logs.length === 0">
                <div class="text-gray-600">No log output.</div>
            </template>
            <template x-for="(line, i) in logsModal.logs" :key="i">
                <div class="leading-5" :class="{
                    'text-red-400':    line.level === 'error',
                    'text-yellow-400': line.level === 'warning',
                    'text-gray-400':   !line.level || line.level === 'info',
                }">
                    <span class="text-gray-700 select-none mr-2" x-text="new Date(line.t).toTimeString().slice(0,8)"></span>
                    <span x-text="line.msg"></span>
                </div>
            </template>
        </div>

        {{-- Footer --}}
        <div class="px-5 py-3 border-t border-gray-800 flex items-center justify-between text-xs text-gray-600">
            <span x-text="logsModal.logs.length + ' lines'"></span>
            <button @click="logsModal.open = false" class="btn btn-secondary text-xs">Close</button>
        </div>
    </div>
</div>

</div>{{-- end x-data --}}

<script>
function statsPanel() {
    return {
        runsModal: { open: false, label: '', filter: '', since: '', runs: [], search: '', loading: false, page: 1, totalPages: 1, total: 0, perPage: 50 },
        logsModal: { open: false, name: '', connId: null, runId: null, logs: [], status: 'idle', runs: [], runsSearch: '', runsOpen: false, runsLoading: false },

        init() {},

        closeAll() {
            this.logsModal.open = false;
            this.logsModal.runsOpen = false;
            this.runsModal.open = false;
        },

        // ── Runs list modal ───────────────────────────────────────────────────

        async openRunsModal(filter, since, label) {
            this.runsModal = { open: true, label, filter, since, runs: [], search: '', loading: true, page: 1, totalPages: 1, total: 0, perPage: 50 };
            await this.fetchRunsPage(1);
        },

        async fetchRunsPage(page) {
            this.runsModal.loading = true;
            this.runsModal.search  = '';

            const params = new URLSearchParams({ page });
            if (this.runsModal.filter !== 'all') params.set('status', this.runsModal.filter);
            if (this.runsModal.since)            params.set('since',  this.runsModal.since);

            try {
                const r = await fetch('/admin/connections/runs?' + params.toString());
                const d = await r.json();
                this.runsModal.runs       = d.runs        ?? [];
                this.runsModal.page       = d.page        ?? page;
                this.runsModal.totalPages = d.total_pages ?? 1;
                this.runsModal.total      = d.total       ?? 0;
                this.runsModal.perPage    = d.per_page    ?? 50;
            } catch (_) {}
            this.runsModal.loading = false;
        },

        async goToPage(page) {
            if (page < 1 || page > this.runsModal.totalPages || this.runsModal.loading) return;
            await this.fetchRunsPage(page);
        },

        get filteredRunsList() {
            const q = this.runsModal.search.trim().toLowerCase();
            if (!q) return this.runsModal.runs;
            return this.runsModal.runs.filter(r =>
                String(r.id).includes(q) ||
                (r.connection_name ?? '').toLowerCase().includes(q) ||
                (r.connection_type ?? '').includes(q) ||
                r.status.includes(q)
            );
        },

        // ── Log modal ─────────────────────────────────────────────────────────

        openLogsModal(connId, runId, name) {
            this.logsModal = { open: true, name, connId, runId, logs: [], status: 'loading', runs: [], runsSearch: '', runsOpen: false, runsLoading: true };

            Promise.all([
                fetch(`/admin/connections/${connId}/runs`).then(r => r.json()),
                fetch(`/admin/connections/runs/${runId}/logs`).then(r => r.json()),
            ]).then(([history, logs]) => {
                this.logsModal.runs        = history.runs ?? [];
                this.logsModal.runsLoading = false;
                this.logsModal.logs        = logs.log_lines ?? [];
                this.logsModal.status      = logs.status;
            });
        },

        selectLogRun(runId) {
            if (this.logsModal.runId === runId) return;
            this.logsModal.runId    = runId;
            this.logsModal.logs     = [];
            this.logsModal.status   = 'loading';
            this.logsModal.runsOpen = false;

            fetch(`/admin/connections/runs/${runId}/logs`)
                .then(r => r.json())
                .then(d => {
                    this.logsModal.logs   = d.log_lines ?? [];
                    this.logsModal.status = d.status;
                });
        },

        get filteredLogsRuns() {
            const q = this.logsModal.runsSearch.trim().toLowerCase();
            if (!q) return this.logsModal.runs;
            return this.logsModal.runs.filter(r =>
                String(r.id).includes(q) ||
                r.status.includes(q) ||
                (r.triggered_by ?? '').includes(q) ||
                (r.created_at ?? '').toLowerCase().includes(q)
            );
        },

        formatRunLabel(run) {
            if (!run) return '—';
            const date = run.created_at ? new Date(run.created_at).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '';
            const dur  = run.duration_seconds != null ? ` — ${run.duration_seconds}s` : '';
            return `#${run.id} ${run.status}${dur} · ${date}`;
        },
    };
}
</script>

@endsection
