<div id="global-loader" style="position: fixed; inset: 0; background: #021a2f; z-index: 999999; display: flex; flex-direction: column; justify-content: center; align-items: center; opacity: 1; transition: opacity 0.4s ease-in-out; pointer-events: auto;">
    <div style="position: relative; display: flex; flex-direction: column; align-items: center;">
        <!-- Glowing Ambient Backdrop Aura -->
        <div style="position: absolute; width: 220px; height: 220px; background: radial-gradient(circle, rgba(56, 189, 248, 0.25) 0%, rgba(2, 26, 47, 0) 70%); border-radius: 50%; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;"></div>
        
        <!-- Logo MMCrespo Branco com sombra e escala cinemática -->
        <img src="{{ asset('images/logo_mmcrespo_branco.png') }}" id="global-loader-logo" alt="Piscinas MMCrespo" style="height: 100px; width: auto; object-fit: contain; opacity: 0; transform: scale(0.9) translateY(15px); transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1); filter: drop-shadow(0 0 25px rgba(56, 189, 248, 0.4));">
    </div>
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
        
        // Força reflow para garantir a transição de opacidade
        void loader.offsetWidth;
        
        loader.style.opacity = '1';
        logo.style.opacity = '1';
        logo.style.transform = 'scale(1) translateY(0)';

        // Temporizador de segurança (fail-safe) para nunca ficar preso
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
        logo.style.transform = 'scale(0.95) translateY(-10px)';

        setTimeout(() => {
            loader.style.opacity = '0';
            setTimeout(() => {
                loader.style.display = 'none';
                loader.style.pointerEvents = 'none';
            }, 400);
        }, 180);
    }

    // 1. Revelação no Carregamento Inicial
    document.addEventListener('DOMContentLoaded', () => {
        const logo = getLogo();
        if (logo) {
            logo.style.opacity = '1';
            logo.style.transform = 'scale(1) translateY(0)';
        }
        setTimeout(hidePreloader, 500);
    });

    // 2. Transições SPA do Livewire (Troca de páginas)
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
