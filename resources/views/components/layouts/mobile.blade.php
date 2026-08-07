<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark h-[100dvh] overscroll-none">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    
    <!-- PWA Meta Tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#000000">
    <link rel="manifest" href="/manifest.json">
    
    <title>{{ $title ?? 'Gestão Piscinas' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Allow text selection on inputs and textareas despite body select-none */
        input, textarea {
            user-select: auto;
            -webkit-user-select: auto;
        }
    </style>
    
    <!-- PWA Service Worker Inline Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    console.log('SW registered: ', registration);
                }).catch(registrationError => {
                    console.log('SW registration failed: ', registrationError);
                });
            });
        }
    </script>
</head>
<body class="antialiased bg-black text-white h-[100dvh] overflow-hidden select-none [-webkit-tap-highlight-color:transparent]" style="padding-top: env(safe-area-inset-top); padding-left: env(safe-area-inset-left); padding-right: env(safe-area-inset-right);">
    
    <main class="relative flex flex-col h-full w-full max-w-md mx-auto overflow-y-auto pb-[calc(5rem+env(safe-area-inset-bottom))]">
        {{ $slot }}
    </main>

    <x-mobile-bottom-nav />

    @livewireScripts
</body>
</html>
