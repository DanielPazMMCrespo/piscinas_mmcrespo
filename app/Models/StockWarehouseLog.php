<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWarehouseLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['product_id', 'user_id', 'tipo_movimento', 'quantity', 'fornecedor', 'created_at'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(StockWarehouse::class, 'product_id', 'product_id');
    }
}
