{{-- Tags PWA injetadas no <head> do painel Filament (render hook HEAD_END). --}}
<link rel="manifest" href="{{ url('/manifest.json') }}">
<meta name="theme-color" content="#2b9cd8">
<meta name="mobile-web-app-capable" content="yes">

{{-- iOS / Safari: instalável no ecrã principal, abre em ecrã inteiro. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Piscinas">
<link rel="apple-touch-icon" href="{{ asset('images/icon-192.png') }}">

<script>
    // Regista o service worker para tornar a app instalável.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{{ url('/sw.js') }}', { scope: '/' })
                .catch(function (e) { console.warn('SW registo falhou:', e); });
        });
    }
</script>
