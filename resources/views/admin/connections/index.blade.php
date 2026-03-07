@extends('admin.layout')

@section('title', 'Connections')

@section('content')

<div x-data="adminPanel()" x-init="init()" @keydown.escape.window="closeModal()">

{{-- Page header --}}
<div class="flex items-center justify-between mb-5">
    <div>
        <h1 class="text-lg font-semibold text-white">Connections</h1>
        <p class="text-gray-500 text-xs mt-0.5">{{ $connections->count() }} connection{{ $connections->count() !== 1 ? 's' : '' }} configured</p>
    </div>
    <div class="flex items-center gap-2">
        <button @click="killAll.open = true" class="btn btn-danger text-xs">
            ■ Kill all jobs
        </button>
        <a href="{{ route('admin.connections.create') }}" class="btn btn-primary">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Connection
        </a>
    </div>
</div>

{{-- Connections table --}}
@if ($connections->isEmpty())
    <div class="card rounded-xl p-12 text-center">
        <div class="text-gray-600 text-sm mb-3">No connections yet.</div>
        <a href="{{ route('admin.connections.create') }}" class="btn btn-primary">Create your first connection</a>
    </div>
@else
    <div class="card rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-gray-500 text-xs uppercase tracking-wider" style="background:#0d1117">
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Type / System</th>
                    <th class="px-4 py-3 text-left font-medium">Schedule</th>
                    <th class="px-4 py-3 text-left font-medium">Last run</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($connections as $conn)
                    @php
                        $run     = $conn->latestRun;
                        $nextRun = $conn->nextRunDate();
                        $missing = $conn->hasCredentials();
                    @endphp
                    <tr class="tbl-row"
                        x-data="connRow('{{ $conn->id }}', '{{ $run?->id }}', '{{ $run?->status ?? 'none' }}')"
                        x-init="init()">

                        {{-- Name --}}
                        <td class="px-4 py-3">
                            <div class="font-medium text-white">{{ $conn->name }}</div>
                            @if (!empty($missing))
                                <div class="text-yellow-500 text-xs mt-0.5">
                                    ⚠ Missing ENV: {{ implode(', ', $missing) }}
                                </div>
                            @endif
                        </td>

                        {{-- Type / System --}}
                        <td class="px-4 py-3">
                            <span class="badge badge-{{ $conn->type }}">{{ strtoupper($conn->type) }}</span>
                            <span class="text-gray-500 text-xs ml-1.5 font-mono">{{ $conn->system_slug }}</span>
                            @if ($conn->type === 'whmcs')
                                <div class="text-gray-600 text-xs mt-0.5">{{ implode(', ', $conn->settings['entities'] ?? []) }}</div>
                            @elseif ($conn->type === 'gmail')
                                <div class="text-gray-600 text-xs mt-0.5">{{ $conn->settings['subject_email'] ?? '' }}</div>
                            @elseif ($conn->type === 'imap')
                                <div class="text-gray-600 text-xs mt-0.5">{{ $conn->settings['mailbox'] ?? 'INBOX' }}</div>
                            @elseif ($conn->type === 'metricscube')
                                @php $linked = $whmcsMap[$conn->settings['whmcs_connection_id'] ?? 0] ?? null @endphp
                                @if ($linked)
                                    <div class="text-gray-600 text-xs mt-0.5">via {{ $linked->name }}</div>
                                @else
                                    <div class="text-yellow-600 text-xs mt-0.5">⚠ No WHMCS linked</div>
                                @endif
                            @elseif ($conn->type === 'discord')
                                @php $gl = $conn->settings['guild_allowlist'] ?? [] @endphp
                                <div class="text-gray-600 text-xs mt-0.5">{{ count($gl) ? count($gl) . ' guild(s)' : 'all guilds' }}</div>
                            @elseif ($conn->type === 'slack')
                                @php $cl = $conn->settings['channel_allowlist'] ?? [] @endphp
                                <div class="text-gray-600 text-xs mt-0.5">{{ count($cl) ? count($cl) . ' channel(s)' : 'all joined channels' }}</div>
                            @endif
                        </td>

                        {{-- Schedule --}}
                        <td class="px-4 py-3">
                            @if ($conn->type === 'metricscube')
                                <span class="text-gray-600 text-xs">Runs with WHMCS</span>
                            @else
                                @php $nextFull = $conn->nextFullRunDate(); @endphp
                                @if ($conn->schedule_enabled)
                                    <div class="text-xs"><span class="text-gray-600">P:</span> <span class="font-mono text-gray-300">{{ $conn->schedule_cron }}</span></div>
                                    <div class="text-gray-600 text-xs">{{ $conn->scheduleLabel() }}</div>
                                    @if ($nextRun)
                                        <div class="text-gray-600 text-xs">Next: {{ \Carbon\Carbon::instance($nextRun)->diffForHumans() }}</div>
                                    @endif
                                @endif
                                @if ($conn->schedule_full_enabled)
                                    <div class="text-xs {{ $conn->schedule_enabled ? 'mt-1.5' : '' }}"><span class="text-gray-600">F:</span> <span class="font-mono text-gray-300">{{ $conn->schedule_full_cron }}</span></div>
                                    <div class="text-gray-600 text-xs">{{ $conn->scheduleFullLabel() }}</div>
                                    @if ($nextFull)
                                        <div class="text-gray-600 text-xs">Next: {{ \Carbon\Carbon::instance($nextFull)->diffForHumans() }}</div>
                                    @endif
                                @endif
                                @if (!$conn->schedule_enabled && !$conn->schedule_full_enabled)
                                    <span class="text-gray-600 text-xs">Manual only</span>
                                @endif
                            @endif
                        </td>

                        {{-- Last run time --}}
                        <td class="px-4 py-3">
                            @if ($run)
                                <div class="text-gray-400 text-xs" title="{{ $run->created_at }}">
                                    {{ $run->created_at->diffForHumans() }}
                                </div>
                                @if ($run->duration_seconds !== null)
                                    <div class="text-gray-600 text-xs">{{ gmdate('H:i:s', $run->duration_seconds) }}</div>
                                @endif
                                <div class="text-gray-600 text-xs">{{ $run->triggered_by }}</div>
                            @else
                                <span class="text-gray-600 text-xs">Never</span>
                            @endif
                        </td>

                        {{-- Status badge (live via polling) --}}
                        <td class="px-4 py-3">
                            @if ($run)
                                <span class="badge"
                                      :class="{
                                          'badge-pending':   status === 'pending',
                                          'badge-running':   status === 'running',
                                          'badge-completed': status === 'completed',
                                          'badge-failed':    status === 'failed',
                                          'badge-pending':   status === 'none',
                                      }">
                                    <template x-if="status === 'running' || status === 'pending'">
                                        <svg class="w-2.5 h-2.5 spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                    </template>
                                    <span x-text="status"></span>
                                </span>
                                @if ($run->error_message)
                                    <div class="text-red-500 text-xs mt-1 max-w-xs truncate" title="{{ $run->error_message }}">
                                        {{ Str::limit($run->error_message, 60) }}
                                    </div>
                                @endif
                            @else
                                <span class="text-gray-600 text-xs">—</span>
                            @endif
                        </td>

                        {{-- Actions --}}
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                {{-- View logs (if has a run) --}}
                                @if ($run)
                                    <button
                                        @click="$dispatch('open-logs', { connId: '{{ $conn->id }}', runId: '{{ $run->id }}', name: '{{ addslashes($conn->name) }}' })"
                                        class="btn btn-secondary text-xs">
                                        Logs
                                    </button>
                                @endif

                                @if ($conn->type !== 'metricscube')
                                {{-- Run now --}}
                                <button
                                    @click="$dispatch('open-run-mode', { connId: {{ $conn->id }}, name: '{{ addslashes($conn->name) }}' })"
                                    :disabled="status === 'running' || status === 'pending'"
                                    class="btn btn-blue text-xs disabled:opacity-40 disabled:cursor-not-allowed">
                                    ▶ Run
                                </button>

                                {{-- Stop --}}
                                <button
                                    x-show="status === 'running' || status === 'pending'"
                                    @click="$dispatch('stop-run', { connId: {{ $conn->id }} })"
                                    class="btn btn-danger text-xs">
                                    ■ Stop
                                </button>
                                @endif

                                {{-- Edit --}}
                                <a href="{{ route('admin.connections.edit', $conn) }}" class="btn btn-secondary text-xs">Edit</a>

                                {{-- Duplicate --}}
                                <form method="POST" action="{{ route('admin.connections.duplicate', $conn) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary text-xs">⧉ Dupe</button>
                                </form>

                                {{-- Delete --}}
                                <form id="delete-form-{{ $conn->id }}" method="POST" action="{{ route('admin.connections.destroy', $conn) }}">
                                    @csrf @method('DELETE')
                                    <button type="button"
                                            @click="$dispatch('confirm-delete', { connId: {{ $conn->id }}, name: '{{ addslashes($conn->name) }}' })"
                                            class="btn btn-danger text-xs">✕</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- =========================================================================
     RUN MODE PICKER MODAL
     ========================================================================= --}}
