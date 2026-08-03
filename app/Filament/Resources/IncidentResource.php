<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Concerns\HasPeriodoFilter;
use App\Filament\Resources\IncidentResource\Pages;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Pool;
use App\Notifications\IncidentMessageNotification;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Gestão de anomalias/incidentes nas piscinas. Os incidentes podem ser manuais (reportados pelo técnico)
 * ou gerados automaticamente pelo sistema.
 *
 * IMPLEMENTADO:
 * - Ciclo de Vida: Incidentes têm estado definido (`status`: aberto/resolvido).
 * - Ação "Resolver": Marca a data, o utilizador que resolveu e o texto de resolução.
 * - Auto-resolução: Incidentes associados a anomalias auto-resolvem-se no Kanban quando o problema desaparece.
 * - Regra Estrita: Incidentes NUNCA devem ser apagados pelos utilizadores, apenas resolvidos.
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
class IncidentResource extends Resource
{
    use HasPeriodoFilter;

    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Registo Diário';

    protected static ?string $navigationLabel = 'Incidentes';

    protected static ?string $modelLabel = 'Incidente';

    protected static ?string $pluralModelLabel = 'Incidentes';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('piscina.encerramentos');

        if (auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Reforça no Resource a mesma regra já validada em IncidentPolicy::create()
     * (defesa em profundidade — mantida sincronizada com a Policy).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return ($user?->hasAnyRole(UserRole::all()) ?? false) && $user->podeVer(NSPermission::INCIDENTES);
    }

