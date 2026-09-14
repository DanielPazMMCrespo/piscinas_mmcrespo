<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A migração 2026_09_10_000002 copiou cada `analise_pontual` de
 * `operational_actions` para `daily_records` e deixou a linha de origem de pé.
 * Ficaram duas cópias da mesma medição, e tudo o que junta as duas tabelas
 * passou a contar a leitura a dobrar: o livro sanitário imprimia duas linhas
 * seguidas com o mesmo técnico e o mesmo carimbo, o gráfico do
 * PainelPiscinasWidget gastava dois dos seus 15 pontos na mesma medição
 * (empurrando história real para fora da janela) e o CorrelacaoOrpCloroService
 * correlacionava ORP com cloro duplicado.
 *
 * Apaga-se a origem em vez de se filtrar em cada consumidor: um predicado num
 * sítio, e não um guard por cada ecrã que venha a ler as duas tabelas.
 *
 * Só apaga a linha cujo par existe mesmo em `daily_records` — o conteúdo da
 * medição fica preservado lá. Se a 2026_09_10_000002 nunca correu neste
 * ambiente, não há pares e esta migração não apaga nada.
 *
 * O `registado_em` sozinho não chega para provar que é uma cópia: os dois
 * lados gravam os segundos a zero (DailyRecordService::setTime(..., 0) e
 * DateTimePicker->seconds(false)), por isso a igualdade é ao minuto. Uma
 * análise pontual feita no mesmo minuto do registo diário da mesma piscina
 * são duas medições diferentes, e apagar a primeira perdia-a para sempre. O
 * `created_at` desempata: a 2026_09_10_000002 copiou-o tal e qual da ação de
 * origem, enquanto um registo diário coincidente tem o instante da sua própria
 * submissão. Se o par não bater, a linha fica — o pior caso passa a ser uma
 * duplicação visível, nunca uma medição perdida em silêncio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $apagadas = DB::table('operational_actions')
            ->where('tipo', 'analise_pontual')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('daily_records')
                    ->whereColumn('daily_records.pool_id', 'operational_actions.pool_id')
                    ->whereColumn('daily_records.registado_em', 'operational_actions.registado_em')
                    ->whereColumn('daily_records.created_at', 'operational_actions.created_at');
            })
            ->delete();

        // O delete é em query builder, logo não passa pelo observer do
        // activitylog: sem esta linha, linhas de uma tabela de domínio legal
        // desapareciam sem deixar rasto nenhum no trilho de auditoria.
        if ($apagadas > 0) {
            activity('sistema')->log(
                "Migração 2026_09_11_000001: apagadas {$apagadas} ações analise_pontual já copiadas para daily_records."
            );
        }
    }

    public function down(): void
    {
        // As medições apagadas continuam em daily_records; recriar a
        // OperationalAction devolveria a duplicação que isto veio resolver.
    }
};
