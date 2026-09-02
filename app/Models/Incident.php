<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Incident extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'installation_id', 'pool_id', 'user_id', 'ocorreu_em',
        'type', 'descricao', 'observacoes',
        'status', 'resolvido_em', 'resolvido_por', 'resolucao',
        'fotos',
    ];

    protected $casts = [
        'ocorreu_em' => 'datetime',
        'resolvido_em' => 'datetime',
        'fotos' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Incident $incident): void {
            static::autorizarPiscinaDoAutor($incident);
        });
    }

    /**
     * O Select de instalação/piscina em IncidentResource::form() só filtra as
     * opções mostradas a um Nadador-Salvador — nunca validou no servidor o
     * que é de facto submetido (o `Rule::exists` que o Filament gera para um
     * `relationship()` verifica só que o id existe na tabela, ignora o
     * `modifyQueryUsing`). Sem isto, um NS conseguia gravar um incidente
     * contra a instalação/piscina de outro NS manipulando o pedido Livewire.
     * Mesma regra que `DailyRecordService::createRecords()` já aplica aos
     * registos diários. Só o NS é limitado; os outros papéis com permissão
     * para criar incidentes veem/gerem todas as instalações.
     */
    private static function autorizarPiscinaDoAutor(self $incident): void
    {
        $user = auth()->user();

        if (! $user || ! $user->hasRole(UserRole::NADADOR_SALVADOR)) {
            return;
        }

        $poolIdsPermitidos = $user->piscinas()->pluck('pools.id')->all();

        if ($incident->pool_id !== null) {
            abort_unless(in_array((int) $incident->pool_id, $poolIdsPermitidos, true), 403, 'Acesso não autorizado a esta piscina.');

            return;
        }

        abort_unless(
            Pool::where('installation_id', $incident->installation_id)->whereIn('id', $poolIdsPermitidos)->exists(),
            403,
            'Acesso não autorizado a esta instalação.'
        );
    }

    public function estaResolvido(): bool
    {
        return $this->status === IncidentStatus::RESOLVIDO;
    }

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function piscina(): BelongsTo
    {
        return $this->belongsTo(Pool::class, 'pool_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(IncidentMessage::class, 'incident_id')->orderBy('created_at');
    }

    /**
     * Quem já participou nesta conversa (reportante + autores de mensagens),
     * excluindo quem acabou de escrever. Se ninguém mais participou ainda, cai
     * para admin+técnico — evita notificar todo o sistema a cada mensagem
     * mantendo pelo menos alguém avisado à primeira resposta.
     *
     * @return Collection<int, User>
     */
    public function participantes(?User $excluir = null): Collection
    {
        $ids = $this->mensagens()->pluck('user_id')
            ->push($this->user_id)
            ->filter()
            ->unique();

        if ($excluir) {
            $ids = $ids->reject(fn ($id) => $id === $excluir->id);
        }

        $participantes = User::whereIn('id', $ids)->get();

        if ($participantes->isNotEmpty()) {
            return $participantes;
        }

        $fallback = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();

        return $excluir ? $fallback->reject(fn (User $u) => $u->id === $excluir->id) : $fallback;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
