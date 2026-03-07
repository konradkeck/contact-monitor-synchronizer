@extends('admin.layout')

@section('title', $connection ? 'Edit Connection' : 'New Connection')

@section('content')

@php
    $isEdit   = $connection !== null;
    $settings = $connection?->settings ?? [];
    $type     = old('type', $connection?->type ?? 'whmcs');
@endphp

<div x-data="connForm('{{ $type }}', '{{ url('/google/callback/') }}/', '{{ old('system_slug', $connection?->system_slug ?? '') }}', {{ $isEdit ? 'true' : 'false' }})" class="max-w-2xl">

    {{-- Header --}}
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('admin.connections.index') }}" class="text-gray-500 hover:text-gray-300 transition text-sm">← Connections</a>
        <span class="text-gray-700">/</span>
        <h1 class="text-lg font-semibold text-white">
            {{ $isEdit ? 'Edit: ' . $connection->name : 'New Connection' }}
        </h1>
    </div>

    @if ($errors->any())
        <div class="card rounded-xl px-4 py-3 mb-5 border-red-800/50" style="background:rgba(248,81,73,.08)">
            <ul class="text-red-400 text-sm space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="connection-form" method="POST"
          action="{{ $isEdit ? route('admin.connections.update', $connection) : route('admin.connections.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        {{-- ================================================================
             GENERAL
             ================================================================ --}}
        <div class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">General</div>
            <div class="px-5 py-4 space-y-4">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Display name</label>
                    <input type="text" name="name" value="{{ old('name', $connection?->name) }}"
                           @input="onNameInput($event.target.value)"
                           class="input" placeholder="e.g. Hosting WHMCS" required>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Type</label>
                        <select name="type" x-model="type" class="input">
                            <option value="whmcs">WHMCS</option>
                            <option value="gmail">Gmail (OAuth)</option>
                            <option value="imap">IMAP</option>
                            <option value="metricscube">MetricsCube</option>
                            <option value="discord">Discord</option>
                            <option value="slack">Slack</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">System slug</label>
                        <input type="text" name="system_slug" id="system_slug"
                               x-model="systemSlug"
                               @input="slugEdited = true"
                               value="{{ old('system_slug', $connection?->system_slug) }}"
                               class="input font-mono" placeholder="e.g. salesos" required
                               pattern="[a-z][a-z0-9_-]*">
                        <p class="text-gray-600 text-xs mt-1">Lowercase letters, numbers, hyphens</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $connection?->is_active ?? true) ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-700 rounded-full peer peer-checked:bg-green-600
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                    after:bg-white after:rounded-full after:h-4 after:w-4
                                    after:transition-all peer-checked:after:translate-x-4"></div>
                    </label>
                    <span class="text-sm text-gray-300">Active (scheduler will run this connection)</span>
                </div>
            </div>
        </div>

        {{-- ================================================================
             WHMCS SETTINGS
             ================================================================ --}}
        <div x-show="type === 'whmcs'" x-cloak class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">WHMCS Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Base URL <span class="text-red-500">*</span></label>
                    <input type="url" name="settings[base_url]"
                           value="{{ old('settings.base_url', $settings['base_url'] ?? '') }}"
                           class="input" placeholder="https://your-whmcs.example.com">
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">API Token <span class="text-red-500">*</span></label>
                    <input type="password" name="settings[token]"
                           class="input font-mono"
                           placeholder="{{ $isEdit && !empty($settings['token']) ? '••••••  (leave blank to keep current)' : 'Bearer token' }}">
                    @if ($isEdit && !empty($settings['token']))
                        <p class="text-gray-600 text-xs mt-1">Token is saved. Enter a new one to replace it.</p>
                    @endif
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-2">Entities to import</label>
                    @foreach (['clients', 'contacts', 'services', 'tickets'] as $entity)
                        <label class="flex items-center gap-2 text-sm text-gray-300 mb-1.5 cursor-pointer">
                            <input type="checkbox" name="settings[entities][]" value="{{ $entity }}"
                                   {{ in_array($entity, old('settings.entities', $settings['entities'] ?? ['clients','contacts','services','tickets'])) ? 'checked' : '' }}
                                   class="rounded border-gray-600 bg-gray-800">
                            {{ ucfirst($entity) }}
                        </label>
                    @endforeach
                </div>

            </div>
        </div>

        {{-- ================================================================
             GMAIL SETTINGS
             ================================================================ --}}
        <div x-show="type === 'gmail'" x-cloak class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">Gmail Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Google OAuth Client ID <span class="text-red-500">*</span></label>
                        <input type="text" name="settings[client_id]"
                               value="{{ old('settings.client_id', $settings['client_id'] ?? '') }}"
                               class="input font-mono text-xs" placeholder="xxxxxxxxxx.apps.googleusercontent.com">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Google OAuth Client Secret <span class="text-red-500">*</span></label>
                        <input type="password" name="settings[client_secret]"
                               class="input font-mono"
                               placeholder="{{ $isEdit && !empty($settings['client_secret']) ? '••••••  (leave blank to keep current)' : 'Client secret' }}">
                        @if ($isEdit && !empty($settings['client_secret']))
                            <p class="text-gray-600 text-xs mt-1">Secret is saved. Enter a new one to replace it.</p>
                        @endif
                    </div>
                    {{-- Callback URL — read-only, derived from APP_URL --}}
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">OAuth Callback URL <span class="text-gray-600">(read-only)</span></label>
                        <div class="flex items-center gap-2">
                            <input type="text" readonly
                                   :value="systemSlug ? baseCallbackUrl + systemSlug : '— enter system slug above —'"
                                   class="input font-mono text-xs bg-gray-900 text-gray-400 cursor-default select-all flex-1">
                            <button type="button" x-show="systemSlug"
                                    @click="navigator.clipboard.writeText(baseCallbackUrl + systemSlug).then(() => { $el.textContent = 'Copied!'; setTimeout(() => $el.textContent = 'Copy', 1500) })"
                                    class="btn btn-secondary text-xs shrink-0">Copy</button>
                        </div>
                        <p class="text-gray-600 text-xs mt-1">Register this URL in Google Cloud Console → OAuth consent screen → Authorized redirect URIs.</p>
                    </div>
                </div>

                <hr class="border-gray-800">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Gmail account (subject email) <span class="text-red-500">*</span></label>
                    <input type="email" name="settings[subject_email]"
                           value="{{ old('settings.subject_email', $settings['subject_email'] ?? '') }}"
                           class="input" placeholder="inbox@example.com">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Gmail search query <span class="text-gray-600">(optional)</span></label>
                    <input type="text" name="settings[query]"
                           value="{{ old('settings.query', $settings['query'] ?? '') }}"
                           class="input" placeholder='in:inbox after:2024/01/01'>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Excluded labels <span class="text-gray-600">(one per line)</span></label>
                    <textarea name="settings[excluded_labels]" rows="3"
                              class="input font-mono text-xs"
                              placeholder="Trash&#10;Spam&#10;Sent">{{ old('settings.excluded_labels', implode("\n", $settings['excluded_labels'] ?? [])) }}</textarea>
                    <p class="text-gray-600 text-xs mt-1">Appends <span class="font-mono">-label:X</span> to the search query for each label.</p>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Page size</label>
                        <input type="number" name="settings[page_size]"
                               value="{{ old('settings.page_size', $settings['page_size'] ?? 100) }}"
                               class="input" min="1" max="500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Max pages <span class="text-gray-600">(0 = unlimited)</span></label>
                        <input type="number" name="settings[max_pages]"
                               value="{{ old('settings.max_pages', $settings['max_pages'] ?? 0) }}"
                               class="input" min="0">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Concurrent <span class="text-gray-600">(1 = sequential)</span></label>
                        <input type="number" name="settings[concurrent_requests]"
                               value="{{ old('settings.concurrent_requests', $settings['concurrent_requests'] ?? 10) }}"
                               class="input" min="1" max="100">
                    </div>
                </div>
                <p class="text-gray-600 text-xs -mt-2">
                    <span class="font-mono text-gray-500">1</span> = one request at a time (old method, no rate-limit risk).
                    <span class="font-mono text-gray-500">2–100</span> = messages per Batch API call (faster; max 100).
                </p>

                {{-- OAuth status (edit mode only) --}}
                @if ($isEdit)
                    <hr class="border-gray-800">
                    <div class="border border-gray-700 rounded-lg p-4 space-y-3" x-data="gmailOAuth()">
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Google OAuth Token</div>

                        {{-- Token present --}}
                        @if ($gmailToken ?? null)
                            <div x-show="!oauthDone" class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                                    <span class="text-green-400 text-sm font-medium">Token active</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    Authorized {{ \Carbon\Carbon::parse($gmailToken->created_at)->format('Y-m-d') }}
                                    &nbsp;·&nbsp; Refreshed {{ \Carbon\Carbon::parse($gmailToken->updated_at)->diffForHumans() }}
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="openPopup('{{ $connection->system_slug }}', document.querySelector('[name=\'settings[subject_email]\']')?.value ?? '')"
                                            :disabled="oauthLoading"
                                            class="btn btn-secondary text-xs disabled:opacity-40">
                                        <span x-text="oauthLoading ? 'Waiting for authorization…' : 'Re-authorize'"></span>
                                    </button>
                                    <button type="button"
                                            @click="copyAuthLink('{{ $connection->system_slug }}', document.querySelector('[name=\'settings[subject_email]\']')?.value ?? '')"
                                            class="btn btn-secondary text-xs"
                                            x-text="copyLinkText"></button>
                                </div>
                            </div>
                        {{-- No token --}}
                        @else
                            <div x-show="!oauthDone" class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                                    <span class="text-red-400 text-sm font-medium">No token — not authorized yet</span>
                                </div>
                                <p class="text-gray-600 text-xs">Save credentials above first, then authorize.</p>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="openPopup('{{ $connection->system_slug }}', document.querySelector('[name=\'settings[subject_email]\']')?.value ?? '')"
                                            :disabled="oauthLoading"
                                            class="btn btn-blue text-xs disabled:opacity-40">
                                        <span x-text="oauthLoading ? 'Waiting for authorization…' : '▶ Authorize with Google'"></span>
                                    </button>
                                    <button type="button"
                                            @click="copyAuthLink('{{ $connection->system_slug }}', document.querySelector('[name=\'settings[subject_email]\']')?.value ?? '')"
                                            class="btn btn-secondary text-xs"
                                            x-text="copyLinkText"></button>
                                </div>
                            </div>
                        @endif

                        {{-- Success feedback (after popup closes) --}}
                        <div x-show="oauthDone" x-cloak class="space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                                <span class="text-green-400 text-sm font-medium">Authorized successfully</span>
                            </div>
                            <p class="text-xs text-gray-500">Authorized as <span class="text-gray-300" x-text="oauthEmail"></span>. Reloading…</p>
                        </div>

                        {{-- Error feedback --}}
                        <div x-show="oauthError" x-cloak class="text-red-400 text-xs" x-text="oauthError"></div>
                    </div>
                @else
                    <div class="text-gray-600 text-xs border border-gray-700 rounded-lg p-3">
                        Save this connection first, then open Edit to authorize with Google OAuth.
                    </div>
                @endif

            </div>
        </div>

        {{-- ================================================================
             IMAP SETTINGS
             ================================================================ --}}
        <div x-show="type === 'imap'" x-cloak class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">IMAP Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div class="grid grid-cols-3 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs text-gray-400 mb-1">Host <span class="text-red-500">*</span></label>
                        <input type="text" name="settings[host]"
                               value="{{ old('settings.host', $settings['host'] ?? '') }}"
                               class="input" placeholder="mail.example.com">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Port <span class="text-red-500">*</span></label>
                        <input type="number" name="settings[port]"
                               value="{{ old('settings.port', $settings['port'] ?? 993) }}"
                               class="input" min="1" max="65535">
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Encryption</label>
                    <select name="settings[encryption]" class="input">
                        @foreach (['ssl' => 'SSL (IMAPS, port 993)', 'tls' => 'STARTTLS (port 143)', 'none' => 'None (dev only)'] as $val => $label)
                            <option value="{{ $val }}" {{ old('settings.encryption', $settings['encryption'] ?? 'ssl') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="settings[username]"
                           value="{{ old('settings.username', $settings['username'] ?? '') }}"
                           class="input" placeholder="inbox@example.com" autocomplete="off">
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="settings[password]"
                           class="input"
                           placeholder="{{ $isEdit && !empty($settings['password']) ? '••••••  (leave blank to keep current)' : 'Password or app password' }}"
                           autocomplete="new-password">
                    @if ($isEdit && !empty($settings['password']))
                        <p class="text-gray-600 text-xs mt-1">Password is saved. Enter a new one to replace it.</p>
                    @endif
                </div>

                <hr class="border-gray-800">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Excluded mailboxes <span class="text-gray-600">(one per line)</span></label>
                    <textarea name="settings[excluded_mailboxes]" rows="4"
                              class="input font-mono text-xs"
                              placeholder="Trash&#10;Spam&#10;Junk&#10;Drafts">{{ old('settings.excluded_mailboxes', implode("\n", $settings['excluded_mailboxes'] ?? [])) }}</textarea>
                    <p class="text-gray-600 text-xs mt-1">All other mailboxes will be scanned. Leave empty to scan everything.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Batch size</label>
                        <input type="number" name="settings[batch_size]"
                               value="{{ old('settings.batch_size', $settings['batch_size'] ?? 100) }}"
                               class="input" min="1">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Max batches <span class="text-gray-600">(0 = unlimited)</span></label>
                        <input type="number" name="settings[max_batches]"
                               value="{{ old('settings.max_batches', $settings['max_batches'] ?? 0) }}"
                               class="input" min="0">
                    </div>
                </div>

            </div>
        </div>

        {{-- ================================================================
             DISCORD SETTINGS
             ================================================================ --}}
        <template x-if="type === 'discord'"><div class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">Discord Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Bot Token <span class="text-red-500">*</span></label>
                    <input type="password" name="settings[bot_token]"
                           class="input font-mono"
                           placeholder="{{ $isEdit && !empty($settings['bot_token']) ? '••••••  (leave blank to keep current)' : 'Bot token from Discord Developer Portal' }}"
                           autocomplete="new-password">
                    @if ($isEdit && !empty($settings['bot_token']))
                        <p class="text-gray-600 text-xs mt-1">Token is saved. Enter a new one to replace it.</p>
                    @endif
                </div>

                <hr class="border-gray-800">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Guild allowlist <span class="text-gray-600">(one guild ID per line)</span></label>
                    <textarea name="settings[guild_allowlist]" rows="3"
                              class="input font-mono text-xs"
                              placeholder="123456789012345678&#10;987654321098765432">{{ old('settings.guild_allowlist', implode("\n", $settings['guild_allowlist'] ?? [])) }}</textarea>
                    <p class="text-gray-600 text-xs mt-1">Leave empty to import from all guilds the bot can see.</p>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Channel allowlist <span class="text-gray-600">(one channel ID per line)</span></label>
                    <textarea name="settings[channel_allowlist]" rows="3"
                              class="input font-mono text-xs"
                              placeholder="123456789012345678">{{ old('settings.channel_allowlist', implode("\n", $settings['channel_allowlist'] ?? [])) }}</textarea>
                    <p class="text-gray-600 text-xs mt-1">Leave empty to import from all text channels the bot can read.</p>
                </div>

                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="settings[include_threads]" value="0">
                        <input type="checkbox" name="settings[include_threads]" value="1"
                               {{ old('settings.include_threads', $settings['include_threads'] ?? true) ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-700 rounded-full peer peer-checked:bg-green-600
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                    after:bg-white after:rounded-full after:h-4 after:w-4
                                    after:transition-all peer-checked:after:translate-x-4"></div>
                    </label>
                    <span class="text-sm text-gray-300">Import threads (public &amp; private thread channels)</span>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Max messages per run <span class="text-gray-600">(0 = unlimited)</span></label>
                    <input type="number" name="settings[max_messages_per_run]"
                           value="{{ old('settings.max_messages_per_run', $settings['max_messages_per_run'] ?? 0) }}"
                           class="input" min="0">
                    <p class="text-gray-600 text-xs mt-1">Recommended for the first full import of large servers (e.g. 50000).</p>
                </div>

            </div>
        </div></template>

        {{-- ================================================================
             SLACK SETTINGS
             ================================================================ --}}
        <template x-if="type === 'slack'"><div class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">Slack Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Bot Token <span class="text-red-500">*</span></label>
                    <input type="password" name="settings[bot_token]"
                           class="input font-mono"
                           placeholder="{{ $isEdit && !empty($settings['bot_token']) ? '••••••  (leave blank to keep current)' : 'xoxb-...' }}"
                           autocomplete="new-password">
                    @if ($isEdit && !empty($settings['bot_token']))
                        <p class="text-gray-600 text-xs mt-1">Token is saved. Enter a new one to replace it.</p>
                    @endif
                    <p class="text-gray-600 text-xs mt-1">Bot User OAuth Token from your Slack App settings. Starts with <span class="font-mono">xoxb-</span>.</p>
                </div>

                <hr class="border-gray-800">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Channel allowlist <span class="text-gray-600">(one channel ID per line)</span></label>
                    <textarea name="settings[channel_allowlist]" rows="4"
                              class="input font-mono text-xs"
                              placeholder="C01234ABCDE&#10;C09876ZYXWV">{{ old('settings.channel_allowlist', implode("\n", $settings['channel_allowlist'] ?? [])) }}</textarea>
                    <p class="text-gray-600 text-xs mt-1">Leave empty to import from all channels the bot is a member of. For private channels, invite the bot first: <span class="font-mono">/invite @botname</span></p>
                </div>

                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="settings[include_threads]" value="0">
                        <input type="checkbox" name="settings[include_threads]" value="1"
                               {{ old('settings.include_threads', $settings['include_threads'] ?? true) ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-700 rounded-full peer peer-checked:bg-green-600
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                    after:bg-white after:rounded-full after:h-4 after:w-4
                                    after:transition-all peer-checked:after:translate-x-4"></div>
                    </label>
                    <span class="text-sm text-gray-300">Import thread replies</span>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Max messages per run <span class="text-gray-600">(0 = unlimited)</span></label>
                    <input type="number" name="settings[max_messages_per_run]"
                           value="{{ old('settings.max_messages_per_run', $settings['max_messages_per_run'] ?? 0) }}"
                           class="input" min="0">
                </div>

            </div>
        </div></template>

        {{-- ================================================================
             METRICSCUBE SETTINGS
             ================================================================ --}}
        <div x-show="type === 'metricscube'" x-cloak class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">MetricsCube Settings</div>
            <div class="px-5 py-4 space-y-4">

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Linked WHMCS connection <span class="text-red-500">*</span></label>
                    <select name="settings[whmcs_connection_id]" class="input">
                        <option value="0">— select WHMCS connection —</option>
                        @foreach ($whmcsConnections ?? [] as $wc)
                            <option value="{{ $wc->id }}"
                                {{ (int) old('settings.whmcs_connection_id', $settings['whmcs_connection_id'] ?? 0) === $wc->id ? 'selected' : '' }}>
                                {{ $wc->name }} ({{ $wc->system_slug }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-gray-600 text-xs mt-1">MetricsCube credentials will be injected when this WHMCS connection runs.</p>
                </div>

                <div class="text-gray-600 text-xs border border-gray-700 rounded-lg px-3 py-2">
                    API endpoint: <span class="text-gray-500 font-mono">https://api.metricscube.io/api/connector/whmcs</span>
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">App Key <span class="text-red-500">*</span></label>
                    <input type="text" name="settings[app_key]"
                           value="{{ old('settings.app_key', $settings['app_key'] ?? '') }}"
                           class="input font-mono" placeholder="your-app-key">
                </div>

                <div>
                    <label class="block text-xs text-gray-400 mb-1">Connector Key <span class="text-red-500">*</span></label>
                    <input type="password" name="settings[connector_key]"
                           class="input font-mono"
                           placeholder="{{ $isEdit && !empty($settings['connector_key']) ? '••••••  (leave blank to keep current)' : 'Connector key' }}">
                    @if ($isEdit && !empty($settings['connector_key']))
                        <p class="text-gray-600 text-xs mt-1">Connector key is saved. Enter a new one to replace it.</p>
                    @endif
                </div>

            </div>
        </div>

        {{-- ================================================================
             PARTIAL SYNC SCHEDULE (hidden for MetricsCube)
             ================================================================ --}}
        <div x-show="type !== 'metricscube'" class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">
                Partial sync schedule
                <span class="ml-2 text-xs font-normal text-gray-500">— only new messages since last sync</span>
            </div>
            <div class="px-5 py-4 space-y-4">
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="schedule_enabled" value="0">
                        <input type="checkbox" name="schedule_enabled" value="1"
                               x-model="schedEnabled"
                               {{ old('schedule_enabled', $connection?->schedule_enabled) ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-700 rounded-full peer peer-checked:bg-green-600
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                    after:bg-white after:rounded-full after:h-4 after:w-4
                                    after:transition-all peer-checked:after:translate-x-4"></div>
                    </label>
                    <span class="text-sm text-gray-300">Enable partial sync schedule</span>
                </div>

                <div x-show="schedEnabled" class="space-y-3">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1.5">Frequency preset</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([
                                '*/5 * * * *'  => 'Every 5 min',
                                '*/15 * * * *' => 'Every 15 min',
                                '*/30 * * * *' => 'Every 30 min',
                                '0 * * * *'    => 'Hourly',
                                '0 */2 * * *'  => 'Every 2h',
                                '0 */6 * * *'  => 'Every 6h',
                            ] as $cron => $label)
                                <button type="button"
                                        @click="setCron('{{ $cron }}')"
                                        :class="cronExpr === '{{ $cron }}' ? 'btn-primary' : 'btn-secondary'"
                                        class="btn text-xs">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Cron expression</label>
                        <input type="text" name="schedule_cron" x-model="cronExpr"
                               class="input font-mono" placeholder="*/5 * * * *">
                        <p class="text-gray-600 text-xs mt-1">Standard 5-field cron (min hour day month weekday)</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================
             FULL SYNC SCHEDULE (hidden for MetricsCube)
             ================================================================ --}}
        <div x-show="type !== 'metricscube'" class="card rounded-xl overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-semibold text-white">
                Full sync schedule
                <span class="ml-2 text-xs font-normal text-gray-500">— scans entire mailbox</span>
            </div>
            <div class="px-5 py-4 space-y-4">
                <div class="flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="schedule_full_enabled" value="0">
                        <input type="checkbox" name="schedule_full_enabled" value="1"
                               x-model="schedFullEnabled"
                               {{ old('schedule_full_enabled', $connection?->schedule_full_enabled) ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-700 rounded-full peer peer-checked:bg-green-600
                                    after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                    after:bg-white after:rounded-full after:h-4 after:w-4
                                    after:transition-all peer-checked:after:translate-x-4"></div>
                    </label>
                    <span class="text-sm text-gray-300">Enable full sync schedule</span>
                </div>

                <div x-show="schedFullEnabled" class="space-y-3">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1.5">Frequency preset</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([
                                '0 */6 * * *' => 'Every 6h',
                                '0 0 * * *'   => 'Daily midnight',
                                '0 2 * * *'   => 'Daily 2am',
                                '0 0 * * 0'   => 'Weekly Sunday',
                            ] as $cron => $label)
                                <button type="button"
                                        @click="setFullCron('{{ $cron }}')"
                                        :class="cronFullExpr === '{{ $cron }}' ? 'btn-primary' : 'btn-secondary'"
                                        class="btn text-xs">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Cron expression</label>
                        <input type="text" name="schedule_full_cron" x-model="cronFullExpr"
                               class="input font-mono" placeholder="0 0 * * *">
                        <p class="text-gray-600 text-xs mt-1">Standard 5-field cron (min hour day month weekday)</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================
             TEST CONNECTION
             ================================================================ --}}
        <div class="card rounded-xl overflow-hidden mb-4" x-data="testConn()">
            <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
                <span class="text-sm font-semibold text-white">Test Connection</span>
                <button type="button" @click="test()" :disabled="testing" class="btn btn-secondary text-xs disabled:opacity-40">
                    <template x-if="testing">
                        <svg class="w-3 h-3 spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </template>
                    <span x-text="testing ? 'Testing…' : 'Test now'"></span>
                </button>
            </div>
            <div class="px-5 py-3 text-sm">
                <template x-if="!result">
                    <span class="text-gray-600 text-xs">Click "Test now" to verify credentials and connectivity.</span>
                </template>
                <template x-if="result && result.ok">
                    <div class="flex items-start gap-2 text-green-400 text-sm">
                        <span class="leading-none mt-0.5">✓</span>
                        <span x-text="result.message"></span>
                    </div>
                </template>
                <template x-if="result && !result.ok">
                    <div class="flex items-start gap-2 text-red-400 text-sm">
                        <span class="leading-none mt-0.5">✗</span>
                        <span x-text="result.message"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Form actions --}}
        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">
                {{ $isEdit ? 'Save changes' : 'Create connection' }}
            </button>
            <a href="{{ route('admin.connections.index') }}" class="btn btn-secondary">Cancel</a>
        </div>

    </form>
</div>

<script>
function gmailOAuth() {
    return {
        oauthLoading: false,
        oauthDone:    false,
        oauthEmail:   '',
        oauthError:   '',
        copyLinkText: 'Copy link',

        copyAuthLink(system, email) {
            const url = window.location.origin + `/google/auth/${system}` + (email ? `?email=${encodeURIComponent(email)}` : '');
            navigator.clipboard.writeText(url).then(() => {
                this.copyLinkText = 'Copied!';
                setTimeout(() => this.copyLinkText = 'Copy link', 2000);
            });
        },

        openPopup(system, email) {
            this.oauthLoading = true;
            this.oauthError   = '';

            const w    = 600, h = 700;
            const left = Math.round((screen.width  - w) / 2);
            const top  = Math.round((screen.height - h) / 2);
            const url  = `/google/auth/${system}` + (email ? `?email=${encodeURIComponent(email)}` : '');

            const popup = window.open(url, 'gmail_oauth',
                `width=${w},height=${h},left=${left},top=${top},toolbar=no,menubar=no,location=no,status=no`);

            if (!popup) {
                this.oauthLoading = false;
                this.oauthError   = 'Popup was blocked. Please allow popups for this page and try again.';
                return;
            }

            const handler = (event) => {
                if (event.origin !== window.location.origin) return;

                if (event.data?.type === 'oauth_success') {
                    window.removeEventListener('message', handler);
                    this.oauthLoading = false;
                    this.oauthDone    = true;
                    this.oauthEmail   = event.data.email ?? '';
                    setTimeout(() => window.location.reload(), 1200);
                }

                if (event.data?.type === 'oauth_error') {
                    window.removeEventListener('message', handler);
                    this.oauthLoading = false;
                    this.oauthError   = event.data.message ?? 'Authorization failed.';
                }
            };

            window.addEventListener('message', handler);

            // If user closes popup manually without completing OAuth
            const pollClosed = setInterval(() => {
                if (popup.closed) {
                    clearInterval(pollClosed);
                    window.removeEventListener('message', handler);
                    if (this.oauthLoading) {
                        this.oauthLoading = false;
                    }
                }
            }, 500);
        }
    };
}

function connForm(initialType, baseCallbackUrl, initialSlug, isEdit) {
    return {
        type:            initialType,
        baseCallbackUrl: baseCallbackUrl,
        systemSlug:      initialSlug,
        slugEdited:      isEdit,
        schedEnabled:    {{ old('schedule_enabled', $connection?->schedule_enabled ?? false) ? 'true' : 'false' }},
        cronExpr:        '{{ old('schedule_cron', $connection?->schedule_cron ?? '*/5 * * * *') }}',
        schedFullEnabled: {{ old('schedule_full_enabled', $connection?->schedule_full_enabled ?? false) ? 'true' : 'false' }},
        cronFullExpr:     '{{ old('schedule_full_cron', $connection?->schedule_full_cron ?? '0 0 * * *') }}',

        onNameInput(name) {
            if (this.slugEdited) return;
            this.systemSlug = name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^[^a-z]+/, '')
                .replace(/-+$/, '');
        },

        setCron(expr)     { this.cronExpr     = expr; },
        setFullCron(expr) { this.cronFullExpr = expr; },
    };
}

function testConn() {
    return {
        testing: false,
        result:  null,

        async test() {
            this.testing = true;
            this.result  = null;

            const form = document.getElementById('connection-form');
            const v    = (name) => form.querySelector(`[name="${name}"]`)?.value ?? '';

            const type = v('type');
            console.log('[test] type=', type, 'slug=', v('system_slug'), 'base_url=', v('settings[base_url]'));
            const data = {
                type:          type,
                system_slug:   v('system_slug'),
                connection_id: {{ $connection?->id ?? 'null' }},
                settings: {
                    // WHMCS
                    base_url: v('settings[base_url]'),
                    token:    v('settings[token]'),
                    // Gmail
                    client_id:     v('settings[client_id]'),
                    client_secret: v('settings[client_secret]'),
                    subject_email: v('settings[subject_email]'),
                    // IMAP
                    host:       v('settings[host]'),
                    port:       v('settings[port]'),
                    encryption: v('settings[encryption]'),
                    username:   v('settings[username]'),
                    password:   v('settings[password]'),
                    mailbox:    v('settings[mailbox]'),
                    // MetricsCube
                    app_key:       v('settings[app_key]'),
                    connector_key: v('settings[connector_key]'),
                    // Discord / Slack
                    bot_token: v('settings[bot_token]'),
                },
            };

            try {
                const r = await fetch('/admin/connections/test', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify(data),
                });
                this.result = await r.json();
            } catch (e) {
                this.result = { ok: false, message: e.message };
            } finally {
                this.testing = false;
            }
        }
    };
}
</script>

@endsection
