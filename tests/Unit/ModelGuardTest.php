<?php declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ModelGuardTest extends TestCase
{
    public function test_no_model_has_open_guarded(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (class_exists($class) && method_exists($class, 'getGuarded')) {
                $this->assertNotEquals([''], (new $class)->getGuarded(), "$class has open guarded");
            }
        }
    }
}
