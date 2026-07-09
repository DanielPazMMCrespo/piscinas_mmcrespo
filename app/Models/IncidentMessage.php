<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentMessage extends Model
{
    use HasFactory;

    public const TIPO_MENSAGEM = 'mensagem';
    public const TIPO_SISTEMA = 'sistema';

    protected $fillable = [
        'incident_id', 'user_id', 'tipo', 'texto',
    ];

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eSistema(): bool
    {
        return $this->tipo === self::TIPO_SISTEMA;
    }
}
