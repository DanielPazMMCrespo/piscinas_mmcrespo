<?php declare(strict_types=1);
namespace App\Models;


// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use App\Constants\NSPermission;
use App\Constants\UserRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->hasRole(UserRole::INATIVO)) {
            session()->flash('mmc_inativo', true);
            return false;
        }

        $hasRole = $this->hasAnyRole(UserRole::all());

        if (! $hasRole) {
            session()->flash('mmc_sem_cargo', true);
        }

        return $hasRole;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return asset('images/user-placeholder.svg');
    }

    public function getFullNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }

        return $this->name;
    }

    protected function setFirstNameAttribute(?string $value): void
    {
        $this->attributes['first_name'] = $value;
        $this->syncNameField();
    }

    protected function setLastNameAttribute(?string $value): void
    {
        $this->attributes['last_name'] = $value;
        $this->syncNameField();
    }

    private function syncNameField(): void
    {
        $firstName = $this->attributes['first_name'] ?? null;
        $lastName = $this->attributes['last_name'] ?? null;
        $this->attributes['name'] = trim("{$firstName} {$lastName}");
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    public function piscinas(): BelongsToMany
    {
        return $this->belongsToMany(Pool::class, 'user_pools');
    }

    public function daily_records(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Se este utilizador consegue ver/usar a secção indicada. Para cargos
     * que não sejam Nadador-Salvador não há restrição. Para NS sem
     * `ns_permissions` definido (registo antigo), assume-se tudo visível.
     */
    public function podeVer(string $seccao): bool
    {
        if (! $this->hasRole(UserRole::NADADOR_SALVADOR)) {
            return true;
        }

        return in_array($seccao, $this->ns_permissions ?? NSPermission::all(), true);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'pin',
        'must_change_password',
        'ns_permissions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'ns_permissions' => 'array',
        ];
    }
}
