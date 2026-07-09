<?php declare(strict_types=1);
namespace App\Console\Commands;


use App\Models\HannaDevice;
use App\Models\SensorReading;
use App\Models\User;
use App\Notifications\HannaOvertimeAlert;
use App\Notifications\HannaThresholdAlert;
use App\Services\HannaCloudService;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
        } catch (\Throwable $e) {
            $this->error('Falha na autenticação: '.$e->getMessage());
            Log::error('HannaCloudSync auth: '.$e->getMessage());

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

        foreach ($devices as $device) {
            try {
                $reading = \App\Services\HannaCircuitBreaker::execute(
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
                            throw new \App\Exceptions\SensorCommunicationException(
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
            }
        }

        $this->info("Sync concluído: {$sincronizados} leitura(s) novas.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $reading */
    private function notificarThresholds(HannaDevice $device, array $reading): void
    {
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
     * @param array<string, mixed> $reading
     */
    private function atualizarPhOvertime(HannaDevice $device, array $reading): void
    {
        $ph = $reading['ph'] !== null ? (float) $reading['ph'] : null;
        $ds = $device->dosingSettings();

        if ($ph === null || $ds === null) {
            return;
        }

        $foraDaBanda = abs($ph - $ds['setpoint']) > $ds['band'];

        if (! $foraDaBanda) {
            // Uma única leitura a tocar a banda não limpa o episódio — só reseta
            // depois de 2 leituras seguidas dentro da banda, para não perder o
            // relógio por ruído pontual (a Hanna Cloud também não parece limpar
            // o alarme "pH Overtime" com uma leitura isolada).
            $anterior = $device->leituras()->latest('lida_em')->first();
            $anteriorDentroDaBanda = $anterior === null
                || $anterior->ph === null
                || abs((float) $anterior->ph - $ds['setpoint']) <= $ds['band'];

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

        if ($device->ph_out_of_band_since === null || ! $device->ph_out_of_band_since->equalTo($desde)) {
            $device->update(['ph_out_of_band_since' => $desde]);
        }

        $minutosDecorridos = $desde->diffInMinutes(now());

        if ($minutosDecorridos >= $ds['overtimeMinutes'] && $device->ph_overtime_notified_at === null) {
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
     * @param array{setpoint: float, band: float, overtimeMinutes: int} $ds
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

            $dentroDaBanda = abs((float) $leitura->ph - $ds['setpoint']) <= $ds['band'];

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
            HannaDevice::updateOrCreate(
                ['hanna_device_id' => $device['DID']],
                [
                    'name' => $device['name'] ?? $device['DID'],
                    'active' => true,
                    'raw_info' => $device,
                ]
            );
        }

        $this->info(count($devices).' dispositivo(s) actualizados em hanna_devices.');
        $this->info('Mapeia cada dispositivo a uma piscina em Admin → Sensores Hanna.');

        return self::SUCCESS;
    }
}
