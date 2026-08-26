<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Exceptions\SensorCommunicationException;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\HannaDevice;
use App\Models\SensorReading;
use App\Models\User;
use App\Notifications\HannaOvertimeAlert;
use App\Notifications\HannaSyncFalhouNotification;
use App\Notifications\HannaThresholdAlert;
use App\Services\HannaCircuitBreaker;
use App\Services\HannaCloudService;
use App\Services\LeituraArtefactoService;
use App\Support\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sincroniza as últimas leituras de todos os dispositivos Hanna Cloud ativos.
 *
 * Corre a cada 5 minutos via scheduler (routes/console.php).
 * Guarda em sensor_readings; ignora duplicados (mesmo device + mesmo timestamp).
 *
 * Uso manual: php artisan hanna:sync
 * Descobrir dispositivos da conta: php artisan hanna:sync --discover
 */
class HannaCloudSync extends Command
{
    protected $signature = 'hanna:sync {--discover : Lista dispositivos da conta e cria/actualiza hanna_devices}';

    protected $description = 'Sincroniza leituras dos sensores Hanna Cloud';

    public function handle(HannaCloudService $hanna): int
    {
        $email = config('services.hanna.email');
        $password = config('services.hanna.password');

        if (empty($email) || empty($password)) {
            $this->error('HANNA_CLOUD_EMAIL e HANNA_CLOUD_PASSWORD não estão definidos no .env');

            return self::FAILURE;
        }

        try {
            $hanna->authenticate($email, $password);
            $this->info('Hanna Cloud: autenticado.');
            Cache::forget(self::AUTH_FALHA_CACHE_KEY);
        } catch (\Throwable $e) {
            $this->error('Falha na autenticação: '.$e->getMessage());
            Log::error('HannaCloudSync auth: '.$e->getMessage());
            Auditoria::sistema('Sincronização Hanna falhou: autenticação recusada.', [
                'erro' => $e->getMessage(),
            ]);
            $this->notificarFalhaDeAutenticacao($e->getMessage());

            return self::FAILURE;
        }

        // --discover: sincroniza a lista de dispositivos (corre uma vez ao configurar)
        if ($this->option('discover')) {
            return $this->discover($hanna);
        }

        // Sincronização normal: lê últimas leituras de todos os dispositivos ativos
        $devices = HannaDevice::where('active', true)->get();

        if ($devices->isEmpty()) {
            $this->warn('Nenhum dispositivo Hanna configurado. Corre com --discover primeiro.');

            return self::SUCCESS;
        }

        // Atualiza raw_info (setpoints + config, incl. DS) em cada ciclo de sync.
        // getDeviceSettings() é por-dispositivo porque a query de lista devices()
        // devolve reportedSettings reduzido (só SY/GS, sem DS/AS).
        foreach ($devices as $device) {
            try {
                $info = $hanna->getDeviceSettings($device->hanna_device_id);
                if (! empty($info)) {
                    $device->update(['raw_info' => $info]);
                }
            } catch (\Throwable) {
                // não-fatal: continua com o raw_info anterior
            }
        }

        $sincronizados = 0;
        $falhas = [];

        foreach ($devices as $device) {
            try {
                $reading = HannaCircuitBreaker::execute(
                    function () use ($hanna, $device) {
                        try {
                            return $hanna->getLastReading($device->hanna_device_id);
                        } catch (\Throwable $e) {
                            $statusCode = null;
                            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                                $statusCode = (int) $e->getResponse()->status();
                            } elseif (isset($e->response) && method_exists($e->response, 'status')) {
                                $statusCode = (int) $e->response->status();
                            }
                            throw new SensorCommunicationException(
                                $device->hanna_device_id,
                                1,
                                $statusCode,
                                $e
                            );
                        }
                    },
                    fn () => null // fallback: skip
                );

                if ($reading === null) {
                    $this->warn("  ⚠ {$device->name}: API indisponível (circuit breaker aberto).");

                    continue;
                }

                $this->atualizarPhOvertime($device, $reading);

                $lida_em = $reading['dt']
                    ? Carbon::parse($reading['dt'])
                    : now();

                // Validar que a data é plausível (não futura, não > 30 dias atrás)
                if ($lida_em->gt(now()->addMinutes(10)) || $lida_em->lt(now()->subDays(30))) {
                    $this->warn("  ✗ {$device->name}: lida_em implausível ({$lida_em}); leitura ignorada.");

                    continue;
                }

                // Validar valores dentro de limites físicos plausíveis
                $reading = $this->sanitizarLeitura($reading, $device);
                if ($reading === null) {
                    continue;
                }

                // Evita duplicados de forma atómica usando upsert (INSERT ... ON CONFLICT DO NOTHING)
                $affected = SensorReading::upsert(
                    [[
                        'pool_id' => $device->pool_id,
                        'hanna_device_id' => $device->hanna_device_id,
                        'lida_em' => $lida_em->toDateTimeString(),
                        'ph' => $reading['ph'],
                        'orp' => $reading['orp'],
                        'temperatura_agua' => $reading['temperatura_agua'],
                        'temperatura_ar' => $reading['temperatura_ar'],
                        'caudal_ph' => $reading['caudal_ph'],
                        'caudal_cloro' => $reading['caudal_cloro'],
                        'raw_parameters' => json_encode($reading['raw_parameters']),
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ]],
                    ['hanna_device_id', 'lida_em'], // unique constraint
                    [] // do nothing on conflict (no columns updated)
                );

                if ($affected > 0) {
                    $sincronizados++;
                    $this->line("  ✓ {$device->name}: pH={$reading['ph']} ORP={$reading['orp']}mV T={$reading['temperatura_agua']}°C");
                    $this->notificarThresholds($device, $reading);
                } else {
                    $this->line("  – {$device->name}: leitura já existe ({$lida_em})");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ {$device->name}: ".$e->getMessage());
                Log::error("HannaCloudSync [{$device->hanna_device_id}]: ".$e->getMessage());
                $falhas[$device->name] = $e->getMessage();
            }

            // Desconto do volume doseado nos bidões (independente da leitura acima).
            if ($device->pool_id !== null) {
                try {
                    $this->sincronizarDosagem($device, $hanna);
                } catch (\Throwable $e) {
                    Log::warning("HannaCloudSync dosagem [{$device->hanna_device_id}]: ".$e->getMessage());
                }
            }
        }

        // Só o ciclo com falhas entra no trilho: um sync bem-sucedido a cada
        // 15 min encheria a auditoria de ruído sem valor nenhum.
        if ($falhas !== []) {
            Auditoria::sistema(
                'Sincronização Hanna com falhas em '.count($falhas).' de '.$devices->count().' sonda(s).',
                ['sondas' => $falhas],
            );
        }

        $this->info("Sync concluído: {$sincronizados} leitura(s) novas.");

        return self::SUCCESS;
    }

