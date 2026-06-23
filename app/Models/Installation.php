<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'morada', 'active'];

    protected $casts = ['active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Installation $installation): void {
            $installation->incidentes()->delete();
            // Apaga pools em cascata (FK cascadeOnDelete cuida dos daily_records filhos).
            $installation->piscinas()->each(fn (Pool $pool) => $pool->delete());
            // Stock da instalação
            StockInstallation::where('installation_id', $installation->id)->delete();
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
