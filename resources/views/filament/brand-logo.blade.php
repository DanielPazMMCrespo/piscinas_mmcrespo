{{-- Logo adaptativo ao tema:
     - Fundo claro  → logo com as cores normais.
     - Fundo escuro → silhueta branca (filtro brightness(0) invert(1) sobre o PNG transparente).
     Usa o seletor .dark do Filament para não depender da compilação do Tailwind do projeto. --}}
<img
    src="{{ asset('images/logo-mmcrespo.png') }}"
    alt="Piscinas MMCrespo"
    class="mmcrespo-brand-logo h-full w-auto"
/>
<style>
    .dark .mmcrespo-brand-logo {
        filter: brightness(0) invert(1);
    }
</style>