<div x-cloak x-show="runMode.open"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">
    <div class="card rounded-xl w-full max-w-sm p-6">
        <h3 class="text-white font-semibold text-base mb-1">Run <span class="text-blue-400" x-text="runMode.name"></span></h3>
        <p class="text-gray-500 text-xs mb-4">Choose sync mode for this run</p>

        <div class="space-y-2 mb-5">
            <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                   :class="runMode.mode === 'partial' ? 'border-blue-600 bg-blue-950/30' : 'border-gray-700 hover:border-gray-600'">
                <input type="radio" name="run_mode" value="partial" x-model="runMode.mode" class="mt-0.5 text-blue-500">
                <div>
                    <div class="text-sm font-medium text-white">Partial sync</div>
                    <div class="text-xs text-gray-500 mt-0.5">Only new messages since last sync. Fast.</div>
                </div>
            </label>
            <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                   :class="runMode.mode === 'full' ? 'border-blue-600 bg-blue-950/30' : 'border-gray-700 hover:border-gray-600'">
                <input type="radio" name="run_mode" value="full" x-model="runMode.mode" class="mt-0.5 text-blue-500">
                <div>
                    <div class="text-sm font-medium text-white">Full sync</div>
                    <div class="text-xs text-gray-500 mt-0.5">Scans entire mailbox from beginning. Slower.</div>
                </div>
            </label>
        </div>

        <div class="flex items-center justify-end gap-3">
            <button @click="runMode.open = false" class="btn btn-secondary">Cancel</button>
            <button @click="startRun()" class="btn btn-blue">▶ Run</button>
        </div>
    </div>
