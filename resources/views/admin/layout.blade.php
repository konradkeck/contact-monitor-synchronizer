<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — contact-monitor-synchronizer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body { background: #0d1117; color: #c9d1d9; }
        .card { background: #161b22; border: 1px solid #30363d; }
        .card-inner { border-top: 1px solid #21262d; }
        .tbl-row { border-top: 1px solid #21262d; }
        .tbl-row:hover { background: #1c2128; }
        .input {
            background: #0d1117; border: 1px solid #30363d; color: #c9d1d9;
            border-radius: 6px; padding: 0.4rem 0.65rem; font-size: 0.875rem; width: 100%;
        }
        .input:focus { outline: none; border-color: #58a6ff; box-shadow: 0 0 0 2px rgba(88,166,255,.15); }
        .btn { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 6px;
               padding: 0.4rem 0.85rem; font-size: 0.8rem; font-weight: 500; transition: .15s; cursor: pointer; border: 1px solid transparent; }
        .btn-primary   { background: #238636; border-color: #2ea043; color: #fff; }
        .btn-primary:hover { background: #2ea043; }
        .btn-secondary { background: #21262d; border-color: #30363d; color: #c9d1d9; }
        .btn-secondary:hover { background: #30363d; }
        .btn-danger    { background: transparent; border-color: #f8514921; color: #f85149; }
        .btn-danger:hover { background: #f8514918; }
        .btn-blue      { background: #1f6feb; border-color: #388bfd; color: #fff; }
        .btn-blue:hover { background: #388bfd; }
        .badge { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.7rem;
                 font-weight: 600; padding: 0.15rem 0.55rem; border-radius: 999px; }
        .badge-pending  { background: #1c2128; color: #8b949e; border: 1px solid #30363d; }
        .badge-running  { background: rgba(31,111,235,.15); color: #58a6ff; border: 1px solid rgba(31,111,235,.3); }
        .badge-completed{ background: rgba(35,134,54,.15); color: #3fb950; border: 1px solid rgba(35,134,54,.3); }
        .badge-failed   { background: rgba(248,81,73,.15); color: #f85149; border: 1px solid rgba(248,81,73,.3); }
        .badge-whmcs        { background: rgba(88,166,255,.1); color: #79c0ff; border: 1px solid rgba(88,166,255,.2); }
        .badge-gmail        { background: rgba(248,81,73,.1); color: #ff7b72; border: 1px solid rgba(248,81,73,.2); }
        .badge-imap         { background: rgba(63,185,80,.1); color: #3fb950; border: 1px solid rgba(63,185,80,.2); }
        .badge-metricscube  { background: rgba(210,153,34,.1); color: #e3b341; border: 1px solid rgba(210,153,34,.2); }
        .badge-discord      { background: rgba(88,101,242,.18); color: #7289da; border: 1px solid rgba(88,101,242,.4); }
        .badge-slack        { background: rgba(74,21,75,.25); color: #e01e5a; border: 1px solid rgba(224,30,90,.4); }
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</head>
<body class="min-h-screen">

{{-- Header --}}
<header class="border-b border-gray-800 sticky top-0 z-20" style="background:#161b22">
    <div class="max-w-7xl mx-auto px-5 h-12 flex items-center gap-6">
        <span class="font-bold text-white text-sm tracking-wide">contact-monitor-synchronizer</span>

        <nav class="flex items-center gap-1">
            <a href="{{ route('admin.connections.index') }}"
               class="px-3 py-1 rounded text-xs font-medium transition
                      {{ request()->routeIs('admin.connections.*') ? 'bg-gray-700 text-white' : 'text-gray-400 hover:text-gray-200' }}">
                Connections
            </a>
            <a href="{{ route('admin.stats') }}"
               class="px-3 py-1 rounded text-xs font-medium transition
                      {{ request()->routeIs('admin.stats') ? 'bg-gray-700 text-white' : 'text-gray-400 hover:text-gray-200' }}">
                Data Stats
            </a>
        </nav>

        <div class="ml-auto flex items-center gap-4">
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="text-gray-600 hover:text-gray-400 text-xs transition">
                    Sign out
                </button>
            </form>
        </div>
    </div>
</header>

{{-- Flash messages --}}
@if (session('success'))
    <div class="max-w-7xl mx-auto px-5 pt-4">
        <div class="bg-green-950/60 border border-green-800/50 text-green-300 text-sm rounded-lg px-4 py-2.5">
            {{ session('success') }}
        </div>
    </div>
@endif

<main class="max-w-7xl mx-auto px-5 py-6">
    @yield('content')
</main>

</body>
</html>
