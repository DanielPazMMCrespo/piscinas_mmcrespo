<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockWarehouseLog extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = ['stock_warehouse_id', 'user_id', 'tipo_movimento', 'quantity', 'fornecedor', 'created_at'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(StockWarehouse::class, 'stock_warehouse_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
