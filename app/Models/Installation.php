<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    protected $fillable = ['name', 'morada', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function piscinas(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function incidentes(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function stockInstalacoes(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }
}
