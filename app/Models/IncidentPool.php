<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentPool extends Model
{
    protected $fillable = ['incident_id', 'pool_id'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }
}