<?php declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

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
            } catch (\Throwable $e) {
                // If table doesn't exist yet (e.g. during migration/testing setup)
                return [];
            }
        });

        return self::$settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
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

    public function flush(): void
    {
        Cache::forget('app_settings_all');
        self::$settings = null;
    }
}
