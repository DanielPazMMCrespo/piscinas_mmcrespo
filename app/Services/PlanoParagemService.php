<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\TrabalhoParagem;
use App\Models\OperationalAction;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gestão do plano de trabalhos de paragem técnica e único escritor
 * do estado das tarefas (PoolClosureTask).
 *
 * [AI_CONTEXT]
 * - Nunca alterar o estado de uma tarefa diretamente no RelationManager:
 *   usar sempre os métodos deste serviço para garantir invariantes legais e auditoria.
 * - Criação do plano é uma ação explícita e manual por encerramento, nunca automática.
 */
class PlanoParagemService
{
    /**
     * Cria o plano de trabalhos inicial a partir do template canónico.
     *
     * @return Collection<int, PoolClosureTask>
     *
     * @throws DomainException Se a paragem técnica já tiver um plano criado.
     */
    public function criarPlano(PoolClosure $encerramento, User $utilizador): Collection
    {
        if ($encerramento->trabalhos()->exists()) {
            throw new DomainException('A paragem técnica já tem um plano de trabalhos criado.');
        }

        return DB::transaction(function () use ($encerramento): Collection {
            $tarefas = collect();

            foreach (TrabalhoParagem::template() as $item) {
                $tarefa = $encerramento->trabalhos()->create([
                    'tipo' => $item['tipo'],
                    'ordem' => $item['ordem'],
                    'obrigatorio' => $item['obrigatorio'],
                    'estado' => TrabalhoParagem::ESTADO_PREVISTO,
                    'origem' => TrabalhoParagem::ORIGEM_DECLARADA,
                    'previsto_para' => null,
                    'executado_em' => null,
                    'executado_por' => null,
                    'motivo_nao_execucao' => null,
                    'dados' => null,
                    'fotos' => null,
                    'videos' => null,
                    'documentos' => null,
                    'observacoes' => null,
                ]);

                $tarefas->push($tarefa);
            }

            return $tarefas;
        });
    }

    /**
     * Acrescenta um trabalho avulso ou fora do template ao plano de trabalhos.
     *
     * @param  array{
     *     tipo?: string,
     *     obrigatorio?: bool,
     *     previsto_para?: mixed,
     *     observacoes?: ?string,
     *     dados?: ?array<string, mixed>,
     *     fotos?: ?array<int, string>,
     *     videos?: ?array<int, string>,
     *     documentos?: ?array<int, string>
     * }  $dados
     *
     * @throws DomainException Se o tipo for inválido.
     */
    public function acrescentarTrabalho(PoolClosure $encerramento, array $dados, User $utilizador): PoolClosureTask
    {
        $tipo = (string) ($dados['tipo'] ?? TrabalhoParagem::OUTRO);
        if (! TrabalhoParagem::isValid($tipo)) {
            throw new DomainException('Tipo de trabalho inválido.');
        }

        $maxOrdem = (int) ($encerramento->trabalhos()->max('ordem') ?? 0);
        $ordem = $maxOrdem + 1;

        $previstoPara = filled($dados['previsto_para'] ?? null)
            ? Carbon::parse((string) $dados['previsto_para'])->toDateString()
            : null;

        /** @var PoolClosureTask $tarefa */
        $tarefa = $encerramento->trabalhos()->create([
            'tipo' => $tipo,
            'ordem' => $ordem,
            'obrigatorio' => (bool) ($dados['obrigatorio'] ?? false),
            'estado' => TrabalhoParagem::ESTADO_PREVISTO,
            'origem' => TrabalhoParagem::ORIGEM_DECLARADA,
            'previsto_para' => $previstoPara,
            'executado_em' => null,
            'executado_por' => null,
            'motivo_nao_execucao' => null,
            'dados' => $dados['dados'] ?? null,
            'fotos' => $dados['fotos'] ?? null,
            'videos' => $dados['videos'] ?? null,
            'documentos' => $dados['documentos'] ?? null,
            'observacoes' => $dados['observacoes'] ?? null,
        ]);

        return $tarefa;
    }

