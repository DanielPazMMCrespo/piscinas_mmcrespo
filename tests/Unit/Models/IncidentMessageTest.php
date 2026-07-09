<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_belongs_to_incident_and_author(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create(['name' => 'Ana']);
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $mensagem = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'A situação está a agravar-se.',
        ]);

        $this->assertTrue($mensagem->incidente->is($incidente));
        $this->assertTrue($mensagem->autor->is($user));
        $this->assertFalse($mensagem->eSistema());
    }

    public function test_incident_lists_messages_ordered_by_created_at(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create();
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'outro',
            'descricao' => 'Problema qualquer',
            'status' => 'aberto',
        ]);

        $primeira = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_SISTEMA,
            'texto' => 'Incidente reportado: Problema qualquer',
        ]);
        $segunda = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'A caminho.',
        ]);

        $ids = $incidente->mensagens()->pluck('id')->all();

        $this->assertSame([$primeira->id, $segunda->id], $ids);
    }
}
