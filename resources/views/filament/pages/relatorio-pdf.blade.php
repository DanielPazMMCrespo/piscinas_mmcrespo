<x-filament-panels::page>
    {{-- Formulário de parâmetros + exportação. O download é devolvido pela action Livewire "exportar". --}}
    <form wire:submit="exportar" class="space-y-6">
        {{ $this->form }}

        <x-filament::button
            type="submit"
            icon="heroicon-o-arrow-down-tray"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="exportar">Exportar PDF</span>
            <span wire:loading wire:target="exportar">A gerar PDF…</span>
        </x-filament::button>
    </form>

    <x-filament::section icon="heroicon-o-information-circle" collapsible collapsed>
        <x-slot name="heading">Sobre este relatório</x-slot>

        <div class="text-sm space-y-2">
            <p>
                O documento gerado corresponde ao <strong>Livro de Registo Sanitário</strong> exigido pela
                Circular Normativa 14/DA (DGS, 2009), com validação automática de conformidade de pH,
                cloro livre, cloro combinado e temperatura contra os limites legais e os limites próprios
                de cada piscina.
            </p>
            <p>
                Registos corrigidos pelos técnicos são excluídos (mantém-se apenas a versão válida,
                assinalada com "(correção)"), em linha com o modelo append-only da aplicação.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
