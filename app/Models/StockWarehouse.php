<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockWarehouse extends Model
{
    use LogsActivity;

    protected $fillable = ['product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function registos(): HasMany
    {
        return $this->hasMany(StockWarehouseLog::class, 'product_id', 'product_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
