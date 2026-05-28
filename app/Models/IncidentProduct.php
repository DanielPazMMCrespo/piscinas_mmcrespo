<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentProduct extends Model
{
    protected $fillable = ['incident_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}