<?php declare(strict_types=1);

namespace App\Filament\Resources;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Rmsramos\Activitylog\Resources\ActivitylogResource;
use Spatie\Activitylog\Models\Activity;

class CustomActivitylogResource extends ActivitylogResource
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                static::getLogNameColumnComponent(),
                static::getEventColumnComponent(),
                TextColumn::make('description')
                    ->label('Descrição / Ação')
                    ->formatStateUsing(function ($state, Model $record) {
                        /** @var Activity $record */
                        $desc = $state ?? $record->description;
                        if ($desc === 'created' || $desc === 'updated' || $desc === 'deleted') {
                            return '-';
                        }
                        return $desc;
                    })
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                static::getSubjectTypeColumnComponent(),
                static::getCauserNameColumnComponent(),
                static::getPropertiesColumnComponent(),
                static::getCreatedAtColumnComponent(),
            ])
            ->defaultSort(
                config('filament-activitylog.resources.default_sort_column', 'created_at'),
                config('filament-activitylog.resources.default_sort_direction', 'desc')
            )
            ->filters([
                static::getDateFilterComponent(),
                static::getEventFilterComponent(),
                static::getLogNameFilterComponent(),
            ]);
    }

    public static function getLogNameColumnComponent(): Column
    {
        return TextColumn::make('log_name')
            ->label('Categoria')
            ->formatStateUsing(function ($state) {
                if (!$state || $state === 'default') {
                    return 'Geral';
                }
                
                $translations = [
                    'auth' => 'Autenticação',
                    'analise' => 'Análises',
                    'relatorios' => 'Relatórios',
                ];

                return $translations[$state] ?? ucwords($state);
            })
            ->searchable()
            ->sortable()
            ->badge();
    }

    public static function getEventColumnComponent(): Column
    {
        return TextColumn::make('event')
            ->label('Evento')
            ->formatStateUsing(function ($state) {
                $translations = [
                    'created' => 'Criou',
                    'updated' => 'Atualizou',
                    'deleted' => 'Apagou',
                    'restored' => 'Restaurou',
                    'draft' => 'Rascunho'
                ];
                return $state ? ($translations[$state] ?? ucwords($state)) : '-';
            })
            ->badge()
            ->color(fn (?string $state): string => match ($state) {
                'draft'    => 'gray',
                'updated'  => 'warning',
                'created'  => 'success',
                'deleted'  => 'danger',
                'restored' => 'info',
                default    => 'primary',
            })
            ->searchable()
            ->sortable();
    }

    public static function getSubjectTypeColumnComponent(): Column
    {
        return TextColumn::make('subject_type')
            ->label('Alvo da Ação')
            ->formatStateUsing(function ($state, Model $record) {
                /** @var Activity $record */
                if (! $state) {
                    return '-';
                }

                $modelNames = [
                    'AppSetting' => 'Configuração',
                    'DailyRecord' => 'Registo Diário',
                    'HannaDevice' => 'Equip. Hanna',
                    'Incident' => 'Incidente',
                    'Installation' => 'Instalação',
                    'OperationalAction' => 'Ação Operacional',
                    'Pool' => 'Piscina',
                    'Product' => 'Produto',
                    'StockInstallation' => 'Stock Instalação',
                    'StockWarehouse' => 'Stock Armazém',
                    'User' => 'Utilizador',
                ];

                $modelBase = Str::of($state)->afterLast('\\')->toString();
                $modelTranslated = $modelNames[$modelBase] ?? $modelBase;

                if (! $record->subject) {
                    return "{$modelTranslated} #{$record->subject_id} (Apagado)";
                }

                $subject = $record->subject;
                $identifier = $record->subject_id;
                
                if (isset($subject->name)) {
                    $identifier = $subject->name;
                } elseif (isset($subject->label)) {
                    $identifier = $subject->label;
                } elseif (isset($subject->produto) && isset($subject->produto->name)) {
                    $identifier = $subject->produto->name;
                } elseif (isset($subject->product) && isset($subject->product->name)) {
                    $identifier = $subject->product->name;
                } elseif ($modelBase === 'DailyRecord' && isset($subject->registado_em)) {
                    $identifier = \Carbon\Carbon::parse($subject->registado_em)->format('d/m/Y H:i');
                } elseif (in_array($modelBase, ['Incident', 'OperationalAction']) && isset($subject->type)) {
                    $identifier = "#{$subject->id} (" . str_replace('_', ' ', $subject->type) . ")";
                }

                $subjectInfo = "{$modelTranslated}: {$identifier}";

                if (method_exists($subject, 'trashed') && $subject->trashed()) {
                    $subjectInfo .= ' (Lixo)';
                }

                return $subjectInfo;
            })
            ->searchable();
    }

    public static function getCauserNameColumnComponent(): Column
    {
        return TextColumn::make('causer.name')
            ->label('Autor (Utilizador)')
            ->getStateUsing(function (Model $record) {
                /** @var Activity $record */
                if ($record->causer_id === null || $record->causer === null) {
                    return 'Sistema / Automático';
                }

                return $record->causer->name ?? 'Sistema / Automático';
            })
            ->searchable();
    }
}
