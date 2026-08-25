<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Constants\TrabalhoParagem;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PoolClosureTask>
 */
class PoolClosureTaskFactory extends Factory
{
    protected $model = PoolClosureTask::class;

    public function definition(): array
    {
        return [
            'pool_closure_id' => PoolClosure::factory(),
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE,
            'ordem' => 1,
            'obrigatorio' => true,
            'estado' => TrabalhoParagem::ESTADO_PREVISTO,
            'origem' => TrabalhoParagem::ORIGEM_DECLARADA,
            'previsto_para' => null,
            'executado_em' => null,
            'executado_por' => null,
            'motivo_nao_execucao' => null,
            'dados' => null,
            'fotos' => null,
            'documentos' => null,
            'observacoes' => null,
        ];
    }

    public function executado(?CarbonInterface $data = null, ?User $user = null, ?array $dados = null): self
    {
        return $this->state(fn () => [
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => $data ? Carbon::instance($data) : Carbon::now(),
            'executado_por' => $user !== null ? $user->id : User::factory(),
            'dados' => $dados,
        ]);
    }

    public function naoExecutado(string $motivo = 'Impossibilidade operacional'): self
    {
        return $this->state(fn () => [
            'estado' => TrabalhoParagem::ESTADO_NAO_EXECUTADO,
            'motivo_nao_execucao' => $motivo,
        ]);
    }

    public function naoAplicavel(string $justificacao = 'Não aplicável a esta piscina'): self
    {
        return $this->state(fn () => [
            'estado' => TrabalhoParagem::ESTADO_NAO_APLICAVEL,
            'motivo_nao_execucao' => $justificacao,
        ]);
    }

    public function reconstruida(): self
    {
        return $this->state(fn () => [
            'origem' => TrabalhoParagem::ORIGEM_RECONSTRUIDA,
        ]);
    }

    public function inferida(): self
    {
        return $this->state(fn () => [
            'origem' => TrabalhoParagem::ORIGEM_INFERIDA,
        ]);
    }

    public function tipo(string $tipo, ?int $ordem = null, ?bool $obrigatorio = null): self
    {
        return $this->state(fn () => [
            'tipo' => $tipo,
            'ordem' => $ordem ?? 1,
            'obrigatorio' => $obrigatorio ?? TrabalhoParagem::isObrigatorio($tipo),
        ]);
    }
}
