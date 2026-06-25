<?php declare(strict_types=1);
namespace App\Models;


// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use App\Constants\UserRole;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $hasRole = $this->hasAnyRole(UserRole::all());

        if (! $hasRole) {
            session()->flash('mmc_sem_cargo', true);
        }

        return $hasRole;
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
        ];
    }
}
