<?php declare(strict_types=1);
namespace App\Models;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordPhoto extends Model
{
    protected $fillable = ['daily_record_id', 'type', 'path'];

    public function registoDiario(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'daily_record_id');
    }
}
