<?php

namespace Tests\Feature;

use App\Filament\Widgets\CloroPhChartWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WidgetRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_renders_without_errors(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);

        Livewire::test(CloroPhChartWidget::class)
            ->assertSuccessful();
    }
}
