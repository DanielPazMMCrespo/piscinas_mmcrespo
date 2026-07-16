<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimerPush extends Model
{
    protected $fillable = [
        'user_id',
        'pool_id',
        'fase',
        'fire_at',
        'sent_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'fire_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }
}
