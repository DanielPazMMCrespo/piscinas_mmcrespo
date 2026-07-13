<?php declare(strict_types=1);
namespace App\Services;

use App\Models\HannaDevice;
use App\Models\SensorReading;
use App\Models\User;
use Filament\Notifications\Notification;

class HannaThresholdService
{
    /**
     * Valida uma leitura contra thresholds e notifica admins/técnicos se houver violação.
     * Thresholds baseados em CN 14/DA (pH 6.5-7.5) + padrões de ORP/Temperatura.
     *
     * @return array{violations: array, message: string}
     */
    public function validateReading(SensorReading $reading, HannaDevice $device): array
    {
        $violations = [];
        $parts = [];

        // Threshold de pH: CN 14/DA (6.5-7.5)
        if ($reading->ph !== null) {
            $min = 6.5;
            $max = 7.5;
            if ($reading->ph < $min || $reading->ph > $max) {
                $violations['ph'] = $reading->ph;
                $parts[] = "pH {$reading->ph} (fora de {$min}-{$max})";
            }
        }

        // Threshold de ORP: 600-800 mV (padrão em piscinas públicas)
        if ($reading->orp !== null) {
            $min = 600;
            $max = 800;
            if ($reading->orp < $min || $reading->orp > $max) {
                $violations['orp'] = $reading->orp;
                $parts[] = "ORP {$reading->orp} mV (fora de {$min}-{$max})";
            }
        }

        // Threshold de Temperatura: baseado na piscina
        if ($reading->temperatura_agua !== null && $device->pool) {
            $min = (float) $device->pool->temp_min;
            $max = (float) $device->pool->temp_max;
            if ($reading->temperatura_agua < $min || $reading->temperatura_agua > $max) {
                $violations['temperatura_agua'] = $reading->temperatura_agua;
                $parts[] = "T {$reading->temperatura_agua}°C (fora de {$min}-{$max})";
            }
        }

        if (empty($violations)) {
            return ['violations' => [], 'message' => ''];
        }

        // Se há violações, notifica os admins/técnicos
        $poolName = $device->pool
            ? $device->pool->nome_completo
            : 'piscina desconhecida';

        $message = $poolName.' — '.implode(', ', $parts);

        $recipients = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'tecnico']))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::make()
                ->warning()
                ->title('Alerta de sensor: parâmetro fora do limite')
                ->body($message)
                ->sendToDatabase($recipients);
        }

        return [
            'violations' => $violations,
            'message' => $message,
        ];
    }
}
