<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'MITHQAL Merchant Portal') }}</title>

    {{-- Synchronous boot payload. Inlined here so the Vue app has the
         authoritative who-is-signed-in answer on its very first frame
         instead of flashing the guest shell while it waits for an
         /auth/user XHR to come back. The auth store reads it in
         applyInitialAuth() right after createApp(). --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.__INITIAL_AUTH__ = @json($initialAuth ?? ['authenticated' => false, 'user' => null]);
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-slate-50 text-slate-950 antialiased">
    <div id="app"></div>
</body>
</html>
