<?php declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorReading;
use Carbon\Carbon;

/**
 * Cascata de fontes centralizada: sonda fresca (≤60 min) → leitura manual
 * mais recente (≤8h) → sonda stale → sem dados.
 *
 * Usada por EsquemaPiscina e PainelPiscinasWidget para decisão unificada
 * sobre qual fonte de verdade usar para cada piscina.
 */
class SourceSelectionService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Determina qual fonte (sonda/manual/stale/nenhuma) usar para uma piscina.
     *
     * Retorna um array com:
     * - 'source': 'hanna_online' | 'hanna_stale' | 'manual' | 'none'
     * - 'reading': ?SensorReading (se Hanna)
     * - 'record': ?DailyRecord (se manual)
     * - 'age_minutes': ?int (minutos desde a leitura, só Hanna online)
     * - 'is_artifact': ?bool (se Hanna em artefacto)
     *
     * @return array{source: string, reading: ?SensorReading, record: ?DailyRecord, age_minutes: ?int, is_artifact: ?bool}
     */
    public function selectSource(Pool $pool): array
    {
        $device = HannaDevice::query()
            ->where('active', true)
            ->where('pool_id', $pool->id)
            ->first();

        $leitura = null;
        $idadeMin = null;
        $artefacto = null;

        if ($device !== null) {
            $leitura = SensorReading::query()
                ->where('hanna_device_id', $device->hanna_device_id)
                ->latest('lida_em')
                ->first();

            if ($leitura !== null) {
                $idadeMin = (int) $leitura->lida_em->diffInMinutes(now());
                $artefacto = app(LeituraArtefactoService::class)->motivoEm($pool->id, $leitura->lida_em);
            }
        }

        $hannaOnline = $leitura !== null
            && $idadeMin !== null
            && $idadeMin <= $this->getSondaFreshMinutes()
            && $artefacto === null;

        // Se Hanna online, usa Hanna
        if ($hannaOnline) {
            return [
                'source' => 'hanna_online',
                'reading' => $leitura,
                'record' => null,
                'age_minutes' => $idadeMin,
                'is_artifact' => false,
            ];
        }

        // Senão, procura leitura manual fresca (≤8h)
        $registo = DailyRecord::latestPerPool()
            ->where('pool_id', $pool->id)
            ->first();

        $manual = null;
        if ($registo !== null && abs((int) $registo->registado_em->diffInHours(now())) <= $this->getManualFreshHours()) {
            $manual = $registo;
        }

        if ($manual !== null) {
            return [
                'source' => 'manual',
                'reading' => null,
                'record' => $manual,
                'age_minutes' => null,
                'is_artifact' => false,
            ];
        }

        // Fallback: Hanna stale (se existir) ou nenhuma
        if ($leitura !== null) {
            return [
                'source' => 'hanna_stale',
                'reading' => $leitura,
                'record' => null,
                'age_minutes' => $idadeMin,
                'is_artifact' => $artefacto !== null,
            ];
        }

        return [
            'source' => 'none',
            'reading' => null,
            'record' => null,
            'age_minutes' => null,
            'is_artifact' => false,
        ];
    }

    /**
     * Timings configuráveis expostos para reutilização (ex.: UI tooltips).
     */
    public function getSondaFreshMinutes(): int
    {
        return $this->settings->getInt('sonda_online_minutos', 60);
    }

    public function getManualFreshHours(): int
    {
        return $this->settings->getInt('registo_manual_validade_horas', 8);
    }
}
