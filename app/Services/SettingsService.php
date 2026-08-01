<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SettingsService
{
    protected static ?array $settings = null;

    public function all(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        // Cache settings for 15 minutes
        self::$settings = Cache::remember('app_settings_all', 900, function () {
            try {
                return AppSetting::all()->pluck('value', 'key')->toArray();
            } catch (QueryException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'does not exist') || str_contains($msg, 'no such table')) {
                    return [];
                }
                Log::error('SettingsService database query failed', ['err' => $msg]);

                return [];
            }
        });

        return self::$settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        // Um valor vazio guardado na BD não pode substituir o default: era assim
        // que um Guardar em Definições com campos em branco punha os limites
        // regulamentares a zero ('' não dispara o ?? do default).
        if ($value === null || $value === '' || $value === []) {
            return $default;
        }

        return $value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function set(string $key, mixed $value, string $group = 'geral', string $type = 'string'): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'group' => $group,
                'label' => ucwords(str_replace('_', ' ', $key)),
                'type' => $type,
            ]
        );
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget('app_settings_all');
        self::$settings = null;
    }
}
