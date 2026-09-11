<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Services\LimitesLegaisService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Modo Inspeção Sanitária DGS (Circular Normativa 14/DA).
 *
 * Ecrã de leitura limpo, otimizado para tablets e smartphones, desenhado para
 * entrega ao Delegado de Saúde Pública ou auditor sanitário:
 * - Selo de conformidade global (% de análises conformes CN 14/DA)
 * - Tabela cronológica imutável com todas as medições obrigatórias
 * - Registo de lavagens de filtro e renovações de água
 * - Acesso direto ao Livro Sanitário Oficial em PDF
 */
class InspecaoDgs extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Gestão';

    protected static ?string $navigationLabel = 'Inspeção DGS';

    protected static ?string $title = 'Modo Inspeção Sanitária (CN 14/DA DGS)';

    protected static ?string $slug = 'inspecao-dgs';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.inspecao-dgs';

    public ?int $installation_id = null;

    public ?string $pool_id = 'todas';

    public string $periodo = '30d';

    public string $data_inicio;

    public string $data_fim;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function mount(): void
    {
        $primeiraInstalacao = Installation::query()->where('active', true)->orderBy('name')->first();
        $this->installation_id = $primeiraInstalacao?->id;

        $this->setPeriodo('30d');
    }

    public function setPeriodo(string $tipo): void
    {
        $this->periodo = $tipo;

        switch ($tipo) {
            case 'este_mes':
                $this->data_inicio = Carbon::now()->startOfMonth()->toDateString();
                $this->data_fim = Carbon::now()->toDateString();
                break;

            case 'mes_anterior':
                $this->data_inicio = Carbon::now()->subMonth()->startOfMonth()->toDateString();
                $this->data_fim = Carbon::now()->subMonth()->endOfMonth()->toDateString();
                break;

            case '7d':
                $this->data_inicio = Carbon::now()->subDays(6)->toDateString();
                $this->data_fim = Carbon::now()->toDateString();
                break;

            case '30d':
            default:
                $this->data_inicio = Carbon::now()->subDays(29)->toDateString();
                $this->data_fim = Carbon::now()->toDateString();
                break;
        }
    }

    public function updatedInstallationId(): void
    {
        $this->pool_id = 'todas';
    }

    /**
     * @return Collection<int, Installation>
     */
    public function getInstalacoesProperty(): Collection
    {
        return Installation::query()->where('active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Pool>
     */
    public function getPiscinasProperty(): Collection
    {
        if (! $this->installation_id) {
            return collect();
        }

        return Pool::query()
            ->where('installation_id', $this->installation_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuditoriaProperty(): array
    {
        if (! $this->installation_id) {
            return [
                'valido' => false,
                'mensagem' => 'Selecione uma instalação.',
                'piscinas' => [],
            ];
        }

        $inicio = Carbon::parse($this->data_inicio)->startOfDay();
        $fim = Carbon::parse($this->data_fim)->endOfDay();

        $piscinasQuery = Pool::query()
            ->where('installation_id', $this->installation_id)
            ->where('active', true);

        if ($this->pool_id && $this->pool_id !== 'todas') {
            $piscinasQuery->where('id', (int) $this->pool_id);
        }

        $piscinas = $piscinasQuery->orderBy('name')->get();

        if ($piscinas->isEmpty()) {
            return [
                'valido' => false,
                'mensagem' => 'Sem piscinas configuradas para esta seleção.',
                'piscinas' => [],
            ];
        }

        $poolIds = $piscinas->pluck('id')->all();

        // Registos diários válidos (exclui os que sofreram correção)
        $registos = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->whereBetween('registado_em', [$inicio, $fim])
            ->whereDoesntHave('correcoes')
            ->with(['user:id,name', 'piscina'])
            ->orderByDesc('registado_em')
            ->orderByDesc('hora_colheita')
            ->get();

        // Lavagens de filtro operacionais no período
        $lavagensAcoes = OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->where('tipo', OperationalAction::TIPO_LAVAGEM_FILTRO)
            ->whereBetween('registado_em', [$inicio, $fim])
            ->get();

        // Encerramentos que intersetam o período
        $encerramentos = PoolClosure::query()
            ->whereIn('pool_id', $poolIds)
            ->queIntersetam($inicio, $fim)
            ->get();

        $totalRegistos = $registos->count();
        $totalViolacoes = 0;
        $totalLavagens = $lavagensAcoes->count();

        // Contabiliza lavagens no formulário diário
        foreach ($registos as $registo) {
            if (! $registo->conformeComLimitesDGS()) {
                $totalViolacoes++;
            }
            if ($registo->filtro_faz_retrolavagem) {
                $totalLavagens += max(1, (int) $registo->numero_lavagens_filtro);
            }
        }

        $taxaConformidade = $totalRegistos > 0
            ? round((($totalRegistos - $totalViolacoes) / $totalRegistos) * 100, 1)
            : 100.0;

        $registosFormatados = $registos->map(function (DailyRecord $r): array {
            $data = $r->registado_em;
            $ph = $r->ph_efetivo;
            $clLivre = $r->cloro_livre_efetivo;
            $clTotal = $r->cloro_total_efetivo;
            $temp = $r->temperatura_efetivo;
            $turb = $r->transparencia;

            $bandaLivre = LimitesLegaisService::bandaCloroLivre($ph !== null ? (float) $ph : null, $data);
            $combMax = LimitesLegaisService::cloroCombinadoMax($data);
            $turbMax = LimitesLegaisService::transparenciaMax($data);

            $phOk = $ph !== null ? ($ph >= DailyRecord::getPhMin() && $ph <= DailyRecord::getPhMax()) : null;
            $clLivreOk = $clLivre !== null ? ($clLivre >= $bandaLivre['min'] && $clLivre <= $bandaLivre['max']) : null;
            $clComb = $r->cloro_combinado;
            $clCombOk = $clComb !== null ? ($clComb >= 0 && $clComb <= $combMax) : null;
            $turbOk = $turb !== null ? ((float) $turb <= $turbMax) : null;

            $conformeGeral = $r->conformeComLimitesDGS();

            return [
                'id' => $r->id,
                'piscina_nome' => $r->piscina?->name ?? 'Piscina',
                'data' => $data ? $data->format('d/m/Y') : '—',
                'hora' => $r->hora_colheita ? Carbon::parse($r->hora_colheita)->format('H:i') : ($data ? $data->format('H:i') : '—'),
                'tecnico' => $r->user?->name ?? 'Técnico',
                'ph' => $ph !== null ? number_format((float) $ph, 2, ',', '') : '—',
                'ph_ok' => $phOk,
                'cloro_livre' => $clLivre !== null ? number_format((float) $clLivre, 2, ',', '') : '—',
                'cloro_livre_ok' => $clLivreOk,
                'cloro_total' => $clTotal !== null ? number_format((float) $clTotal, 2, ',', '') : '—',
                'cloro_combinado' => $clComb !== null ? number_format((float) $clComb, 2, ',', '') : '—',
                'cloro_combinado_ok' => $clCombOk,
                'temperatura' => $temp !== null ? number_format((float) $temp, 1, ',', '').' °C' : '—',
                'turbidez' => $turb !== null ? number_format((float) $turb, 2, ',', '') : '—',
                'turbidez_ok' => $turbOk,
                'renovacao_agua' => $r->renovacao_agua ? 'Sim' : '—',
                'lavagem_filtro' => $r->filtro_faz_retrolavagem ? 'Sim' : '—',
                'observacoes' => $r->observacoes,
                'conforme' => $conformeGeral,
            ];
        });

        return [
            'valido' => true,
            'instalacao' => Installation::find($this->installation_id)?->name ?? 'Instalação',
            'periodo_label' => $inicio->format('d/m/Y').' a '.$fim->format('d/m/Y'),
            'total_registos' => $totalRegistos,
            'total_violacoes' => $totalViolacoes,
            'total_lavagens' => $totalLavagens,
            'taxa_conformidade' => $taxaConformidade,
            'encerramentos_count' => $encerramentos->count(),
            'registos' => $registosFormatados,
        ];
    }
}
