<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordAddition extends Model
{
    protected $fillable = ['daily_record_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function registoDiario(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'daily_record_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
