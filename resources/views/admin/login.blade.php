<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mielonka — Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight">Mielonka</h1>
        <p class="text-gray-500 text-sm mt-1">Admin Panel</p>
    </div>

    <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
        @if (session('error'))
            <div class="bg-red-950 border border-red-800 text-red-300 text-sm rounded-lg px-4 py-3 mb-5">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-400 text-sm mb-1.5" for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autofocus
                    class="w-full bg-gray-800 border border-gray-700 text-white rounded-lg px-3 py-2 text-sm
                           focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    placeholder="Enter admin password"
                >
            </div>
            <button
                type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-sm rounded-lg px-4 py-2.5 transition"
            >
                Sign in
            </button>
        </form>
    </div>

    <p class="text-center text-gray-600 text-xs mt-6">
        Set <code class="text-gray-500">ADMIN_PASSWORD</code> in <code class="text-gray-500">.env</code>
    </p>
</div>

</body>
</html>
