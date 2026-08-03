<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomActivitylogResource\Pages;

use App\Filament\Resources\CustomActivitylogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Sem esta página, o painel servia a `ListActivitylog` do pacote, cuja
 * `$resource` aponta em duro para `Rmsramos\...\ActivitylogResource` — o
 * `table()` do `CustomActivitylogResource` (traduções PT, diff legível,
 * filtro por autor) nunca chegava a ser chamado.
 */
class ListActivitylog extends ListRecords
{
    protected static string $resource = CustomActivitylogResource::class;
}
