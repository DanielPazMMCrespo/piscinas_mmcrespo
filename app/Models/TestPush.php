<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestPush extends Model
{
    protected $fillable = [
        'user_id',
        'tipo',
        'fire_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'fire_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