</div>

{{-- =========================================================================
     KILL ALL CONFIRMATION MODAL
     ========================================================================= --}}
<div x-cloak x-show="killAll.open"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">
    <div class="card rounded-xl w-full max-w-md p-6">
        <h3 class="text-white font-semibold text-base mb-3">Kill all running jobs?</h3>
        <ul class="text-gray-500 text-xs space-y-1 mb-5 list-disc list-inside">
            <li>All <span class="text-yellow-400">pending</span> and <span class="text-blue-400">running</span> jobs will be immediately terminated</li>
            <li>Queue will be cleared — no scheduled runs will start</li>
            <li>Each affected run will be marked as <span class="text-red-400">failed</span></li>
            <li>Imported data already written to DB stays intact</li>
        </ul>
        <div class="flex items-center justify-end gap-3">
            <button @click="killAll.open = false" class="btn btn-secondary">Cancel</button>
            <button @click="doKillAll()" class="btn btn-danger">■ Kill all</button>
        </div>
    </div>
</div>

{{-- =========================================================================
     DELETE CONFIRMATION MODAL
     ========================================================================= --}}
<div x-cloak x-show="del.open"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">
    <div class="card rounded-xl w-full max-w-md p-6">
        <h3 class="text-white font-semibold text-base mb-3">Delete connection?</h3>
        <p class="text-gray-300 text-sm mb-2">
            You are about to permanently delete
            <span class="text-white font-semibold" x-text="del.name"></span>.
        </p>
        <ul class="text-gray-500 text-xs space-y-1 mb-5 list-disc list-inside">
            <li>All run history and logs will be deleted</li>
            <li>Credentials stored for this connection will be deleted</li>
            <li>Scheduled runs will stop</li>
            <li>This cannot be undone</li>
        </ul>
        <div class="flex items-center justify-end gap-3">
            <button @click="del.open = false" class="btn btn-secondary">Cancel</button>
            <button @click="confirmDelete()" class="btn btn-danger">Delete permanently</button>
        </div>
    </div>
</div>

