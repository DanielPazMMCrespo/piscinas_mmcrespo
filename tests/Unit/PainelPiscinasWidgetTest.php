<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Widgets\PainelPiscinasWidget;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PainelPiscinasWidgetTest extends TestCase
{
    private function conforme(bool $registoHoje, bool $registoOk, bool $controladorUsavel, bool $controladorOk): bool
    {
        $method = new ReflectionMethod(PainelPiscinasWidget::class, 'piscinaConforme');
        $method->setAccessible(true);

        return $method->invoke(null, $registoHoje, $registoOk, $controladorUsavel, $controladorOk);
    }

    public function test_registo_hoje_e_controlador_exigem_ambos_conformes(): void
    {
        $this->assertTrue($this->conforme(registoHoje: true, registoOk: true, controladorUsavel: true, controladorOk: true));
        $this->assertFalse($this->conforme(registoHoje: true, registoOk: true, controladorUsavel: true, controladorOk: false));
        $this->assertFalse($this->conforme(registoHoje: true, registoOk: false, controladorUsavel: true, controladorOk: true));
    }

    public function test_sem_registo_hoje_decide_so_o_controlador(): void
    {
        $this->assertTrue($this->conforme(registoHoje: false, registoOk: false, controladorUsavel: true, controladorOk: true));
        $this->assertFalse($this->conforme(registoHoje: false, registoOk: true, controladorUsavel: true, controladorOk: false));
    }

    public function test_sem_controlador_utilizavel_decide_so_o_registo_de_hoje(): void
    {
        $this->assertTrue($this->conforme(registoHoje: true, registoOk: true, controladorUsavel: false, controladorOk: false));
        $this->assertFalse($this->conforme(registoHoje: true, registoOk: false, controladorUsavel: false, controladorOk: false));
    }

    public function test_sem_qualquer_fonte_atual_nao_e_conforme(): void
    {
        $this->assertFalse($this->conforme(registoHoje: false, registoOk: false, controladorUsavel: false, controladorOk: false));
    }
}
