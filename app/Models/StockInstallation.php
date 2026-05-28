<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockInstallation extends Model
{
    protected $fillable = ['installation_id', 'product_id', 'quantity', 'limite_minimo'];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'limite_minimo' => 'decimal:3',
    ];

    public function instalacao(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function registos(): HasMany
    {
        return $this->hasMany(StockInstallationLog::class);
    }
}
