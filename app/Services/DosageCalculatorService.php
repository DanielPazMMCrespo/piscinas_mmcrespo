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
            'explicacao' => $explicacao,
        ];
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

    public function avaliarTendencia(int $poolId, string $parametro, int $numRegistos = 5): ?array
    {
        $registos = DailyRecord::where('pool_id', $poolId)
            ->whereDoesntHave('correcoes')
            ->orderByDesc('registado_em')
            ->limit($numRegistos)
            ->get()
            ->reverse()
            ->values();

        $valores = [];
        foreach ($registos as $registo) {
            $val = null;
            if ($parametro === 'ph') {
                $val = $registo->ph_efetivo;
            } elseif ($parametro === 'cloro_livre') {
                $val = $registo->cloro_livre_efetivo;
            }

            if ($val !== null) {
                $valores[] = (float) $val;
            }
        }

        if (count($valores) < 3) {
            return null;
        }

        $min = 0;
        $max = 0;
        if ($parametro === 'ph') {
            $min = DailyRecord::getPhMin();
            $max = DailyRecord::getPhMax();
        } elseif ($parametro === 'cloro_livre') {
            $min = DailyRecord::getCloroLivreMin();
            $max = DailyRecord::getCloroLivreMax();
        }
        $midpoint = ($min + $max) / 2;

        $degrading = false;
        $improving = false;
        $consecutiveDecreases = 0;
        $consecutiveIncreases = 0;

        for ($i = 1; $i < count($valores); $i++) {
            if ($valores[$i] < $valores[$i - 1]) {
                $consecutiveDecreases++;
                $consecutiveIncreases = 0;
            } elseif ($valores[$i] > $valores[$i - 1]) {
                $consecutiveIncreases++;
                $consecutiveDecreases = 0;
            } else {
                $consecutiveDecreases = 0;
                $consecutiveIncreases = 0;
            }
        }

        $current = end($valores);

        if (($consecutiveDecreases >= 2 && $current < $midpoint) || ($consecutiveIncreases >= 2 && $current > $midpoint)) {
            $degrading = true;
        } elseif (($consecutiveDecreases >= 2 && $current > $midpoint) || ($consecutiveIncreases >= 2 && $current < $midpoint)) {
            $improving = true;
        }

        // Simple linear regression to predict next value
        $n = count($valores);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        foreach ($valores as $x => $y) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += ($x * $y);
            $sumX2 += ($x * $x);
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        $previsao = $slope * $n + $intercept; // predict for index $n

        $tendencia = 'estavel';
        if ($degrading) {
            $tendencia = 'degradante';
        }
        if ($improving) {
            $tendencia = 'melhorante';
        }

        return [
            'tendencia' => $tendencia,
            'valores' => $valores,
            'previsao' => round($previsao, 2),
            'mensagem' => "Tendência atual é {$tendencia}.",
        ];
    }

    public function registarEficacia(int $poolId, int $productId, float $doseAplicada, float $valorAntes, float $valorDepois, string $parametro): void
    {
        activity()
            ->performedOn(Pool::find($poolId))
            ->withProperties([
                'product_id' => $productId,
                'dose_aplicada' => $doseAplicada,
                'valor_antes' => $valorAntes,
                'valor_depois' => $valorDepois,
                'parametro' => $parametro,
            ])
            ->useLog('dosagem')
            ->log('Eficácia de dosagem registada');
    }
}