{{-- =========================================================================
     LOG MODAL
     ========================================================================= --}}
<div x-cloak x-show="modal.open"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(0,0,0,.75)">

    <div class="card rounded-xl w-full max-w-3xl flex flex-col" style="max-height:85vh">

        {{-- Modal header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-800">
            <div>
                <div class="font-semibold text-white text-sm" x-text="modal.name"></div>
                <div class="text-xs text-gray-500 mt-0.5">
                    Run
                    <template x-if="modal.runId">
                        <span x-text="'#' + modal.runId"></span>
                    </template>
                    &nbsp;—&nbsp;
                    <span :class="{
                        'text-yellow-400': modal.status === 'pending',
                        'text-blue-400':   modal.status === 'running',
                        'text-green-400':  modal.status === 'completed',
                        'text-red-400':    modal.status === 'failed',
                    }" x-text="modal.status"></span>
                    <template x-if="modal.status === 'running' || modal.status === 'pending'">
                        <svg class="inline w-3 h-3 spin ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </template>
                </div>
            </div>
            <button @click="closeModal()" class="text-gray-500 hover:text-gray-300 text-xl leading-none">&times;</button>
        </div>

        {{-- Run history dropdown --}}
        <div class="px-5 py-2 border-b border-gray-800" style="background:#0d1117">
            <div class="relative" @click.outside="modal.runsOpen = false">
                {{-- Trigger button --}}
                <div class="flex items-center gap-2">
                    <span class="text-gray-600 text-xs shrink-0">History:</span>
                    <button
                        @click="modal.runsOpen = !modal.runsOpen; if (modal.runsOpen) $nextTick(() => $refs.runsSearchInput?.focus())"
                        class="flex-1 text-left flex items-center justify-between gap-2 rounded px-2 py-1 text-xs font-mono border border-gray-700 hover:border-gray-500 transition-colors"
                        style="background:#161b22; color:#e6edf3">
                        <span x-text="modal.runsLoading ? 'Loading…' : (modal.runs.length ? formatRunLabel(modal.runs.find(r => r.id == modal.runId) ?? modal.runs[0]) : 'No runs')"></span>
                        <svg class="w-3 h-3 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                </div>

                {{-- Dropdown panel --}}
                <div x-show="modal.runsOpen" x-cloak
                     class="absolute left-0 right-0 mt-1 rounded-lg border border-gray-700 shadow-xl z-10 overflow-hidden"
                     style="background:#161b22; top:100%">

                    {{-- Search input --}}
                    <div class="p-2 border-b border-gray-700">
                        <input
                            type="text"
                            x-model="modal.runsSearch"
                            placeholder="Search by ID, status, date…"
                            class="w-full rounded px-2 py-1 text-xs font-mono border border-gray-600 focus:border-blue-500 focus:outline-none"
                            style="background:#0d1117; color:#e6edf3"
                            @click.stop
                            x-ref="runsSearchInput">
                    </div>

                    {{-- Run list --}}
                    <div class="overflow-y-auto" style="max-height:220px">
                        <template x-if="filteredRuns.length === 0">
                            <div class="px-3 py-2 text-xs text-gray-600">No runs match.</div>
                        </template>
                        <template x-for="run in filteredRuns" :key="run.id">
                            <button
                                @click="selectRun(run.id)"
                                class="w-full text-left px-3 py-1.5 text-xs font-mono hover:bg-gray-800 flex items-center justify-between gap-3 transition-colors"
                                :class="run.id == modal.runId ? 'bg-gray-800' : ''"
                                :style="run.id == modal.runId ? 'color:#e6edf3' : 'color:#8b949e'">
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
        <div id="modal-log" x-ref="logEl"
             class="flex-1 overflow-y-auto font-mono text-xs p-4 space-y-0.5"
             style="background:#0d1117; min-height:300px">

            <template x-if="modal.logs.length === 0">
                <div class="text-gray-600">Waiting for output…</div>
            </template>

            <template x-for="(line, i) in modal.logs" :key="i">
                <div class="leading-5"
                     :class="{
                         'text-red-400':    line.level === 'error',
                         'text-yellow-400': line.level === 'warning',
                         'text-gray-400':   !line.level || line.level === 'info',
                     }">
                    <span class="text-gray-700 select-none mr-2"
                          x-text="new Date(line.t).toTimeString().slice(0,8)"></span>
                    <span x-text="line.msg"></span>
                </div>
            </template>
        </div>

        {{-- Modal footer --}}
        <div class="px-5 py-3 border-t border-gray-800 flex items-center justify-between text-xs text-gray-600">
            <span x-text="modal.logs.length + ' lines'"></span>
            <button @click="closeModal()" class="btn btn-secondary text-xs">Close</button>
        </div>
    </div>
