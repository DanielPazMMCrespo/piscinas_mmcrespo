<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'unidade', 'categoria', 'concentracao_cl', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'concentracao_cl' => 'decimal:2',
    ];

    public function stockArmazem(): HasOne
    {
        return $this->hasOne(StockWarehouse::class);
    }

    public function stockInstalacoes(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }
}