    /** Marca que a falha de autenticação já foi comunicada à equipa. */
    private const AUTH_FALHA_CACHE_KEY = 'hanna:sync:auth_falha_notificada';

    /**
     * O sync corre a cada 15 min: sem esta janela, uma credencial errada
     * enviaria 96 notificações por dia.
     */
    private const AUTH_FALHA_REPETIR_HORAS = 6;

    /**
     * Avisa admins e técnicos que as sondas deixaram de sincronizar. Sem isto
     * a página Sensores Hanna mostra "sonda em falha" sem dizer que a causa
     * real é a credencial, e as leituras ficam paradas dias sem ninguém notar.
     */
    private function notificarFalhaDeAutenticacao(string $erro): void
    {
        if (Cache::has(self::AUTH_FALHA_CACHE_KEY)) {
            return;
        }

        Cache::put(
            self::AUTH_FALHA_CACHE_KEY,
            now()->toDateTimeString(),
            now()->addHours(self::AUTH_FALHA_REPETIR_HORAS),
        );

        // whereHas em vez de ->role(): o scope do Spatie lança RoleDoesNotExist
        // se um dos papéis não existir, e um handler de falha nunca deve
        // transformar uma falha tratada numa excepção não apanhada.
        $destinatarios = User::whereHas(
            'roles',
            fn ($q) => $q->whereIn('name', [UserRole::ADMIN, UserRole::TECNICO]),
        )->get();

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send($destinatarios, new HannaSyncFalhouNotification($erro));
    }

    /** Janela máxima de recuperação de dosagem quando o sync esteve em baixo. */
    private const DOSE_LOOKBACK_MAX_HORAS = 24;