</div>

</div>{{-- end x-data --}}

<script>
function adminPanel() {
    return {
        modal:   { open: false, name: '', connId: null, runId: null, logs: [], status: 'idle', es: null, runs: [], runsSearch: '', runsOpen: false, runsLoading: false },
        del:     { open: false, name: '', formId: null },
        killAll: { open: false },
        runMode: { open: false, connId: null, name: '', mode: 'partial' },

        init() {
            this.$el.addEventListener('open-run-mode', e => {
                this.runMode = { open: true, connId: e.detail.connId, name: e.detail.name, mode: 'partial' };
            });
            this.$el.addEventListener('open-logs', e => {
                this.viewLogs(e.detail.connId, e.detail.runId, e.detail.name);
            });
            this.$el.addEventListener('stop-run', e => {
                this.stopRun(e.detail.connId);
            });
            this.$el.addEventListener('confirm-delete', e => {
                this.del = { open: true, name: e.detail.name, formId: `delete-form-${e.detail.connId}` };
            });
        },

        async startRun() {
            const { connId, name, mode } = this.runMode;
            this.runMode.open = false;
            await this.triggerRun(connId, name, mode);
        },

        async doKillAll() {
            this.killAll.open = false;
            try {
                await fetch('/admin/connections/kill-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
            } catch (_) {}
            // Reload so statuses refresh
            window.location.reload();
        },

        async stopRun(connId) {
            try {
                await fetch(`/admin/connections/${connId}/stop`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                // status will update via existing polling in connRow
            } catch (_) {}
        },

        confirmDelete() {
            const form = document.getElementById(this.del.formId);
            if (form) form.submit();
            this.del.open = false;
        },

        async triggerRun(connId, name, mode = 'partial') {
            // Guard against double-click before Alpine re-renders disabled state
            if (this._launching) return;
            this._launching = true;

            // If the modal is already open showing another run, do NOT hijack it.
            // Start the run silently — connRow polling will update the status badge.
            const openModal = !this.modal.open;

            if (openModal) {
                // Clear any zombie interval before overwriting modal
                if (this.modal.es) { clearInterval(this.modal.es); this.modal.es = null; }
                this.modal = { open: true, name, connId, runId: null, logs: [], status: 'pending', es: null, runs: [], runsSearch: '', runsOpen: false, runsLoading: false };
            }

            try {
                const r = await fetch(`/admin/connections/${connId}/run`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ mode }),
                });
                const d = await r.json();

                // Always notify the table row so its badge updates
                window.dispatchEvent(new CustomEvent('run-started-event', {
                    detail: { connId: String(connId), runId: String(d.run_id) }
                }));

                if (openModal) {
                    this.modal.runId  = d.run_id;
                    this.modal.status = 'running';
                    if (d.already_active) {
                        this.modal.logs.push({ t: Date.now(), level: 'warning', msg: 'Run already in progress — showing existing run.' });
                    }
                    this.connectSSE(d.run_id);
                }
            } catch (e) {
                if (openModal) {
                    this.modal.status = 'failed';
                    this.modal.logs.push({ t: Date.now(), level: 'error', msg: 'Failed to start: ' + e.message });
                }
            } finally {
                this._launching = false;
            }
        },

        viewLogs(connId, runId, name) {
            // Clear any existing SSE interval before switching modal
            if (this.modal.es) { clearInterval(this.modal.es); this.modal.es = null; }
            this.modal = { open: true, name, connId, runId, logs: [], status: 'loading', es: null, runs: [], runsSearch: '', runsOpen: false, runsLoading: true };

            // Load run history and current run's logs in parallel
            Promise.all([
                fetch(`/admin/connections/${connId}/runs`).then(r => r.json()),
                fetch(`/admin/connections/runs/${runId}/logs`).then(r => r.json()),
            ]).then(([history, logs]) => {
                this.modal.runs        = history.runs ?? [];
                this.modal.runsLoading = false;
                this.modal.logs        = logs.log_lines ?? [];
                this.modal.status      = logs.status;

                // If run is still active, start live polling
                if (['pending', 'running'].includes(logs.status)) {
                    this.connectSSE(runId);
                }
            });
        },

        get filteredRuns() {
            const q = this.modal.runsSearch.trim().toLowerCase();
            if (!q) return this.modal.runs;
            return this.modal.runs.filter(r =>
                String(r.id).includes(q) ||
                r.status.includes(q) ||
                (r.triggered_by ?? '').includes(q) ||
                (r.created_at ?? '').toLowerCase().includes(q)
            );
        },

        selectRun(runId) {
            if (this.modal.runId === runId) return;
            this.modal.runId  = runId;
            this.modal.logs   = [];
            this.modal.status = 'loading';
            this.modal.runsOpen = false;

            fetch(`/admin/connections/runs/${runId}/logs`)
                .then(r => r.json())
                .then(d => {
                    this.modal.logs   = d.log_lines ?? [];
                    this.modal.status = d.status;
                    if (['pending', 'running'].includes(d.status)) {
                        this.connectSSE(runId);
                    }
                });
        },

        formatRunLabel(run) {
            const date = run.created_at ? new Date(run.created_at).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '';
            const dur  = run.duration_seconds != null ? ` — ${run.duration_seconds}s` : '';
            return `#${run.id} ${run.status}${dur} · ${date}`;
        },

        connectSSE(runId) {
            if (this.modal.es) { clearInterval(this.modal.es); this.modal.es = null; }

            // Capture the interval handle in a closure so each interval can reliably
            // clear *itself* — not whatever this.modal.es happens to point to at
            // the time the callback fires (which may already be a newer interval).
            const iv = setInterval(async () => {
                // Stale interval: modal switched to a different run — kill self and exit
                if (this.modal.runId != runId) {
                    clearInterval(iv);
                    if (this.modal.es === iv) this.modal.es = null;
                    return;
                }

                try {
                    const r = await fetch(`/admin/connections/runs/${runId}/logs`);
                    if (!r.ok) return;
                    const d = await r.json();

                    this.modal.logs   = d.log_lines ?? [];
                    this.modal.status = d.status;

                    this.$nextTick(() => {
                        const el = this.$refs.logEl;
                        if (el) el.scrollTop = el.scrollHeight;
                    });

                    if (['completed', 'failed'].includes(d.status)) {
                        clearInterval(iv);
                        if (this.modal.es === iv) this.modal.es = null;
                        if (this.modal.connId) {
                            fetch(`/admin/connections/${this.modal.connId}/runs`)
                                .then(r => r.json())
                                .then(h => { this.modal.runs = h.runs ?? []; });
                        }
                        setTimeout(() => window.location.reload(), 2000);
                    }
                } catch (_) {}
            }, 2000);

            this.modal.es = iv;
        },

        closeModal() {
            // Keep the SSE interval alive — it will auto-reload the page when the
            // run completes, even with the modal closed. connectSSE() clears the
            // old interval when a new one starts, so no leak.
            this.modal.open = false;
        }
    };
}

function connRow(connId, runId, initialStatus) {
    return {
        connId: String(connId),
        status: initialStatus,
        runId: runId,
        interval: null,

        init() {
            if (this.runId && this.runId !== 'null' && ['pending', 'running'].includes(this.status)) {
                this.startPolling();
            }
            // Listen for new runs dispatched by adminPanel.triggerRun()
            window.addEventListener('run-started-event', e => {
                if (e.detail.connId === this.connId) {
                    this.runId  = e.detail.runId;
                    this.status = 'pending';
                    if (!this.interval) this.startPolling();
                }
            });
        },

        startPolling() {
            this.interval = setInterval(async () => {
                try {
                    const r = await fetch(`/admin/connections/runs/${this.runId}/status`);
                    const d = await r.json();
                    this.status = d.status;
                    if (['completed', 'failed', 'cancelled'].includes(d.status)) {
                        clearInterval(this.interval);
                        this.interval = null;
                        // Reload to refresh last_run time, duration, etc.
                        setTimeout(() => window.location.reload(), 1500);
                    }
                } catch (_) {}
            }, 4000);
        }
    };
}
</script>

@endsection
