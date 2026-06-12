<?php

namespace App\Console\Commands;

use App\Models\HannaDevice;
use App\Models\SensorReading;
use App\Services\HannaCloudService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
            $this->error('HANNA_EMAIL e HANNA_PASSWORD não estão definidos no .env');

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

        $sincronizados = 0;

        foreach ($devices as $device) {
            try {
                $reading = $hanna->getLastReading($device->hanna_device_id);

                $lida_em = $reading['dt']
                    ? Carbon::parse($reading['dt'])
                    : now();

                // Evita duplicados: mesmo device + mesmo timestamp
                $existe = SensorReading::where('hanna_device_id', $device->hanna_device_id)
                    ->where('lida_em', $lida_em)
                    ->exists();

                if (! $existe) {
                    SensorReading::create([
                        'pool_id' => $device->pool_id,
                        'hanna_device_id' => $device->hanna_device_id,
                        'lida_em' => $lida_em,
                        'ph' => $reading['ph'],
                        'orp' => $reading['orp'],
                        'temperatura_agua' => $reading['temperatura_agua'],
                        'temperatura_ar' => $reading['temperatura_ar'],
                        'caudal_ph' => $reading['caudal_ph'],
                        'caudal_cloro' => $reading['caudal_cloro'],
                        'raw_parameters' => $reading['raw_parameters'],
                    ]);
                    $sincronizados++;
                    $this->line("  ✓ {$device->name}: pH={$reading['ph']} ORP={$reading['orp']}mV T={$reading['temperatura_agua']}°C");
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