    /**
     * Desconta dos bidões (cloro e pH-) o volume doseado que o controlador
     * reportou desde a última sincronização. O campo DV do deviceLogHistory dá
     * o volume por ciclo; somamos apenas os ciclos novos (dt > dose_sincronizada_ate)
     * para nunca contar duas vezes.
     */
    private function sincronizarDosagem(HannaDevice $device, HannaCloudService $hanna): void
    {
        // Primeira vez: fixa a baseline sem descontar dosagem anterior à feature.
        if ($device->dose_sincronizada_ate === null) {
            $device->update(['dose_sincronizada_ate' => now()]);

            return;
        }

        $desde = $device->dose_sincronizada_ate->copy();
        $limite = now()->subHours(self::DOSE_LOOKBACK_MAX_HORAS);

        if ($desde->lt($limite)) {
            $this->warn("  ⚠ {$device->name}: dosagem sem sync há mais de ".self::DOSE_LOOKBACK_MAX_HORAS.'h; a recuperar só as últimas '.self::DOSE_LOOKBACK_MAX_HORAS.'h.');
            $desde = $limite;
        }

        $leituras = $hanna->getHistoryReadings(
            $device->hanna_device_id,
            $desde,
            now(),
        );

        $novas = array_filter(
            $leituras,
            fn (array $l) => $l['dt'] !== null && Carbon::parse($l['dt'])->gt($device->dose_sincronizada_ate),
        );

        if (empty($novas)) {
            return;
        }

        $containerCloro = DosingContainer::firstOrCreate(
            ['pool_id' => $device->pool_id, 'tipo' => DosingContainer::TIPO_CLORO],
        );
        $containerPh = DosingContainer::firstOrCreate(
            ['pool_id' => $device->pool_id, 'tipo' => DosingContainer::TIPO_PH_MENOS],
        );

        $doseCloro = 0.0;
        $dosePh = 0.0;
        $ultimoDt = $device->dose_sincronizada_ate;

        foreach ($novas as $l) {
            $dt = Carbon::parse($l['dt']);

            if ($containerCloro->reabastecido_em === null || $dt->gt($containerCloro->reabastecido_em)) {
                $doseCloro += (float) ($l['dose_cloro_ml'] ?? 0);
            }

            if ($containerPh->reabastecido_em === null || $dt->gt($containerPh->reabastecido_em)) {
                $dosePh += (float) ($l['dose_ph_ml'] ?? 0);
            }

            if ($dt->gt($ultimoDt)) {
                $ultimoDt = $dt;
            }
        }

        $this->descontarBidao($device, DosingContainer::TIPO_CLORO, $doseCloro);
        $this->descontarBidao($device, DosingContainer::TIPO_PH_MENOS, $dosePh);

        $device->update(['dose_sincronizada_ate' => $ultimoDt]);
    }

    private function descontarBidao(HannaDevice $device, string $tipo, float $ml): void
    {
        // Validar plausibilidade: > 20L num ciclo é avaria, não dosagem legítima
        if ($ml <= 0 || $ml > 20000) {
            Log::warning("HannaCloudSync [{$device->hanna_device_id}]: dosagem de {$ml} mL implausível; ignorada.");

            return;
        }

        $container = DosingContainer::firstOrCreate(
            ['pool_id' => $device->pool_id, 'tipo' => $tipo],
        );

        $container->consumir($ml);
        $this->line("  ↓ {$device->name}: -".number_format($ml, 0, ',', '')." mL {$container->tipoLabel()}");

        $container->notificarSeBaixo();
    }

    /**
     * Validar que os valores estão dentro dos limites físicos plausíveis.
     * Fora destes limites, o valor é avaria de sonda, não leitura legítima.
     *
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>|null $reading sanitizado ou null se implausível
     */
    private function sanitizarLeitura(array $reading, HannaDevice $device): ?array
    {
        $limites = [
            'ph' => [0.0, 14.0],
            'orp' => [-2000.0, 2000.0],
            'temperatura_agua' => [-5.0, 60.0],
            'temperatura_ar' => [-30.0, 60.0],
            'caudal_ph' => [0.0, 100000.0],
            'caudal_cloro' => [0.0, 100000.0],
        ];

        foreach ($limites as $campo => [$min, $max]) {
            $valor = $reading[$campo] ?? null;
            if ($valor !== null && ((float) $valor < $min || (float) $valor > $max)) {
                Log::warning("HannaCloudSync [{$device->hanna_device_id}]: {$campo}={$valor} fora dos limites físicos [{$min}, {$max}]; descartado.");
                $reading[$campo] = null;
            }
        }

        return $reading;
    }

