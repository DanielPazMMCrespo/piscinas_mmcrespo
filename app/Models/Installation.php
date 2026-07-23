<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Installation extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'morada', 'active', 'tanques_verificaveis'];

    protected $casts = [
        'active' => 'boolean',
        'tanques_verificaveis' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Installation $installation): void {
            $installation->incidentes()->delete();
            $installation->piscinas()->delete();
            // stock_installation_logs.stock_installation_id é restrictOnDelete();
            // sem apagar primeiro os logs, qualquer instalação com histórico de
            // stock (o caso normal) falha a eliminação com violação de FK.
            $installation->stockInstallations->each(function (StockInstallation $stock): void {
                $stock->registos()->delete();
            });
            $installation->stockInstallations()->delete();
        });
    }

    public function piscinas(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function incidentes(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function stockInstallations(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
