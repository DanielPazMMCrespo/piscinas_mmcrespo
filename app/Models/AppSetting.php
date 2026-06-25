<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $table = 'app_settings';
    
    protected $primaryKey = 'key';
    
    public $incrementing = false;
    
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'group',
        'label',
        'type',
        'description',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \Illuminate\Support\Facades\Cache::forget('app_settings_all'));
        static::deleted(fn () => \Illuminate\Support\Facades\Cache::forget('app_settings_all'));
    }
}
