<?php declare(strict_types=1);
namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['categoria'] ?? null === 'outro' && !empty($data['categoria_custom'])) {
            $data['categoria'] = $data['categoria_custom'];
        }
        unset($data['categoria_custom']);
        return $data;
    }
}

