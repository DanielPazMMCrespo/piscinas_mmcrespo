<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\Pool;
use App\Models\SensorOutage;
use App\Models\SensorReading;

/**
 * Cascata de fontes centralizada: sonda fresca (≤60 min) → leitura manual
 * mais recente (≤8h) → sonda em avaria declarada → sonda stale → sem dados.
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
     * - 'source': 'hanna_online' | 'hanna_avaria' | 'hanna_stale' | 'manual' | 'none'
     * - 'reading': ?SensorReading (se Hanna)
     * - 'record': ?DailyRecord (se manual)
     * - 'age_minutes': ?int (minutos desde a leitura, só Hanna online)
     * - 'is_artifact': bool (se a leitura é artefacto e não conta para conformidade)
     * - 'artifact_reason': ?string (motivo legível do artefacto)
     * - 'outage': ?SensorOutage (avaria da sonda em aberto, se houver)
     *
     * @return array{source: string, reading: ?SensorReading, record: ?DailyRecord, age_minutes: ?int, is_artifact: bool, artifact_reason: ?string, outage: ?SensorOutage}
     */
    /**
     * @param  HannaDevice|null  $deviceCarregado  sonda já carregada pelo chamador
     * @param  SensorReading|null  $leituraCarregada  última leitura já carregada
     * @param  DailyRecord|null  $registoCarregado  último registo já carregado
     * @param  SensorOutage|null  $avariaCarregada  avaria da sonda em aberto já carregada
     */
    public function selectSource(
        Pool $pool,
        ?HannaDevice $deviceCarregado = null,
        ?SensorReading $leituraCarregada = null,
        ?DailyRecord $registoCarregado = null,
        bool $usarCarregados = false,
        ?SensorOutage $avariaCarregada = null,
    ): array {
        $device = $usarCarregados ? $deviceCarregado : HannaDevice::query()
            ->where('active', true)
            ->where('pool_id', $pool->id)
            ->first();

        // Avaria declarada por ação operacional: a sonda deixa de ser fonte de
        // verdade mesmo que continue a mandar leituras frescas (uma peça partida
        // manda valores, só não são dela).
        $avaria = $usarCarregados ? $avariaCarregada : SensorOutage::abertaPara($pool->id);

        $leitura = null;
        $idadeMin = null;
        $artefacto = null;

        if ($device !== null) {
            $leitura = $usarCarregados ? $leituraCarregada : SensorReading::query()
                ->where('hanna_device_id', $device->hanna_device_id)
                ->latest('lida_em')
                ->first();

            if ($leitura !== null) {
                $idadeMin = (int) $leitura->lida_em->diffInMinutes(now());
                $artefacto = $avaria !== null
                    ? $avaria->resumo()
                    : app(LeituraArtefactoService::class)->motivoEm($pool->id, $leitura->lida_em);
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
                'artifact_reason' => null,
                'outage' => null,
            ];
        }

        // Senão, procura leitura manual fresca (≤8h)
        $registo = $usarCarregados ? $registoCarregado : DailyRecord::latestPerPool()
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
                'artifact_reason' => null,
                // Vai preenchido de propósito mesmo com fonte manual: o estado da
                // sonda tem de continuar visível quando há registo manual fresco.
                'outage' => $avaria,
            ];
        }

        // Sonda declarada em avaria: estado próprio, para a UI dizer o motivo em
        // vez de "controlador desatualizado" (que soa a problema de rede).
        if ($avaria !== null) {
            return [
                'source' => 'hanna_avaria',
                'reading' => $leitura,
                'record' => null,
                'age_minutes' => $idadeMin,
                'is_artifact' => $leitura !== null,
                'artifact_reason' => $leitura !== null ? $avaria->resumo() : null,
                'outage' => $avaria,
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
                'artifact_reason' => $artefacto,
                'outage' => null,
            ];
        }

        return [
            'source' => 'none',
            'reading' => null,
            'record' => null,
            'age_minutes' => null,
            'is_artifact' => false,
            'artifact_reason' => null,
            'outage' => null,
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
