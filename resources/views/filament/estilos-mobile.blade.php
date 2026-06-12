<style>
    /*
     | Wizard do registo diário no telemóvel.
     | Por defeito o Filament empilha os 10 indicadores de passo na vertical (<768px),
     | empurrando os campos para muito baixo. Aqui passamos a tira de passos a
     | horizontal e compacta (scroll lateral só na tira), para os campos do passo
     | ativo ficarem logo visíveis, sem ter de fazer scroll vertical.
     */
    @media (max-width: 767px) {
        .fi-fo-wizard-header {
            grid-auto-flow: column !important;
            grid-auto-columns: max-content !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .fi-fo-wizard-header-step-description {
            display: none !important;
        }
        .fi-fo-wizard-header-step-button {
            padding-top: 0.6rem !important;
            padding-bottom: 0.6rem !important;
            white-space: nowrap;
        }
        .fi-fo-wizard-header-step-label {
            white-space: nowrap;
        }
        /* O passo ativo já mostra o conteúdo logo abaixo da tira. */
        .fi-fo-wizard-step.fi-active {
            padding-top: 1rem !important;
        }
    }
</style>
