<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migrar histórico de analise_pontual para daily_records
        $actions = DB::table('operational_actions')
            ->where('tipo', 'analise_pontual')
            ->get();

        foreach ($actions as $action) {
            $dados = json_decode($action->dados ?? '{}', true) ?: [];
            $registadoEm = $action->registado_em ? Carbon::parse($action->registado_em) : now();

            DB::table('daily_records')->insert([
                'pool_id' => $action->pool_id,
                'user_id' => $action->user_id,
                'registado_em' => $registadoEm,
                'hora_colheita' => $registadoEm->format('H:i'),
                'ph' => $dados['ph'] ?? null,
                'cloro_livre' => $dados['cloro_livre'] ?? null,
                'cloro_total' => $dados['cloro_total'] ?? null,
                'temperatura' => $dados['temperatura'] ?? null,
                'orp' => isset($dados['orp']) ? (int) $dados['orp'] : null,
                'observacoes' => $action->observacoes,
                'analises_fotos' => ! empty($action->foto) ? json_encode([$action->foto]) : null,
                'created_at' => $action->created_at ?? $registadoEm,
                'updated_at' => $action->updated_at ?? $registadoEm,
            ]);
        }
    }

    public function down(): void
    {
        // Não é necessário reverter dados históricos migrados
    }
};
