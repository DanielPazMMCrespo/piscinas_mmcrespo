<?php declare(strict_types=1);
namespace App\Models;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    protected $fillable = ['name', 'morada', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Installation $installation): void {
            $installation->incidentes()->delete();
        });
    }

    /**
     * @return HasMany
     */
    public function piscinas(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    /**
     * @return HasMany
     */
    public function incidentes(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

}
