<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyRecord extends ViewRecord
{
    protected static string $resource = DailyRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ── Informação Geral ─────────────────────────────────────────────
                Section::make('Informação Geral')
                    ->icon('heroicon-o-identification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('piscina.name')
                            ->label('Piscina'),
                        TextEntry::make('utilizador.name')
                            ->label('Técnico / Nadador-Salvador'),
                        TextEntry::make('registado_em')
                            ->label('Data e Hora')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                // ── Bomba ────────────────────────────────────────────────────────
                Section::make('Bomba')
                    ->icon('heroicon-o-bolt')
                    ->columns(2)
                    ->visible(fn () => $this->record->bomba_ferrada !== null || filled($this->record->bomba_foto))
                    ->schema([
                        TextEntry::make('bomba_ferrada')
                            ->label('Bomba ferrada')
                            ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state ? 'Sim' : 'Não'))
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                true => 'success',
                                false => 'danger',
                                default => 'gray',
                            }),
                        ImageEntry::make('bomba_foto')
                            ->label('Foto da Bomba')
                            ->disk('public')
                            ->visible(fn () => filled($this->record->bomba_foto)),
                    ]),

                // ── Filtros ──────────────────────────────────────────────────────
                Section::make('Filtros')
                    ->icon('heroicon-o-funnel')
                    ->columns(2)
                    ->visible(fn () => $this->record->filtro_faz_retrolavagem !== null
                        || filled($this->record->filtro_foto_retrolavagem)
                        || filled($this->record->filtro_foto_enxaguamento)
                        || filled($this->record->filtro_foto_posicao_normal))
                    ->schema([
                        TextEntry::make('filtro_faz_retrolavagem')
                            ->label('Retrolavagem feita')
                            ->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray'),
                        ImageEntry::make('filtro_foto_retrolavagem')
                            ->label('Foto — Posição Retrolavagem')
                            ->disk('public')
                            ->visible(fn () => filled($this->record->filtro_foto_retrolavagem)),
                        ImageEntry::make('filtro_foto_enxaguamento')
                            ->label('Foto — Posição Enxaguamento')
                            ->disk('public')
                            ->visible(fn () => filled($this->record->filtro_foto_enxaguamento)),
                        ImageEntry::make('filtro_foto_posicao_normal')
                            ->label('Foto — Retorno à Posição Normal')
                            ->disk('public')
                            ->visible(fn () => filled($this->record->filtro_foto_posicao_normal)),
                    ]),

                // ── Contador & Água ──────────────────────────────────────────────
                Section::make('Contador & Água')
                    ->icon('heroicon-o-calculator')
                    ->columns(2)
                    ->visible(fn () => filled($this->record->contador_valor)
                        || filled($this->record->agua_modo)
                        || filled($this->record->contador_foto))
                    ->schema([
                        TextEntry::make('contador_valor')
                            ->label('Valor do Contador')
                            ->suffix(' m³')
                            ->visible(fn () => filled($this->record->contador_valor)),
                        TextEntry::make('agua_modo')
                            ->label('Estado da Entrada de Água')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'auto_com_agua' => 'Automático — com água',
                                'auto_sem_agua' => 'Automático — sem água',
                                'on_com_agua'   => 'ON — com água',
                                'on_sem_agua'   => 'ON — sem água',
                                'off'           => 'OFF — sem água na instalação',
                                default         => $state ?? '—',
                            })
                            ->visible(fn () => filled($this->record->agua_modo)),
                        ImageEntry::make('contador_foto')
                            ->label('Foto do Contador')
                            ->disk('public')
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->contador_foto)),
                    ]),

                // ── Tanque de Compensação ────────────────────────────────────────
                Section::make('Tanque de Compensação')
                    ->icon('heroicon-o-beaker')
                    ->columns(2)
                    ->visible(fn () => $this->record->tanque_ok !== null
                        || filled($this->record->tanque_observacoes)
                        || filled($this->record->tanque_foto))
                    ->schema([
                        TextEntry::make('tanque_ok')
                            ->label('Tanque OK')
                            ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state ? 'Sim' : 'Não'))
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                true => 'success',
                                false => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('tanque_observacoes')
                            ->label('Observações do Tanque')
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->tanque_observacoes)),
                        ImageEntry::make('tanque_foto')
                            ->label('Foto do Tanque')
                            ->disk('public')
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->tanque_foto)),
                    ]),

                // ── Análises — Nadador-Salvador ──────────────────────────────────
                Section::make('Análises — Nadador-Salvador')
                    ->icon('heroicon-o-eye')
                    ->columns(2)
                    ->visible(fn () => filled($this->record->ns_ph)
                        || filled($this->record->ns_cloro_livre)
                        || filled($this->record->ns_foto))
                    ->schema([
                        TextEntry::make('ns_ph')
                            ->label('pH (NS)')
                            ->visible(fn () => filled($this->record->ns_ph)),
                        TextEntry::make('ns_cloro_livre')
                            ->label('Cloro Livre — NS (mg/L)')
                            ->visible(fn () => filled($this->record->ns_cloro_livre)),
                        TextEntry::make('ns_cloro_total')
                            ->label('Cloro Total — NS (mg/L)')
                            ->visible(fn () => filled($this->record->ns_cloro_total)),
                        TextEntry::make('ns_temperatura')
                            ->label('Temperatura — NS (ºC)')
                            ->suffix(' ºC')
                            ->visible(fn () => filled($this->record->ns_temperatura)),
                        ImageEntry::make('ns_foto')
                            ->label('Foto da Análise NS')
                            ->disk('public')
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->ns_foto)),
                    ]),

                // ── Nossas Análises ──────────────────────────────────────────────
                Section::make('Nossas Análises')
                    ->icon('heroicon-o-beaker')
                    ->columns(2)
                    ->visible(fn () => filled($this->record->ph)
                        || filled($this->record->cloro_livre)
                        || ! empty($this->record->analises_fotos))
                    ->schema([
                        TextEntry::make('ph')
                            ->label('pH')
                            ->visible(fn () => filled($this->record->ph)),
                        TextEntry::make('cloro_livre')
                            ->label('Cloro Livre (mg/L)')
                            ->visible(fn () => filled($this->record->cloro_livre)),
                        TextEntry::make('cloro_total')
                            ->label('Cloro Total (mg/L)')
                            ->visible(fn () => filled($this->record->cloro_total)),
                        TextEntry::make('temperatura')
                            ->label('Temperatura (ºC)')
                            ->suffix(' ºC')
                            ->visible(fn () => filled($this->record->temperatura)),
                        TextEntry::make('transparencia')
                            ->label('Turbidez (FNU)')
                            ->visible(fn () => filled($this->record->transparencia)),
                        ImageEntry::make('analises_fotos')
                            ->label('Fotos das análises')
                            ->disk('public')
                            ->columnSpanFull()
                            ->visible(fn () => ! empty($this->record->analises_fotos))
                            ->getStateUsing(function (): array {
                                $fromJson = $this->record->analises_fotos ?? [];
                                if (! empty($fromJson)) {
                                    return $fromJson;
                                }

                                return $this->record->fotos()->pluck('path')->toArray();
                            }),
                    ]),

                // ── Adições de Químicos ──────────────────────────────────────────
                Section::make('Adições de Químicos')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn () => $this->record->adicoes->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('adicoes')
                            ->label('')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('produto.name')
                                    ->label('Produto'),
                                TextEntry::make('quantity')
                                    ->label('Quantidade')
                                    ->suffix(fn ($record) => ' '.($record->produto?->unidade ?? 'unid.')),
                                TextEntry::make('acao_corretiva')
                                    ->label('Ação corretiva')
                                    ->visible(fn ($record) => filled($record->acao_corretiva))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                // ── Observações ──────────────────────────────────────────────────
                Section::make('Observações')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->visible(fn () => filled($this->record->observacoes))
                    ->schema([
                        TextEntry::make('observacoes')
                            ->label('')
                            ->columnSpanFull(),
                    ]),

                // ── Informação de Correção ────────────────────────────────────────
                Section::make('Informação de Correção')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn () => (bool) $this->record->e_correcao)
                    ->schema([
                        TextEntry::make('razao_correcao')
                            ->label('Razão da Correção')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
