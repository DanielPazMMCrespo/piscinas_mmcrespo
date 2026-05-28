<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInstallationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_installation_id', 'user_id', 'tipo_movimento', 'quantity', 'created_at'];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function stockInstalacao(): BelongsTo
    {
        return $this->belongsTo(StockInstallation::class, 'stock_installation_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