    /**
     * Apenas o admin pode editar/eliminar incidentes. O pessoal de campo regista e consulta.
     */
    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['descricao', 'piscina.name', 'instalacao.name', 'type'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return Str::limit((string) $record->descricao, 60) ?: 'Incidente';
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Instalação' => $record->instalacao->name,
            'Piscina' => $record->piscina?->name ?? '—',
            'Estado' => $record->status === IncidentStatus::RESOLVIDO ? 'Resolvido' : 'Aberto',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['instalacao', 'piscina']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação')
                    ->relationship(
                        'instalacao',
                        'name',
                        modifyQueryUsing: fn (Builder $query) => auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)
                            ? $query->whereHas('piscinas', fn (Builder $q) => $q->whereIn('id', auth()->user()->piscinas()->pluck('pools.id')))
                            : $query,
                    )
                    ->required()
                    ->preload()
                    ->searchable()
                    ->live()
                    // Vir do dashboard/registo com a piscina já escolhida evita
                    // repetir o que o utilizador acabou de ver noutro ecrã.
                    ->default(fn () => request()->integer('installation')
                        ?: Pool::find(request()->integer('pool'))?->installation_id)
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('pool_id', null)),
                Forms\Components\Select::make('pool_id')
                    ->label('Piscina (opcional)')
                    ->helperText('Deixe em branco se o incidente for da instalação toda (ex: fuga geral, avaria de bomba).')
                    ->relationship(
                        'piscina',
                        'name',
                        modifyQueryUsing: fn (Builder $query, Get $get) => $query
                            ->when($get('installation_id'), fn (Builder $q, $installationId) => $q->where('installation_id', $installationId))
                            ->when(
                                auth()->user()->hasRole(UserRole::NADADOR_SALVADOR),
                                fn (Builder $q) => $q->whereIn('id', auth()->user()->piscinas()->pluck('pools.id')),
                            ),
                    )
                    ->preload()
                    ->searchable()
                    ->default(fn () => request()->integer('pool') ?: null)
                    ->disabled(fn (Get $get): bool => ! $get('installation_id')),
                Forms\Components\Hidden::make('user_id')
                    ->default(auth()->id())
                    ->dehydrated(),
                Forms\Components\DateTimePicker::make('ocorreu_em')
                    ->label('Data/Hora da Ocorrência')
                    ->default(now())
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Tipo de Incidente')
                    ->options(IncidentType::options())
                    ->required(),
                Forms\Components\Textarea::make('descricao')
                    ->label('Descrição do Problema')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações Adicionais')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('fotos')
                    ->label('Fotos')
                    ->helperText('Evidência da avaria/ocorrência (até 5 fotos).')
                    ->disk(DailyRecord::getStorageDisk())
                    ->visibility('public')
                    ->directory('incidentes')
                    ->image()
                    ->multiple()
                    ->maxFiles(5)
                    ->maxSize(20480)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->columnSpanFull(),
                Forms\Components\Section::make('Resolução')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (?Incident $record): bool => $record?->status === IncidentStatus::RESOLVIDO)
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('resolvido_em')
                            ->label('Resolvido em')
                            ->content(fn (?Incident $record): string => $record?->resolvido_em?->format('d/m/Y H:i') ?? '—'),
                        Forms\Components\Placeholder::make('resolvido_por')
                            ->label('Resolvido por')
                            ->content(fn (?Incident $record): string => $record?->resolvidoPor?->name ?? '—'),
                        Forms\Components\Textarea::make('resolucao')
                            ->label('Resolução aplicada')
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function tipoIcone(?string $type): string
    {
        return match ($type) {
            IncidentType::AVARIA_EQUIPAMENTO => 'heroicon-o-wrench-screwdriver',
            IncidentType::FUGA_AGUA => 'heroicon-o-cloud',
            IncidentType::QUALIDADE_AGUA => 'heroicon-o-beaker',
            default => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('type')
                        ->label('Tipo')
                        ->badge()
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->icon(fn (?string $state) => self::tipoIcone($state))
                        ->formatStateUsing(fn (?string $state): string => IncidentType::label($state)),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'Resolvido' : 'Aberto')
                        ->color(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'success' : 'danger')
                        ->icon(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle'),
                    Infolists\Components\TextEntry::make('ocorreu_em')
                        ->label('Ocorreu em')
                        ->icon('heroicon-o-clock')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('utilizador.name')
                        ->label('Reportado por')
                        ->icon('heroicon-o-user'),
                    Infolists\Components\TextEntry::make('instalacao.name')
                        ->label('Instalação')
                        ->icon('heroicon-o-building-office-2'),
                    Infolists\Components\TextEntry::make('piscina.name')
                        ->label('Piscina')
                        ->icon('heroicon-o-map-pin')
                        ->visible(fn (?Incident $record) => filled($record?->pool_id)),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Descrição')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Infolists\Components\TextEntry::make('descricao')
                        ->hiddenLabel()
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('observacoes')
                        ->label('Observações')
                        ->visible(fn (?Incident $record) => filled($record?->observacoes))
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Fotos')
                ->icon('heroicon-o-camera')
                ->visible(fn (?Incident $record) => filled($record?->fotos))
                ->schema([
                    Infolists\Components\ImageEntry::make('fotos')
                        ->hiddenLabel()
                        ->disk(DailyRecord::getStorageDisk())
                        ->size(320)
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Resolução')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (?Incident $record): bool => $record?->status === IncidentStatus::RESOLVIDO)
                ->schema([
                    Infolists\Components\TextEntry::make('resolvido_em')
                        ->label('Resolvido em')
                        ->icon('heroicon-o-clock')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('resolvidoPor.name')
                        ->label('Resolvido por')
                        ->icon('heroicon-o-user'),
                    Infolists\Components\TextEntry::make('resolucao')
                        ->label('Resolução aplicada')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['instalacao', 'piscina', 'utilizador']))
            ->recordUrl(fn (Incident $record): string => static::getUrl('view', ['record' => $record]))
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('instalacao.name')
                            ->label('Instalação')
                            ->weight('bold')
                            ->sortable()
                            ->searchable()
                            ->description(fn (Incident $record): ?string => trim(
                                ($record->piscina?->name ? $record->piscina->name.' · ' : '')
                                .Str::limit((string) $record->descricao, 70)
                            )),
                        Tables\Columns\TextColumn::make('ocorreu_em')
                            ->label('Data/Hora')
                            ->dateTime('d/m/Y H:i')
                            ->description(fn (Incident $record): string => $record->ocorreu_em->locale('pt')->diffForHumans()
                                // Contexto para quem lê o incidente meses depois:
                                // a piscina estava fechada quando isto aconteceu.
                                .($record->piscina?->estaEncerradaEm($record->ocorreu_em) ? ' · piscina encerrada' : ''))
                            ->color('gray')
                            ->size('sm')
                            ->sortable(),
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->size('sm')
                            ->icon('heroicon-m-user')
                            ->sortable(),
                    ])->space(1),
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => IncidentType::label($state))
                            ->searchable(),
                        Tables\Columns\TextColumn::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'Resolvido' : 'Aberto')
                            ->color(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'success' : 'danger')
                            ->icon(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle'),
                    ])->space(1),
                ])->from('md'),
                // Colunas só para pesquisa: "bomba" não encontrava
                // "Bomba do filtro com ruído anormal" (a busca não cobria a descrição).
                Tables\Columns\TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([IncidentStatus::ABERTO => 'Aberto', IncidentStatus::RESOLVIDO => 'Resolvido'])
                    ->default(IncidentStatus::ABERTO),
                Tables\Filters\SelectFilter::make('installation_id')
                    ->label('Instalação')
                    ->relationship('instalacao', 'name')
                    ->preload(),
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->relationship('piscina', 'name')
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(IncidentType::options()),
                static::filtroPeriodo('ocorreu_em', mesCorrentePorOmissao: false),
            ], layout: FiltersLayout::Modal)
            ->emptyStateHeading('Sem incidentes')
            ->emptyStateDescription('A lista mostra apenas os incidentes abertos por omissão — abra os filtros para ver os resolvidos.')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::resolverTableAction(),
                    Tables\Actions\ViewAction::make()->slideOver(),
                    Tables\Actions\EditAction::make()->slideOver(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'view' => Pages\ViewIncident::route('/{record}'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
        ];
    }

    /**
     * Ação "Resolver" para a tabela de listagem. Partilha a lógica com
     * {@see resolverHeaderAction()} (usada na página de visualização) via
     * {@see aplicarResolucao()} — evita duplicar a regra de negócio nos dois sítios.
     */
    public static function resolverTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resolver')
            ->label('Resolver')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->slideOver()
            ->visible(fn (Incident $record): bool => static::podeResolver($record))
            ->modalHeading('Resolver incidente')
            ->modalDescription('Descreva como foi resolvido. O incidente sai do quadro de operação.')
            ->modalSubmitActionLabel('Marcar como resolvido')
            ->form(static::resolverFormSchema())
            ->action(fn (Incident $record, array $data) => static::aplicarResolucao($record, $data));
    }

    /**
     * Ação "Resolver" para o cabeçalho de {@see Pages\ViewIncident}.
     */
    public static function resolverHeaderAction(): Action
    {
        return Action::make('resolver')
            ->label('Resolver')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->slideOver()
            ->visible(fn (Incident $record): bool => static::podeResolver($record))
            ->modalHeading('Resolver incidente')
            ->modalDescription('Descreva como foi resolvido. O incidente sai do quadro de operação.')
            ->modalSubmitActionLabel('Marcar como resolvido')
            ->form(static::resolverFormSchema())
            ->action(fn (Incident $record, array $data) => static::aplicarResolucao($record, $data));
    }

    private static function podeResolver(Incident $record): bool
    {
        return $record->status !== IncidentStatus::RESOLVIDO
            && (auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false);
    }

    /** @return array<Component> */
    private static function resolverFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('resolucao')
                ->label('Resolução aplicada')
                ->required()
                ->minLength(5)
                ->rows(3),
        ];
    }

    private static function aplicarResolucao(Incident $record, array $data): void
    {
        if (! static::podeResolver($record)) {
            Notification::make()->danger()->title('Sem permissão')->send();

            return;
        }

        $record->update([
            'status' => IncidentStatus::RESOLVIDO,
            'resolvido_em' => now(),
            'resolvido_por' => auth()->id(),
            'resolucao' => $data['resolucao'],
        ]);

        $texto = "Estado alterado para: Resolvido — {$data['resolucao']}";

        IncidentMessage::create([
            'incident_id' => $record->id,
            'user_id' => auth()->id(),
            'tipo' => IncidentMessage::TIPO_SISTEMA,
            'texto' => $texto,
        ]);

        \Illuminate\Support\Facades\Notification::send(
            $record->participantes(excluir: auth()->user()),
            new IncidentMessageNotification($record, auth()->user(), $texto)
        );

        Notification::make()
            ->success()
            ->title('Incidente resolvido')
            ->body('Saiu do quadro de operação.')
            ->send();
    }
}
