<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = ['name', 'unidade', 'categoria', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function stockArmazem(): HasOne
    {
        return $this->hasOne(StockWarehouse::class);
    }

    public function stockInstalacoes(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }
}
