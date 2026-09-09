<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/**
 * Ação operacional pontual numa piscina (lavagem de filtro, torneira, contador,
 * análise rápida, etc.). Ao contrário do DailyRecord — o registo completo e
 * regulado do livro sanitário — cada ação aqui é um evento isolado, com hora e
 * autor exatos, que pode explicar/justificar valores anómalos do controlador
 * no mesmo intervalo (ex.: pH e ORP a cair durante uma lavagem de filtro).
 */
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OperationalAction extends Model
{
    use HasFactory, LogsActivity;

    public const TIPO_LAVAGEM_FILTRO = 'lavagem_filtro';

    public const TIPO_ENXAGUAMENTO_FILTRO = 'enxaguamento_filtro';

    public const TIPO_TORNEIRA = 'torneira';

    public const TIPO_BOMBA = 'bomba';

    public const TIPO_CONTADOR = 'contador';

    public const TIPO_TANQUE = 'tanque';

    public const TIPO_ANALISE_PONTUAL = 'analise_pontual';

    public const TIPO_REABASTECIMENTO_BIDAO = 'reabastecimento_bidao';

    public const TIPO_LIMPEZA_PRAIAS = 'limpeza_praias';

    public const TIPO_ASPIRACAO_FUNDO = 'aspiracao_fundo';

    public const TIPO_TRATAMENTO_CHOQUE = 'tratamento_choque';

    public const TIPO_MANUTENCAO_EQUIPAMENTO = 'manutencao_equipamento';

    public const TIPO_AVARIA_SONDA = 'avaria_sonda';

    public const TIPO_OUTRO = 'outro';

    public const TIPOS = [
        self::TIPO_LAVAGEM_FILTRO => 'Lavagem de filtro',
        self::TIPO_ENXAGUAMENTO_FILTRO => 'Enxaguamento de filtro',
        self::TIPO_TORNEIRA => 'Torneira / entrada de água',
        self::TIPO_BOMBA => 'Bomba',
        self::TIPO_CONTADOR => 'Contador (m³)',
        self::TIPO_TANQUE => 'Tanque de compensação',
        self::TIPO_REABASTECIMENTO_BIDAO => 'Reabastecimento de bidão',
        self::TIPO_LIMPEZA_PRAIAS => 'Limpeza de praias / grelhas',
        self::TIPO_ASPIRACAO_FUNDO => 'Aspiração de fundo / robô',
        self::TIPO_TRATAMENTO_CHOQUE => 'Tratamento de choque / hipercloração',
        self::TIPO_MANUTENCAO_EQUIPAMENTO => 'Manutenção / calibração',
        self::TIPO_AVARIA_SONDA => 'Sonda: avaria / indisponibilidade',
        self::TIPO_OUTRO => 'Outro',
    ];

    protected $fillable = [
        'pool_id', 'user_id', 'tipo', 'registado_em', 'dados', 'observacoes', 'foto',
    ];

    protected $casts = [
        'registado_em' => 'datetime',
        'dados' => 'array',
    ];

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tipoLabel(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /**
     * Devolve os dados formatados em linguagem corrente para visualização do utilizador.
     */
    public function dadosFormatados(): string
    {
        if (empty($this->dados)) {
            return '—';
        }

        $partes = [];

        switch ($this->tipo) {
            case self::TIPO_LAVAGEM_FILTRO:
            case self::TIPO_ENXAGUAMENTO_FILTRO:
                if (isset($this->dados['filtro_nome']) && filled($this->dados['filtro_nome'])) {
                    $partes[] = "Filtro: {$this->dados['filtro_nome']}";
                }
                if (isset($this->dados['duracao_min']) && filled($this->dados['duracao_min'])) {
                    $partes[] = "Duração: {$this->dados['duracao_min']} min";
                }
                if (isset($this->dados['pressao_antes_bar']) && filled($this->dados['pressao_antes_bar'])) {
                    $partes[] = "Pressão inicial: {$this->dados['pressao_antes_bar']} bar";
                }
                if (isset($this->dados['pressao_depois_bar']) && filled($this->dados['pressao_depois_bar'])) {
                    $partes[] = "Pressão final: {$this->dados['pressao_depois_bar']} bar";
                }
                break;

            case self::TIPO_TORNEIRA:
                if (isset($this->dados['agua_modo']) && filled($this->dados['agua_modo'])) {
                    $modos = [
                        'auto_com_agua' => 'Automático com água',
                        'auto_sem_agua' => 'Automático sem água',
                        'on_com_agua' => 'Ligado com água',
                        'on_sem_agua' => 'Ligado sem água',
                        'off' => 'Desligado sem água',
                    ];
                    $modo = $modos[$this->dados['agua_modo']] ?? $this->dados['agua_modo'];
                    $partes[] = "Estado: {$modo}";
                }
                break;

            case self::TIPO_BOMBA:
                if (isset($this->dados['bomba_nome']) && filled($this->dados['bomba_nome'])) {
                    $partes[] = "Bomba: {$this->dados['bomba_nome']}";
                }
                if (isset($this->dados['bomba_acao']) && filled($this->dados['bomba_acao'])) {
                    $acoes = [
                        'ferragem' => 'Ferragem',
                        'limpeza_pre_filtro' => 'Limpeza de pré-filtro',
                        'paragem_arranque' => 'Paragem / Arranque',
                        'manutencao' => 'Manutenção',
                    ];
                    $partes[] = 'Ação: '.($acoes[$this->dados['bomba_acao']] ?? $this->dados['bomba_acao']);
                }
                if (isset($this->dados['bomba_ferrada']) && $this->dados['bomba_ferrada'] !== '') {
                    $ferrada = filter_var($this->dados['bomba_ferrada'], FILTER_VALIDATE_BOOLEAN);
                    $partes[] = $ferrada ? 'Bomba ferrada' : 'Bomba desferrada';
                }
                break;

            case self::TIPO_CONTADOR:
                if (isset($this->dados['contador_valor']) && filled($this->dados['contador_valor'])) {
                    $valor = number_format((float) $this->dados['contador_valor'], 2, ',', ' ');
                    $partes[] = "Leitura: {$valor} m³";
                }
                break;

            case self::TIPO_TANQUE:
                if (isset($this->dados['tanque_nome']) && filled($this->dados['tanque_nome'])) {
                    $partes[] = "Tanque: {$this->dados['tanque_nome']}";
                }
                if (isset($this->dados['tanque_nivel_pct']) && filled($this->dados['tanque_nivel_pct'])) {
                    $partes[] = "Nível: {$this->dados['tanque_nivel_pct']}%";
                }
                if (isset($this->dados['tanque_ok']) && $this->dados['tanque_ok'] !== '') {
                    $ok = filter_var($this->dados['tanque_ok'], FILTER_VALIDATE_BOOLEAN);
                    $partes[] = $ok ? 'Tanque OK' : 'Problema no tanque';
                }
                break;

            case self::TIPO_ANALISE_PONTUAL:
                if (isset($this->dados['ph']) && filled($this->dados['ph'])) {
                    $partes[] = 'pH: '.number_format((float) $this->dados['ph'], 2, ',', '');
                }
                if (isset($this->dados['cloro_livre']) && filled($this->dados['cloro_livre'])) {
                    $partes[] = 'Cl livre: '.number_format((float) $this->dados['cloro_livre'], 2, ',', '').' mg/L';
                }
                if (isset($this->dados['cloro_total']) && filled($this->dados['cloro_total'])) {
                    $partes[] = 'Cl total: '.number_format((float) $this->dados['cloro_total'], 2, ',', '').' mg/L';
                }
                if (isset($this->dados['cloro_livre'], $this->dados['cloro_total']) && filled($this->dados['cloro_livre']) && filled($this->dados['cloro_total'])) {
                    $comb = max(0, (float) $this->dados['cloro_total'] - (float) $this->dados['cloro_livre']);
                    $partes[] = 'Cl comb: '.number_format($comb, 2, ',', '').' mg/L';
                }
                if (isset($this->dados['orp']) && filled($this->dados['orp'])) {
                    $partes[] = 'ORP: '.number_format((float) $this->dados['orp'], 0, ',', '').' mV';
                }
                if (isset($this->dados['temperatura']) && filled($this->dados['temperatura'])) {
                    $partes[] = 'Temp: '.number_format((float) $this->dados['temperatura'], 1, ',', '').' °C';
                }
                break;

            case self::TIPO_REABASTECIMENTO_BIDAO:
                if (isset($this->dados['bidao_tipo']) && filled($this->dados['bidao_tipo'])) {
                    $labels = [
                        DosingContainer::TIPO_CLORO => 'Cloro',
                        DosingContainer::TIPO_PH_MENOS => 'pH-',
                        'coagulante' => 'Coagulante / Floculante',
                        'ph_mais' => 'pH+',
                        'anti_algas' => 'Anti-algas',
                        'ambos' => 'Ambos (Cloro e pH-)',
                    ];
                    $tipoLabel = $labels[$this->dados['bidao_tipo']] ?? $this->dados['bidao_tipo'];
                    $partes[] = "Bidão: {$tipoLabel}";
                }
                if (isset($this->dados['quantidade_l']) && filled($this->dados['quantidade_l'])) {
                    $quantidade = number_format((float) $this->dados['quantidade_l'], 2, ',', ' ');
                    $partes[] = "Quantidade: {$quantidade} L";
                } elseif (isset($this->dados['bidao_tipo']) && $this->dados['bidao_tipo'] === 'ambos') {
                    $partes[] = 'Quantidade: Capacidade total';
                }
                break;

            case self::TIPO_AVARIA_SONDA:
                $estado = $this->dados['sonda_estado'] ?? null;
                if (filled($estado)) {
                    $partes[] = $estado === SensorOutage::ESTADO_RESOLVIDO
                        ? 'Sonda reposta em serviço'
                        : 'Sonda indisponível: '.(SensorOutage::MOTIVOS[$estado] ?? $estado);
                }
                break;

            case self::TIPO_TRATAMENTO_CHOQUE:
                if (isset($this->dados['produto']) && filled($this->dados['produto'])) {
                    $partes[] = "Produto: {$this->dados['produto']}";
                }
                if (isset($this->dados['quantidade']) && filled($this->dados['quantidade'])) {
                    $partes[] = "Quantidade: {$this->dados['quantidade']}";
                }
                break;

            default:
                foreach ($this->dados as $k => $v) {
                    if (filled($v)) {
                        $partes[] = self::rotuloDado($k).': '.(is_bool($v) ? ($v ? 'sim' : 'não') : $v);
                    }
                }
                break;
        }

        return empty($partes) ? '—' : implode(' | ', $partes);
    }

    /**
     * Rótulo legível para chaves do JSON `dados` sem `case` próprio — o fallback
     * imprimia a chave crua ("duracao_min: 7 | valor: 27").
     */
    private static function rotuloDado(string $chave): string
    {
        return match ($chave) {
            'duracao_min' => 'Duração (min)',
            'pressao_antes_bar' => 'Pressão inicial (bar)',
            'pressao_depois_bar' => 'Pressão final (bar)',
            'filtro_nome' => 'Filtro',
            'valor' => 'Valor',
            'contador_valor' => 'Contador (m³)',
            'quantidade_l' => 'Quantidade (L)',
            'orp' => 'ORP (mV)',
            'ph' => 'pH',
            'cloro_livre' => 'Cloro livre',
            'temperatura' => 'Temperatura',
            default => ucfirst(str_replace('_', ' ', $chave)),
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
