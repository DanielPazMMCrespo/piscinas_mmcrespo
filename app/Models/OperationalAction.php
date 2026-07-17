<?php declare(strict_types=1);
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
class OperationalAction extends Model
{
    use HasFactory;

    public const TIPO_LAVAGEM_FILTRO = 'lavagem_filtro';
    public const TIPO_ENXAGUAMENTO_FILTRO = 'enxaguamento_filtro';
    public const TIPO_TORNEIRA = 'torneira';
    public const TIPO_BOMBA = 'bomba';
    public const TIPO_CONTADOR = 'contador';
    public const TIPO_TANQUE = 'tanque';
    public const TIPO_ANALISE_PONTUAL = 'analise_pontual';
    public const TIPO_OUTRO = 'outro';

    public const TIPOS = [
        self::TIPO_LAVAGEM_FILTRO => 'Lavagem de filtro',
        self::TIPO_ENXAGUAMENTO_FILTRO => 'Enxaguamento de filtro',
        self::TIPO_TORNEIRA => 'Torneira / entrada de água',
        self::TIPO_BOMBA => 'Bomba',
        self::TIPO_CONTADOR => 'Contador (m³)',
        self::TIPO_TANQUE => 'Tanque',
        self::TIPO_ANALISE_PONTUAL => 'Análise rápida',
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
}