    /**
     * Leitura obtida durante uma lavagem/bomba parada é artefacto (a água não
     * circula no sensor) — não deve gerar alertas de pH.
     *
     * @param  array<string, mixed>  $reading
     */
    private function emArtefacto(HannaDevice $device, array $reading): bool
    {
        if ($device->pool_id === null) {
            return false;
        }

        $lida_em = $reading['dt'] ? Carbon::parse($reading['dt']) : now();

        return app(LeituraArtefactoService::class)->motivoEm((int) $device->pool_id, $lida_em) !== null;
    }

    /** @param array<string, mixed> $reading */
    private function notificarThresholds(HannaDevice $device, array $reading): void
    {
        if ($this->emArtefacto($device, $reading)) {
            return;
        }

        $violacoes = [];
        $ph = $reading['ph'] !== null ? (float) $reading['ph'] : null;

        if ($ph !== null && ($ph < DailyRecord::getPhMin() || $ph > DailyRecord::getPhMax())) {
            $fmt = number_format($ph, 1, ',', '');
            $violacoes[] = $ph < DailyRecord::getPhMin()
                ? "pH {$fmt} abaixo do mínimo (".number_format(DailyRecord::getPhMin(), 1, ',', '').')'
                : "pH {$fmt} acima do máximo (".number_format(DailyRecord::getPhMax(), 1, ',', '').')';
        }

        if (empty($violacoes)) {
            return;
        }

        $adminsETecnicos = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();
        Notification::send($adminsETecnicos, new HannaThresholdAlert($device, $violacoes));
    }

    /**
     * Rastreia se o pH está fora da banda proporcional do próprio controlador
     * (setpoint ± banda, campo DS). Enquanto se mantiver fora por mais tempo
     * que o "Overtime" configurado, notifica admins/técnicos uma única vez
     * por episódio — a mesma condição que a Hanna Cloud assinala como
     * "pH Overtime" no dashboard deles.
     *
     * @param  array<string, mixed>  $reading
     */
    private function atualizarPhOvertime(HannaDevice $device, array $reading): void
    {
        // Durante um artefacto (lavagem/bomba parada) o pH está falseado —
        // não alimentar a máquina de estados de overtime.
        if ($this->emArtefacto($device, $reading)) {
            return;
        }

        $ph = $reading['ph'] !== null ? (float) $reading['ph'] : null;
        $ds = $device->dosingSettings();

        if ($ph === null || $ds === null) {
            return;
        }

        // Verifica se a API reportou algum alarme de overtime
        $apiOvertime = false;
        foreach (['alarms', 'warnings', 'errors'] as $key) {
            if (! empty($reading[$key]) && is_array($reading[$key])) {
                foreach ($reading[$key] as $item) {
                    $itemStr = is_string($item) ? $item : json_encode($item);
                    if (stripos($itemStr, 'overtime') !== false) {
                        $apiOvertime = true;
                        break 2;
                    }
                }
            }
        }

        // Usar round para evitar problemas de precisão de floats
        $foraDaBanda = $apiOvertime || (round(abs($ph - $ds['setpoint']), 2) > round($ds['band'], 2));

        if (! $foraDaBanda) {
            // Uma única leitura a tocar a banda não limpa o episódio — só reseta
            // depois de 2 leituras seguidas dentro da banda, para não perder o
            // relógio por ruído pontual (a Hanna Cloud também não parece limpar
            // o alarme "pH Overtime" com uma leitura isolada).
            $anterior = $device->leituras()->latest('lida_em')->first();
            $anteriorDentroDaBanda = $anterior === null
                || $anterior->ph === null
                || round(abs((float) $anterior->ph - $ds['setpoint']), 2) <= round($ds['band'], 2);

            if (! $anteriorDentroDaBanda) {
                return;
            }

            if ($device->ph_out_of_band_since !== null || $device->ph_overtime_notified_at !== null) {
                $device->update(['ph_out_of_band_since' => null, 'ph_overtime_notified_at' => null]);
            }

            return;
        }

        // Recalcula sempre a partir do histórico (auto-corrige se um ciclo
        // anterior tiver gravado um "desde" desatualizado).
        $desde = $this->inicioForaDaBanda($device, $ds);

        // Se a API diz que está em overtime mas a leitura local não detectou ou o histórico é curto,
        // garantimos que $desde não é null e respeita a existência do alarme.
        if ($desde === null || $desde->isFuture()) {
            $desde = $reading['dt'] ? Carbon::parse($reading['dt']) : now();
        }

        if ($device->ph_out_of_band_since === null || ! $device->ph_out_of_band_since->equalTo($desde)) {
            $device->update(['ph_out_of_band_since' => $desde]);
        }

        $minutosDecorridos = $desde->diffInMinutes(now());

        // Se a API reporta overtime diretamente, forçamos o trigger do alerta mesmo que os minutos calculados
        // localmente sejam menores (por falta de histórico local, por exemplo).
        $forcarNotificacao = $apiOvertime && $device->ph_overtime_notified_at === null;

        if (($forcarNotificacao || $minutosDecorridos >= $ds['overtimeMinutes']) && $device->ph_overtime_notified_at === null) {
            $device->update(['ph_overtime_notified_at' => now()]);

            $adminsETecnicos = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();
            Notification::send($adminsETecnicos, new HannaOvertimeAlert($device, $ph, $ds));
        }
    }

