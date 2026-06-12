{{-- Logo adaptativo ao tema:
     - Fundo claro (light)  → logo com as cores normais.
     - Fundo escuro (dark)  → logo branco dedicado.
     Usa o seletor .dark do Filament para alternar entre imagens. --}}
<img
    src="{{ asset('images/logo-mmcrespo.png') }}"
    alt="Piscinas MMCrespo"
    class="mmcrespo-brand-logo h-full w-auto"
    style="display: block;"
/>
<img
    src="{{ asset('images/logo_mmcrespo_branco.png') }}"
    alt="Piscinas MMCrespo"
    class="mmcrespo-brand-logo-dark h-full w-auto"
    style="display: none;"
/>
<style>
    /* Pai do logo: manter sempre visível mesmo ao recolher a barra lateral */
    .fi-sidebar-header {
        position: sticky !important;
        top: 0 !important;
        z-index: 50 !important;
        background-color: inherit !important;
    }

    .dark .mmcrespo-brand-logo {
        display: none !important;
    }
    .dark .mmcrespo-brand-logo-dark {
        display: block !important;
    }

    /* Header logo no mobile — aproximar logótipo do botão de menu */
    #mmcrespo-header-logo {
        gap: 0.25rem !important;
        padding-right: 0 !important;
        margin-right: auto !important;
    }

    #mmcrespo-header-logo img {
        max-height: 2.5rem;
        width: auto;
        display: block;
    }

    /* Navbar responsiva em mobile */
    nav {
        padding: 0.5rem 0.75rem !important;
        gap: 0.5rem !important;
    }

    @media (max-width: 640px) {
        nav {
            padding: 0.5rem 0.5rem !important;
            gap: 0.25rem !important;
        }

        #mmcrespo-header-logo {
            gap: 0.15rem !important;
        }

        #mmcrespo-header-logo img {
            max-height: 2rem;
        }
    }

    /* Garantir que botões do navbar não estão apertados em mobile */
    nav button {
        padding: 0.5rem !important;
        min-width: 2.5rem !important;
    }

    @media (max-width: 640px) {
        nav button {
            padding: 0.4rem !important;
            min-width: 2rem !important;
        }
    }
</style>
