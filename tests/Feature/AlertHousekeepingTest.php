<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AlertState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AlertHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_states_older_than_seven_days_and_keeps_recent(): void
    {
        $user = User::factory()->create();

        $antigo = AlertState::create([
            'alert_key' => 'fora_limites|1|2020-01-01',
            'status' => 'resolvido',
            'moved_by' => $user->id,
            'moved_at' => now()->subDays(10),
        ]);

        $recente = AlertState::create([
            'alert_key' => 'fora_limites|2|2020-01-02',
            'status' => 'pendente',
            'moved_by' => $user->id,
            'moved_at' => now()->subDays(2),
        ]);

        Artisan::call('alerts:housekeeping');

        $this->assertDatabaseMissing('alert_states', ['id' => $antigo->id]);
        $this->assertDatabaseHas('alert_states', ['id' => $recente->id]);
    }
}