    /**
     * Repete a mesma máquina de estados de atualizarPhOvertime() sobre o
     * histórico já guardado (2 leituras seguidas dentro da banda para
     * limpar o episódio) para encontrar o instante real em que o pH saiu
     * da banda. Sem isto, o relógio de overtime reiniciaria do zero só
     * porque esta funcionalidade acabou de ser lançada — a Hanna Cloud já
     * vinha a contar overtime há horas.
     *
     * @param  array{setpoint: float, band: float, overtimeMinutes: int}  $ds
     */
    private function inicioForaDaBanda(HannaDevice $device, array $ds): Carbon
    {
        $leituras = $device->leituras()
            ->latest('lida_em')
            ->limit(200)
            ->get(['ph', 'lida_em'])
            ->reverse();

        $desde = null;
        $consecutivoDentro = 0;

        foreach ($leituras as $leitura) {
            if ($leitura->ph === null) {
                continue;
            }

            // Usar round para evitar problemas de precisão de floats
            $dentroDaBanda = round(abs((float) $leitura->ph - $ds['setpoint']), 2) <= round($ds['band'], 2);

            if ($dentroDaBanda) {
                if (++$consecutivoDentro >= 2) {
                    $desde = null;
                }

                continue;
            }

            $consecutivoDentro = 0;
            $desde ??= $leitura->lida_em;
        }

        return $desde ?? now();
    }

    private function discover(HannaCloudService $hanna): int
    {
        try {
            $devices = $hanna->getDevices();
        } catch (\Throwable $e) {
            $this->error('Falha ao listar dispositivos: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($devices)) {
            $this->warn('Nenhum dispositivo BL12x/BL13x encontrado na conta.');

            return self::SUCCESS;
        }

        $this->table(
            ['DID', 'Nome', 'Modelo', 'Pool mapeada'],
            collect($devices)->map(fn ($d) => [
                $d['DID'],
                $d['name'] ?? '-',
                $d['DM'] ?? '-',
                HannaDevice::where('hanna_device_id', $d['DID'])->first()?->piscina?->name ?? '(não mapeado)',
            ])->toArray()
        );

        foreach ($devices as $device) {
            $existente = HannaDevice::where('hanna_device_id', $device['DID'])->first();

            if ($existente) {
                // Não forçar active=true: um admin pode ter desligado o sensor de
                // propósito; o discover atualiza metadados mas respeita esse estado.
                $existente->update([
                    'name' => $device['name'] ?? $device['DID'],
                    'raw_info' => $device,
                ]);
            } else {
                HannaDevice::create([
                    'hanna_device_id' => $device['DID'],
                    'name' => $device['name'] ?? $device['DID'],
                    'active' => true,
                    'raw_info' => $device,
                ]);
            }
        }

        $this->info(count($devices).' dispositivo(s) actualizados em hanna_devices.');

        // Dispositivos ativos que já não aparecem na conta: só avisa (não desativa
        // automaticamente — um discover parcial por glitch da API não deve desligar
        // sensores bons; a decisão de desativar fica com o admin).
        $didsDaConta = collect($devices)->pluck('DID')->all();
        $desaparecidos = HannaDevice::where('active', true)
            ->whereNotIn('hanna_device_id', $didsDaConta)
            ->get();

        if ($desaparecidos->isNotEmpty()) {
            $this->warn('Dispositivos ativos que já não constam na conta Hanna Cloud (verifica em Admin → Sensores Hanna):');
            foreach ($desaparecidos as $d) {
                $this->warn("  - {$d->name} ({$d->hanna_device_id})");
            }
        }

        $this->info('Mapeia cada dispositivo a uma piscina em Admin → Sensores Hanna.');

        return self::SUCCESS;
    }
}
