<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\TrabalhoParagem;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Item de trabalho / obrigação de uma Paragem Técnica (PoolClosure).
 *
 * [AI_CONTEXT]
 * - Não é append-only: funciona como máquina de estados auditada via LogsActivity.
 * - 'estado' e 'origem' são ortogonais: estado indica conclusão; origem indica se
 *   o registo é manual ('declarada'), confirmado via sensor ('reconstruida') ou
 *   proposto ('inferida').
 * - As obrigações legais (obrigatorio=true) têm de estar executadas ou justificadas
 *   como não aplicáveis antes do fecho da paragem.
 */
class PoolClosureTask extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'pool_closure_id',
        'tipo',
        'ordem',
        'obrigatorio',
        'estado',
        'origem',
        'previsto_para',
        'executado_em',
        'executado_por',
        'motivo_nao_execucao',
        'dados',
        'fotos',
        'documentos',
        'observacoes',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'obrigatorio' => 'boolean',
        'previsto_para' => 'date',
        'executado_em' => 'datetime',
        'dados' => 'array',
        'fotos' => 'array',
        'documentos' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function (PoolClosureTask $task): void {
            $task->invalidarCaches();
        });

        static::deleted(function (PoolClosureTask $task): void {
            $task->invalidarCaches();
        });
    }

    public function encerramento(): BelongsTo
    {
        return $this->belongsTo(PoolClosure::class, 'pool_closure_id');
    }

    public function executadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executado_por');
    }

    public function tipoLabel(): string
    {
        return TrabalhoParagem::label($this->tipo);
    }

    public function estadoLabel(): string
    {
        return TrabalhoParagem::estadoLabel($this->estado);
    }

    public function origemLabel(): string
    {
        return TrabalhoParagem::origemLabel($this->origem);
    }

    /**
     * Scope para obter tarefas obrigatórias que ainda não foram concluídas nem declaradas não aplicáveis.
     */
    public function scopeObrigatoriosEmFalta(Builder $query): Builder
    {
        return $query
            ->where('obrigatorio', true)
            ->whereNotIn('estado', [
                TrabalhoParagem::ESTADO_EXECUTADO,
                TrabalhoParagem::ESTADO_NAO_APLICAVEL,
            ]);
    }

    /**
     * Devolve os dados formatados em linguagem corrente para visualização e relatórios.
     */
    public function dadosFormatados(): string
    {
        if (empty($this->dados)) {
            return '—';
        }

        $partes = [];

        switch ($this->tipo) {
            case TrabalhoParagem::SUPERCLORACAO:
                if (isset($this->dados['produto']) && filled($this->dados['produto'])) {
                    $partes[] = "Produto: {$this->dados['produto']}";
                }
                if (isset($this->dados['quantidade']) && filled($this->dados['quantidade'])) {
                    $partes[] = "Quantidade: {$this->dados['quantidade']}";
                }
                $horasContacto = $this->dados['horas_contacto'] ?? $this->dados['tempo_contacto_horas'] ?? null;
                if (filled($horasContacto)) {
                    $partes[] = "Tempo contacto: {$horasContacto} h";
                }
                if (isset($this->dados['orp_atingido_mv']) && filled($this->dados['orp_atingido_mv'])) {
                    $partes[] = 'ORP: '.number_format((float) $this->dados['orp_atingido_mv'], 0, ',', '').' mV';
                }
                $cloroLivre = $this->dados['cloro_livre_ppm'] ?? $this->dados['cloro_livre_atingido'] ?? null;
                if (filled($cloroLivre)) {
                    $partes[] = 'Cl livre: '.number_format((float) $cloroLivre, 2, ',', '').' mg/L';
                }
                if (isset($this->dados['ph_durante_choque']) && filled($this->dados['ph_durante_choque'])) {
                    $partes[] = 'pH: '.number_format((float) $this->dados['ph_durante_choque'], 2, ',', '');
                }
                if (isset($this->dados['neutralizacao_quimica']) && filled($this->dados['neutralizacao_quimica'])) {
                    $partes[] = "Neutralização: {$this->dados['neutralizacao_quimica']}";
                }
                break;

            case TrabalhoParagem::DESINFECAO_LEGIONELLA:
                if (isset($this->dados['numero_boletim']) && filled($this->dados['numero_boletim'])) {
                    $partes[] = "Boletim: {$this->dados['numero_boletim']}";
                }
                if (isset($this->dados['laboratorio']) && filled($this->dados['laboratorio'])) {
                    $partes[] = "Laboratório: {$this->dados['laboratorio']}";
                }
                if (isset($this->dados['data_colheita']) && filled($this->dados['data_colheita'])) {
                    $partes[] = "Colheita: {$this->dados['data_colheita']}";
                }
                $resultado = $this->dados['resultado'] ?? $this->dados['resultado_legionella'] ?? null;
                if (filled($resultado)) {
                    $partes[] = "Resultado: {$resultado}";
                }
                $zonas = $this->dados['zonas'] ?? $this->dados['zonas_desinfetadas'] ?? null;
                if (filled($zonas)) {
                    $zonasStr = is_array($zonas) ? implode(', ', $zonas) : (string) $zonas;
                    $partes[] = "Zonas: {$zonasStr}";
                }
                break;

            case TrabalhoParagem::ARRANQUE_AQUECIMENTO:
                if (isset($this->dados['temperatura_inicial']) && filled($this->dados['temperatura_inicial'])) {
                    $partes[] = 'Temp. inicial: '.number_format((float) $this->dados['temperatura_inicial'], 1, ',', '').' °C';
                }
                if (isset($this->dados['temperatura_alvo']) && filled($this->dados['temperatura_alvo'])) {
                    $partes[] = 'Temp. alvo: '.number_format((float) $this->dados['temperatura_alvo'], 1, ',', '').' °C';
                }
                break;

            case TrabalhoParagem::VERIFICACAO_PARAMETROS:
                if (isset($this->dados['ph']) && filled($this->dados['ph'])) {
                    $partes[] = 'pH: '.number_format((float) $this->dados['ph'], 2, ',', '');
                }
                if (isset($this->dados['cloro_livre']) && filled($this->dados['cloro_livre'])) {
                    $partes[] = 'Cl livre: '.number_format((float) $this->dados['cloro_livre'], 2, ',', '').' mg/L';
                }
                if (isset($this->dados['cloro_total']) && filled($this->dados['cloro_total'])) {
                    $partes[] = 'Cl total: '.number_format((float) $this->dados['cloro_total'], 2, ',', '').' mg/L';
                }
                if (isset($this->dados['turbidez']) && filled($this->dados['turbidez'])) {
                    $partes[] = 'Turbidez: '.number_format((float) $this->dados['turbidez'], 2, ',', '').' NTU';
                }
                if (isset($this->dados['temperatura']) && filled($this->dados['temperatura'])) {
                    $partes[] = 'Temp: '.number_format((float) $this->dados['temperatura'], 1, ',', '').' °C';
                }
                break;

            default:
                foreach ($this->dados as $k => $v) {
                    if (filled($v)) {
                        $rotulo = self::rotuloDado((string) $k);
                        $valor = is_bool($v) ? ($v ? 'sim' : 'não') : (is_array($v) ? implode(', ', $v) : (string) $v);
                        $partes[] = "{$rotulo}: {$valor}";
                    }
                }
                break;
        }

        return empty($partes) ? '—' : implode(' | ', $partes);
    }

    private static function rotuloDado(string $chave): string
    {
        return match ($chave) {
            'produto' => 'Produto',
            'quantidade' => 'Quantidade',
            'horas_contacto', 'tempo_contacto_horas' => 'Tempo de contacto (h)',
            'orp_atingido_mv' => 'ORP (mV)',
            'cloro_livre_ppm', 'cloro_livre_atingido' => 'Cloro livre (mg/L)',
            'ph_durante_choque' => 'pH choque',
            'neutralizacao_quimica' => 'Neutralização',
            'laboratorio' => 'Laboratório',
            'numero_boletim' => 'Boletim nº',
            'data_colheita' => 'Data da colheita',
            'data_boletim' => 'Data do boletim',
            'resultado', 'resultado_legionella' => 'Resultado',
            'zonas', 'zonas_desinfetadas' => 'Zonas abrangidas',
            'temperatura_inicial' => 'Temp. inicial (°C)',
            'temperatura_alvo' => 'Temp. alvo (°C)',
            'ph' => 'pH',
            'cloro_livre' => 'Cloro livre (mg/L)',
            'cloro_total' => 'Cloro total (mg/L)',
            'turbidez' => 'Turbidez (NTU)',
            'temperatura' => 'Temperatura (°C)',
            'metodo' => 'Método',
            'filtro_nome' => 'Filtro',
            'tipo_intervencao' => 'Intervenção',
            default => ucfirst(str_replace('_', ' ', $chave)),
        };
    }

    public function invalidarCaches(): void
    {
        $cache = app(CacheService::class);
        $cache->invalidateAllAlerts();

        $closure = $this->relationLoaded('encerramento')
            ? $this->encerramento
            : $this->encerramento()->first();

        if ($closure instanceof PoolClosure) {
            $cache->invalidateGraphCache((int) $closure->pool_id);
        }
    }
}