    /**
     * Marca uma tarefa como executada, registando autor, data/hora e evidências.
     *
     * @param  array{
     *     executado_em?: mixed,
     *     dados?: ?array<string, mixed>,
     *     fotos?: ?array<int, string>,
     *     videos?: ?array<int, string>,
     *     documentos?: ?array<int, string>,
     *     observacoes?: ?string,
     *     origem?: string
     * }  $dados
     *
     * @throws DomainException Se documentos obrigatórios estiverem em falta (ex: boletim Legionella).
     */
    public function marcarExecutado(PoolClosureTask $tarefa, array $dados, User $utilizador): PoolClosureTask
    {
        if ($tarefa->tipo === TrabalhoParagem::DESINFECAO_LEGIONELLA) {
            $documentos = array_key_exists('documentos', $dados)
                ? $dados['documentos']
                : $tarefa->documentos;

            $temDocs = is_array($documentos)
                ? count(array_filter($documentos)) > 0
                : filled($documentos);

            if (! $temDocs) {
                throw new DomainException('A desinfeção de Legionella exige o carregamento do boletim analítico acreditado.');
            }
        }

        $executadoEm = filled($dados['executado_em'] ?? null)
            ? Carbon::parse((string) $dados['executado_em'])
            : now();

        $origem = (string) ($dados['origem'] ?? $tarefa->origem ?? TrabalhoParagem::ORIGEM_DECLARADA);
        if (! in_array($origem, TrabalhoParagem::origens(), true)) {
            $origem = TrabalhoParagem::ORIGEM_DECLARADA;
        }

        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => $executadoEm,
            'executado_por' => $utilizador->id,
            'motivo_nao_execucao' => null,
            'origem' => $origem,
            'dados' => array_key_exists('dados', $dados) ? $dados['dados'] : $tarefa->dados,
            'fotos' => array_key_exists('fotos', $dados) ? $dados['fotos'] : $tarefa->fotos,
            'videos' => array_key_exists('videos', $dados) ? $dados['videos'] : $tarefa->videos,
            'documentos' => array_key_exists('documentos', $dados) ? $dados['documentos'] : $tarefa->documentos,
            'observacoes' => array_key_exists('observacoes', $dados) ? $dados['observacoes'] : $tarefa->observacoes,
        ]);

        /** @var PoolClosureTask $refreshed */
        $refreshed = $tarefa->fresh();

        return $refreshed;
    }

    /**
     * Marca uma tarefa como não executada, exigindo justificação obrigatória.
     *
     * @throws DomainException Se o motivo for vazio.
     */
    public function marcarNaoExecutado(PoolClosureTask $tarefa, string $motivo, User $utilizador): PoolClosureTask
    {
        $motivoFormatado = trim($motivo);
        if ($motivoFormatado === '') {
            throw new DomainException('O motivo de não execução é obrigatório.');
        }

        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_NAO_EXECUTADO,
            'motivo_nao_execucao' => $motivoFormatado,
            'executado_em' => null,
            'executado_por' => null,
        ]);

        /** @var PoolClosureTask $refreshed */
        $refreshed = $tarefa->fresh();

        return $refreshed;
    }

    /**
     * Marca uma tarefa como não aplicável a esta instalação, exigindo fundamentação técnica.
     *
     * @throws DomainException Se a justificação for vazia.
     */
    public function marcarNaoAplicavel(PoolClosureTask $tarefa, string $justificacao, User $utilizador): PoolClosureTask
    {
        $justificacaoFormatada = trim($justificacao);
        if ($justificacaoFormatada === '') {
            throw new DomainException('A justificação de não aplicabilidade é obrigatória.');
        }

        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_NAO_APLICAVEL,
            'motivo_nao_execucao' => $justificacaoFormatada,
            'executado_em' => null,
            'executado_por' => null,
        ]);

        /** @var PoolClosureTask $refreshed */
        $refreshed = $tarefa->fresh();

        return $refreshed;
    }

    /**
     * Retorna o resumo do estado de execução do plano de trabalhos.
     *
     * @return array{
     *     total: int,
     *     executados: int,
     *     obrigatorios_em_falta: int,
     *     pendentes: Collection<int, PoolClosureTask>
     * }
     */
    public function resumo(PoolClosure $encerramento): array
    {
        /** @var Collection<int, PoolClosureTask> $trabalhos */
        $trabalhos = $encerramento->relationLoaded('trabalhos')
            ? $encerramento->trabalhos
            : $encerramento->trabalhos()->get();

        $total = $trabalhos->count();
        $executados = $trabalhos->where('estado', TrabalhoParagem::ESTADO_EXECUTADO)->count();

        $obrigatoriosEmFalta = $trabalhos->filter(
            fn (PoolClosureTask $t) => (bool) $t->obrigatorio && ! in_array($t->estado, [
                TrabalhoParagem::ESTADO_EXECUTADO,
                TrabalhoParagem::ESTADO_NAO_APLICAVEL,
            ], true)
        )->count();

        $pendentes = $trabalhos->filter(
            fn (PoolClosureTask $t) => in_array($t->estado, [
                TrabalhoParagem::ESTADO_PREVISTO,
                TrabalhoParagem::ESTADO_EM_CURSO,
            ], true)
        )->values();

        return [
            'total' => $total,
            'executados' => $executados,
            'obrigatorios_em_falta' => $obrigatoriosEmFalta,
            'pendentes' => $pendentes,
        ];
    }

    /**
     * Obtém as ações operacionais registadas no intervalo da paragem técnica (Anexo A).
     *
     * @return Collection<int, OperationalAction>
     */
    public function evidenciaOperacional(PoolClosure $encerramento): Collection
    {
        $inicio = $encerramento->inicio->copy()->startOfDay();
        $fim = ($encerramento->fim ? $encerramento->fim->copy() : now())->endOfDay();

        return OperationalAction::query()
            ->where('pool_id', $encerramento->pool_id)
            ->whereBetween('registado_em', [$inicio, $fim])
            ->orderBy('registado_em')
            ->get();
    }
}
