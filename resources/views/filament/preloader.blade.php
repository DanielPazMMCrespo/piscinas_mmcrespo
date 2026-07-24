<div id="global-loader" style="position: fixed; inset: 0; background: #021a2f; z-index: 999999; display: flex; flex-direction: column; justify-content: center; align-items: center; opacity: 1; transition: opacity 200ms ease-in-out; pointer-events: auto;">
    <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" id="global-loader-logo" alt="Piscinas MMCrespo" style="height: 100px; width: auto; object-fit: contain; opacity: 0; transform: scale(0.95) translateY(10px); transition: opacity 100ms ease-out, transform 100ms ease-out;">
</div>

<script>
(function() {
    let fallbackTimeout = null;

    function getLoader() { return document.getElementById('global-loader'); }
    function getLogo() { return document.getElementById('global-loader-logo'); }

    function showPreloader() {
        const loader = getLoader();
        const logo = getLogo();
        if (!loader || !logo) return;

        loader.style.display = 'flex';
        loader.style.pointerEvents = 'auto';
        
        // Força reflow
        void loader.offsetWidth;
        
        loader.style.opacity = '1';
        requestAnimationFrame(() => {
            logo.style.opacity = '1';
            logo.style.transform = 'scale(1) translateY(0)';
        });

        // Temporizador de segurança (fail-safe)
        clearTimeout(fallbackTimeout);
        fallbackTimeout = setTimeout(() => {
            hidePreloader();
        }, 3500);
    }

    function hidePreloader() {
        const loader = getLoader();
        const logo = getLogo();
        if (!loader || !logo) return;

        clearTimeout(fallbackTimeout);

        logo.style.opacity = '0';
        logo.style.transform = 'scale(0.95) translateY(-5px)';
        loader.style.opacity = '0';

        setTimeout(() => {
            loader.style.display = 'none';
            loader.style.pointerEvents = 'none';
        }, 200);
    }

    // 1. Revelação no Carregamento Inicial (Mantido a 500ms)
    document.addEventListener('DOMContentLoaded', () => {
        const logo = getLogo();
        if (logo) {
            requestAnimationFrame(() => {
                logo.style.opacity = '1';
                logo.style.transform = 'scale(1) translateY(0)';
            });
        }
        setTimeout(hidePreloader, 500);
    });

    // 2. Transições SPA do Livewire (Aparecer: 100ms | Destaque: 200ms | Fade-out: 200ms)
    document.addEventListener('livewire:navigating', () => {
        showPreloader();
    });

    document.addEventListener('livewire:navigated', () => {
        setTimeout(hidePreloader, 200);
    });

    // 3. Disparo imediato nos cliques de links internos para transição fluida
    document.addEventListener('click', (e) => {
        const anchor = e.target.closest('a[href]');
        if (!anchor) return;
        
        const href = anchor.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || anchor.target === '_blank') return;

        if (href.startsWith('/admin') || href.startsWith(window.location.origin + '/admin')) {
            const currentPath = window.location.pathname + window.location.search;
            if (href !== currentPath && href !== window.location.href) {
                showPreloader();
            }
        }
    }, true);
})();
</script>
