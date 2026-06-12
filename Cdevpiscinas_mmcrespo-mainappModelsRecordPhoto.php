<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordPhoto extends Model
{
    protected $fillable = [
        'daily_record_id',
        'type',
        'path',
        'resultado_ocr',
    ];

    protected $casts = [
        'resultado_ocr' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function dailyRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class);
    }
}
