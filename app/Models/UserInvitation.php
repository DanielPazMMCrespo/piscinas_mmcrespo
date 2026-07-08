<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    protected $fillable = [
        'email',
        'role',
        'pool_ids',
        'token',
        'invited_by_id',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'pool_ids'    => 'array',
        'accepted_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public static function findValid(string $rawToken): ?self
    {
        return self::where('token', hash('sha256', $rawToken))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
