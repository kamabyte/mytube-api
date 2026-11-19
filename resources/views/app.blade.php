<!DOCTYPE html>
<html lang="ru" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="dark light">
        <meta name="theme-color" content="#0a0a0c">
        <meta name="application-name" content="MyTube">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="MyTube">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        {{-- Публичный ключ Reverb — в рантайме: образ собирается один на все окружения. --}}
        @if (config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key'))
            <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        @endif

        {{-- Тема до первой отрисовки, чтобы не мигало: сохранённая или системная. --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('mytube:appearance') || 'system';
                    var dark = stored === 'dark' || (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                    document.querySelector('meta[name="theme-color"]').content = dark ? '#0a0a0c' : '#f6f6f7';
                } catch (e) {}
            })();
        </script>

        <style>
            html { background-color: #f6f6f7; }
            html.dark { background-color: #0a0a0c; }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#e0241b">
        <link rel="manifest" href="/site.webmanifest">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'MyTube') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
