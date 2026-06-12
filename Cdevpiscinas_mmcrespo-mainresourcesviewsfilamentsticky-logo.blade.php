{{-- Logotipo sticky sempre visível no canto inferior esquerdo --}}
<div class="mmcrespo-sticky-logo">
    <img
        src="{{ asset('images/logo-mmcrespo.png') }}"
        alt="Piscinas MMCrespo"
        class="mmcrespo-sticky-logo-light h-full w-auto"
    />
    <img
        src="{{ asset('images/logo_mmcrespo_branco.png') }}"
        alt="Piscinas MMCrespo"
        class="mmcrespo-sticky-logo-dark h-full w-auto"
        style="display: none;"
    />
</div>

<style>
    .mmcrespo-sticky-logo {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        width: 4rem;
        height: 4rem;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 0.5rem;
        padding: 0.5rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 40;
        transition: all 0.3s ease;
    }

    .mmcrespo-sticky-logo:hover {
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        transform: scale(1.05);
    }

    .mmcrespo-sticky-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .dark .mmcrespo-sticky-logo {
        background: rgba(30, 41, 59, 0.95);
    }

    .dark .mmcrespo-sticky-logo-light {
        display: none !important;
    }

    .dark .mmcrespo-sticky-logo-dark {
        display: block !important;
    }

    /* Esconder em telemóvel pequeno */
    @media (max-width: 640px) {
        .mmcrespo-sticky-logo {
            bottom: 1rem;
            right: 1rem;
            width: 3rem;
            height: 3rem;
        }
    }
</style>
