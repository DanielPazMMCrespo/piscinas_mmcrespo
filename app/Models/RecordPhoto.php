<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordPhoto extends Model
{
    protected $fillable = ['daily_record_id', 'type', 'path', 'resultado_ocr'];

    protected $casts = ['resultado_ocr' => 'array'];

    public function registoDiario(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'daily_record_id');
    }
}
