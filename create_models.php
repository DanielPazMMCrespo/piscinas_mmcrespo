<?php

$models = [
    'Installation' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    protected $fillable = ['name', 'address', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function stockInstallations(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }
}
PHP,

    'Pool' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pool extends Model
{
    protected $fillable = ['installation_id', 'name', 'type', 'temp_min', 'temp_max', 'active'];

    protected $casts = [
        'active'   => 'boolean',
        'temp_min' => 'decimal:1',
        'temp_max' => 'decimal:1',
    ];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function dailyRecords(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    public function filterChecks(): HasMany
    {
        return $this->hasMany(FilterCheck::class);
    }
}
PHP,

    'Product' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = ['name', 'unit', 'category', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function stockWarehouse(): HasOne
    {
        return $this->hasOne(StockWarehouse::class);
    }

    public function stockInstallations(): HasMany
    {
        return $this->hasMany(StockInstallation::class);
    }
}
PHP,

    'StockWarehouse' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWarehouse extends Model
{
    protected $fillable = ['product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockWarehouseLog::class, 'product_id', 'product_id');
    }
}
PHP,

    'StockInstallation' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockInstallation extends Model
{
    protected $fillable = ['installation_id', 'product_id', 'quantity', 'low_threshold'];

    protected $casts = [
        'quantity'      => 'decimal:3',
        'low_threshold' => 'decimal:3',
    ];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StockInstallationLog::class);
    }
}
PHP,

    'StockWarehouseLog' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWarehouseLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'user_id', 'movement_type', 'quantity', 'supplier', 'created_at'];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP,

    'StockInstallationLog' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInstallationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_installation_id', 'user_id', 'movement_type', 'quantity', 'created_at'];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function stockInstallation(): BelongsTo
    {
        return $this->belongsTo(StockInstallation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP,

    'DailyRecord' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyRecord extends Model
{
    protected $fillable = [
        'pool_id', 'user_id', 'recorded_at',
        'chlorine_free', 'chlorine_total',
        'ph', 'temperature', 'transparency',
        'overflow_done', 'water_renewal_done',
        'observations', 'is_correction',
        'corrects_record_id', 'correction_reason',
    ];

    protected $casts = [
        'recorded_at'        => 'datetime',
        'chlorine_free'      => 'decimal:2',
        'chlorine_total'     => 'decimal:2',
        'ph'                 => 'decimal:2',
        'temperature'        => 'decimal:1',
        'overflow_done'      => 'boolean',
        'water_renewal_done' => 'boolean',
        'is_correction'      => 'boolean',
    ];

    protected function chlorineCombined(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->chlorine_total - (float) $this->chlorine_free, 2)
        );
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function additions(): HasMany
    {
        return $this->hasMany(RecordAddition::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RecordPhoto::class);
    }

    public function originalRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class, 'corrects_record_id');
    }
}
PHP,

    'RecordAddition' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordAddition extends Model
{
    protected $fillable = ['daily_record_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function dailyRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
PHP,

    'RecordPhoto' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordPhoto extends Model
{
    protected $fillable = ['daily_record_id', 'type', 'path', 'ocr_result'];

    protected $casts = ['ocr_result' => 'array'];

    public function dailyRecord(): BelongsTo
    {
        return $this->belongsTo(DailyRecord::class);
    }
}
PHP,

    'Incident' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $fillable = [
        'installation_id', 'user_id', 'occurred_at',
        'type', 'description', 'observations',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(Pool::class, 'incident_pools');
    }

    public function incidentProducts(): HasMany
    {
        return $this->hasMany(IncidentProduct::class);
    }
}
PHP,

    'IncidentPool' => <<<'PHP'
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
PHP,

    'IncidentProduct' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentProduct extends Model
{
    protected $fillable = ['incident_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
PHP,

    'FilterCheck' => <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterCheck extends Model
{
    protected $fillable = [
        'pool_id', 'user_id', 'checked_at', 'operation_type',
        'photo_path', 'ai_result', 'ai_description', 'observations',
    ];

    protected $casts = ['checked_at' => 'datetime'];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP,
];

foreach ($models as $name => $content) {
    file_put_contents(__DIR__ . '/app/Models/' . $name . '.php', $content);
}
echo "Models created.";
