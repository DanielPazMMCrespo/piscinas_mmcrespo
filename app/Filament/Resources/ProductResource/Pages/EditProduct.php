<?php declare(strict_types=1);
namespace App\Filament\Resources\ProductResource\Pages;


use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $categoriasFixas = Product::query()
            ->distinct()
            ->whereNotNull('categoria')
            ->pluck('categoria')
            ->toArray();

        if (!in_array($data['categoria'] ?? '', $categoriasFixas, true)) {
            $data['categoria_custom'] = $data['categoria'] ?? '';
            $data['categoria'] = 'outro';
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['categoria'] ?? null) === 'outro' && !empty($data['categoria_custom'])) {
            $data['categoria'] = $data['categoria_custom'];
        }
        unset($data['categoria_custom']);
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
