<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\Product;

class DosageCalculatorService
{
    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Calculates recommended chemical dosage when water parameters are out of compliance.
     */
    public function calcularDose(Pool $piscina, string $parametro, float $valorAtual): ?array
    {
        $fatorCompensacao = $this->settingsService->getFloat('fator_compensacao_dosagem', 1.25);
        $produto = $this->sugerirProduto($parametro, $piscina->installation_id);

        if (! $produto) {
            return null; // Nao ha produto disponivel
        }

        $volumeLitros = $piscina->volume * 1000;
        $deficit = 0.0;
        $explicacao = '';

        if ($parametro === 'cloro_livre') {
            $min = DailyRecord::getCloroLivreMin();
            $max = DailyRecord::getCloroLivreMax();

            if ($valorAtual >= $min) {
                return null; // Dentro ou acima dos limites, nao precisa de dosagem positiva
            }

            $target = ($min + $max) / 2;
            $deficit = $target - $valorAtual; // mg/L
            $explicacao = "Aumentar cloro livre para o ponto ideal ({$target} ppm).";
        } elseif ($parametro === 'ph') {
            $min = DailyRecord::getPhMin();
            $max = DailyRecord::getPhMax();

            if ($valorAtual >= $min && $valorAtual <= $max) {
                return null;
            }

            $target = ($min + $max) / 2;
            $diff = abs($target - $valorAtual);

            // Regra prática para pH: ~10ml/g por m³ por cada 0.1 de alteração no pH
            $volumeM3 = $piscina->volume;
            $doseCalculada = $volumeM3 * ($diff / 0.1) * 10;
            $doseComFator = $doseCalculada * $fatorCompensacao;

            $acao = $valorAtual < $min ? 'Aumentar pH' : 'Reduzir pH';
            $targetFmt = number_format($target, 2, ',', '');

            return [
                'produto' => $produto,
                'dose_calculada_ml' => round($doseCalculada, 2),
                'dose_com_fator_ml' => round($doseComFator, 2),
                'unidade' => $produto->unidade ?? 'ml',
                'dose_formatada' => $this->formatarDose($doseComFator, $produto->unidade),
                'explicacao' => "{$acao} para o valor ideal ({$targetFmt}).",
            ];
        } else {
            return null; // Parametro nao suportado para calculo automatico
        }

        $concentracao = $produto->concentracao_cl ?? 10.0; // 10% por defeito se nao definido

        // Formula: Volume(L) × Deficit(mg/L) / (Concentração% × 10) × FatorCompensação
        $doseCalculada = ($volumeLitros * $deficit) / ($concentracao * 10);
        $doseComFator = $doseCalculada * $fatorCompensacao;

        return [
            'produto' => $produto,
            'dose_calculada_ml' => round($doseCalculada, 2),
            'dose_com_fator_ml' => round($doseComFator, 2),
            'unidade' => $produto->unidade ?? 'ml',
            'dose_formatada' => $this->formatarDose($doseComFator, $produto->unidade),
            'explicacao' => $explicacao,
        ];
    }

    /**
     * A dose é calculada em ml (líquidos) ou g (sólidos). O produto é vendido em
     * L ou kg, por isso imprimir o número cru com a unidade do produto dava um
     * valor 1000x maior do que o real ("+24.438 kg" em vez de "24,4 L").
     */
    private function formatarDose(float $doseMlOuG, ?string $unidade): string
    {
        return match (strtolower(trim((string) $unidade))) {
            'l', 'litro', 'litros' => number_format($doseMlOuG / 1000, 2, ',', ' ').' L',
            'kg' => number_format($doseMlOuG / 1000, 2, ',', ' ').' kg',
            'g' => number_format($doseMlOuG, 0, ',', ' ').' g',
            default => number_format($doseMlOuG, 0, ',', ' ').' ml',
        };
    }

    public function sugerirProduto(string $parametro, int $installationId): ?Product
    {
        $categoria = '';
        if (in_array($parametro, ['cloro_livre', 'cloro_total'])) {
            $categoria = 'Desinfeção';
        } elseif ($parametro === 'ph') {
            $categoria = 'Correção pH';
        } else {
            return null;
        }

        // Tentar primeiro produtos com stock positivo na instalação
        $produtoComStock = Product::where('categoria', $categoria)
            ->where('active', true)
            ->whereHas('stockInstalacoes', function ($q) use ($installationId) {
                $q->where('installation_id', $installationId)
                    ->where('quantity', '>', 0);
            })
            ->orderByDesc('concentracao_cl')
            ->first();

        if ($produtoComStock) {
            return $produtoComStock;
        }

        // Fallback: qualquer produto ativo da categoria para não deixar de sugerir
        return Product::where('categoria', $categoria)
            ->where('active', true)
            ->orderByDesc('concentracao_cl')
            ->first();
    }
}
