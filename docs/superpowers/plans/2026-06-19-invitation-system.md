# Invitation System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace direct user creation with a token-based invitation flow — admin/gestor enters email + role, user receives link, completes registration with first name, last name, phone, password and/or PIN.

**Architecture:** New `user_invitations` table stores one-time tokens (48h TTL). A public Blade route `/convite/{token}` handles registration outside Filament. Filament gets a custom Login page that auto-detects PIN (4–6 digits) vs password. A new `gestor` role mirrors admin access for user management.

**Tech Stack:** Laravel 12, Filament 3.3, Spatie laravel-permission v6, Blade (invitation page), bcrypt (PIN hashing), PHP 8.5, PHPUnit.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/2026_06_19_000001_add_profile_fields_to_users_table.php` | Create | first_name, last_name, phone, pin columns |
| `database/migrations/2026_06_19_000002_create_user_invitations_table.php` | Create | invitation tokens table |
| `app/Models/UserInvitation.php` | Create | Invitation model + helpers (isPending, isExpired, isAccepted) |
| `app/Models/User.php` | Modify | Add fillable fields, getFullNameAttribute, hasPin() |
| `app/Services/InvitationService.php` | Create | send(), accept() business logic |
| `app/Mail/UserInvitationMail.php` | Create | Mailable |
| `resources/views/emails/user-invitation.blade.php` | Create | Email template |
| `app/Http/Controllers/InvitationController.php` | Create | show() + store() for /convite/{token} |
| `resources/views/auth/accept-invitation.blade.php` | Create | Registration form (email/role read-only) |
| `app/Filament/Pages/Auth/Login.php` | Create | Custom login with PIN toggle |
| `app/Filament/Resources/UserResource.php` | Modify | first_name/last_name fields + Convidar action |
| `app/Filament/Resources/UserResource/Pages/ListUsers.php` | Modify | Add HeaderAction "Convidar Utilizador" |
| `database/seeders/RolesAndPermissionsSeeder.php` | Modify | Add gestor role |
| `database/seeders/UserSeeder.php` | Modify | Add daniel@ + marcio@ real accounts |
| `app/Providers/Filament/AdminPanelProvider.php` | Modify | Register custom login, gestor canAccessPanel |
| `routes/web.php` | Modify | GET/POST /convite/{token} |
| `tests/Feature/InvitationFlowTest.php` | Create | Full invitation + registration flow |
| `tests/Feature/PinLoginTest.php` | Create | PIN authentication |
| `tests/Feature/GestorRoleTest.php` | Create | gestor can invite, cannot access admin-only |

---

## Task 1: Add gestor role

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `app/Models/User.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/GestorRoleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_gestor_can_access_filament_panel(): void
    {
        $gestor = User::factory()->create();
        $gestor->assignRole('gestor');

        $this->assertTrue($gestor->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_gestor_role_exists(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'gestor', 'guard_name' => 'web']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
C:\php\php.exe vendor/bin/phpunit tests/Feature/GestorRoleTest.php --testdox
```

Expected: FAIL — role 'gestor' does not exist.

- [ ] **Step 3: Add gestor to seeder**

`database/seeders/RolesAndPermissionsSeeder.php` — add to the roles array:
```php
<?php declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['admin', 'gestor', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
```

- [ ] **Step 4: Update canAccessPanel in User model**

`app/Models/User.php` — update the method:
```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->hasAnyRole(['admin', 'gestor', 'tecnico', 'nadador_salvador']);
}
```

- [ ] **Step 5: Run migration + seeder, then run test**

```bash
C:\php\php.exe artisan migrate:fresh --seed
C:\php\php.exe vendor/bin/phpunit tests/Feature/GestorRoleTest.php --testdox
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php app/Models/User.php
git commit -m "feat: add gestor role to permission seeder and canAccessPanel"
```

---

## Task 2: Add profile fields to users table

**Files:**
- Create: `database/migrations/2026_06_19_000001_add_profile_fields_to_users_table.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create migration**

```php
<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('users', 'pin')) {
                $table->string('pin')->nullable()->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumnIfExists('first_name');
            $table->dropColumnIfExists('last_name');
            $table->dropColumnIfExists('phone');
            $table->dropColumnIfExists('pin');
        });
    }
};
```

- [ ] **Step 2: Update User model fillable and helpers**

```php
// In app/Models/User.php — update $fillable:
protected $fillable = [
    'name', 'first_name', 'last_name', 'email', 'password', 'phone', 'pin',
];

// Add after $fillable:
public function getFullNameAttribute(): string
{
    if ($this->first_name || $this->last_name) {
        return trim("{$this->first_name} {$this->last_name}");
    }
    return $this->name;
}

public function hasPin(): bool
{
    return $this->pin !== null;
}
```

- [ ] **Step 3: Run migration**

```bash
C:\php\php.exe artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_19_000001_add_profile_fields_to_users_table.php app/Models/User.php
git commit -m "feat: add first_name, last_name, phone, pin to users table"
```

---

## Task 3: Create user_invitations table and model

**Files:**
- Create: `database/migrations/2026_06_19_000002_create_user_invitations_table.php`
- Create: `app/Models/UserInvitation.php`
- Test: `tests/Feature/InvitationFlowTest.php` (partial)

- [ ] **Step 1: Create migration**

```php
<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('email');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
```

- [ ] **Step 2: Create UserInvitation model**

`app/Models/UserInvitation.php`:
```php
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    protected $fillable = [
        'email', 'role', 'token', 'invited_by_id', 'accepted_at', 'expires_at',
    ];

    protected $casts = [
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

    public static function findValid(string $token): ?self
    {
        return self::where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
```

- [ ] **Step 3: Run migration**

```bash
C:\php\php.exe artisan migrate
```

- [ ] **Step 4: Write model unit tests**

`tests/Feature/InvitationFlowTest.php` — first batch:
```php
<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(array $overrides = []): UserInvitation
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return UserInvitation::create(array_merge([
            'email'          => 'newuser@mmcrespo.pt',
            'role'           => 'tecnico',
            'token'          => Str::random(64),
            'invited_by_id'  => $admin->id,
            'expires_at'     => now()->addHours(48),
        ], $overrides));
    }

    public function test_pending_invitation_is_pending(): void
    {
        $inv = $this->makeInvitation();
        $this->assertTrue($inv->isPending());
        $this->assertFalse($inv->isExpired());
        $this->assertFalse($inv->isAccepted());
    }

    public function test_expired_invitation_is_not_pending(): void
    {
        $inv = $this->makeInvitation(['expires_at' => now()->subHour()]);
        $this->assertFalse($inv->isPending());
        $this->assertTrue($inv->isExpired());
    }

    public function test_accepted_invitation_is_not_pending(): void
    {
        $inv = $this->makeInvitation(['accepted_at' => now()]);
        $this->assertFalse($inv->isPending());
        $this->assertTrue($inv->isAccepted());
    }

    public function test_find_valid_returns_null_for_expired(): void
    {
        $inv = $this->makeInvitation(['expires_at' => now()->subHour()]);
        $this->assertNull(UserInvitation::findValid($inv->token));
    }

    public function test_find_valid_returns_invitation_when_valid(): void
    {
        $inv = $this->makeInvitation();
        $found = UserInvitation::findValid($inv->token);
        $this->assertNotNull($found);
        $this->assertEquals($inv->id, $found->id);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
C:\php\php.exe vendor/bin/phpunit tests/Feature/InvitationFlowTest.php --testdox
```

Expected: all 5 PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_19_000002_create_user_invitations_table.php app/Models/UserInvitation.php tests/Feature/InvitationFlowTest.php
git commit -m "feat: user_invitations table, model, and unit tests"
```

---

## Task 4: InvitationService

**Files:**
- Create: `app/Services/InvitationService.php`
- Test: `tests/Feature/InvitationFlowTest.php` (extend)

- [ ] **Step 1: Create service**

`app/Services/InvitationService.php`:
```php
<?php declare(strict_types=1);

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class InvitationService
{
    public function send(string $email, string $role, User $invitedBy): UserInvitation
    {
        // Block if a pending invitation already exists for this email
        $existing = UserInvitation::where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            throw new RuntimeException("Já existe um convite pendente para {$email}.");
        }

        // Block if user already exists
        if (User::where('email', $email)->exists()) {
            throw new RuntimeException("Já existe um utilizador com o email {$email}.");
        }

        $invitation = UserInvitation::create([
            'email'         => $email,
            'role'          => $role,
            'token'         => Str::random(64),
            'invited_by_id' => $invitedBy->id,
            'expires_at'    => now()->addHours(48),
        ]);

        Mail::to($email)->send(new UserInvitationMail($invitation));

        return $invitation;
    }

    public function accept(UserInvitation $invitation, array $data): User
    {
        $user = User::create([
            'name'       => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $invitation->email,
            'phone'      => $data['phone'] ?? null,
            'password'   => isset($data['password']) ? bcrypt($data['password']) : null,
            'pin'        => isset($data['pin']) ? bcrypt($data['pin']) : null,
        ]);

        $user->assignRole($invitation->role);

        $invitation->update(['accepted_at' => now()]);

        return $user;
    }
}
```

- [ ] **Step 2: Add service tests to InvitationFlowTest.php**

Add these methods to the existing test class:
```php
public function test_send_throws_if_pending_invitation_exists(): void
{
    $this->makeInvitation(['email' => 'dup@test.pt']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $service = new \App\Services\InvitationService();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('já existe um convite pendente');
    $service->send('dup@test.pt', 'tecnico', $admin);
}

public function test_send_throws_if_user_already_exists(): void
{
    $existing = User::factory()->create(['email' => 'existing@test.pt']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $service = new \App\Services\InvitationService();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Já existe um utilizador');
    $service->send('existing@test.pt', 'tecnico', $admin);
}

public function test_accept_creates_user_with_role(): void
{
    \Illuminate\Support\Facades\Mail::fake();
    $inv = $this->makeInvitation(['role' => 'tecnico']);
    $service = new \App\Services\InvitationService();

    $user = $service->accept($inv, [
        'first_name' => 'João',
        'last_name'  => 'Silva',
        'password'   => 'secret123',
    ]);

    $this->assertTrue($user->hasRole('tecnico'));
    $this->assertEquals('João Silva', $user->name);
    $inv->refresh();
    $this->assertNotNull($inv->accepted_at);
}
```

- [ ] **Step 3: Run tests**

```bash
C:\php\php.exe vendor/bin/phpunit tests/Feature/InvitationFlowTest.php --testdox
```

Expected: all PASS (Mail::fake() suppresses actual mail sending in tests).

- [ ] **Step 4: Commit**

```bash
git add app/Services/InvitationService.php tests/Feature/InvitationFlowTest.php
git commit -m "feat: InvitationService — send and accept logic with tests"
```

---

## Task 5: Invitation email

**Files:**
- Create: `app/Mail/UserInvitationMail.php`
- Create: `resources/views/emails/user-invitation.blade.php`

- [ ] **Step 1: Create Mailable**

`app/Mail/UserInvitationMail.php`:
```php
<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly UserInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Convite — Piscinas MMCrespo',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invitation',
            with: [
                'url'      => url('/convite/'.$this->invitation->token),
                'role'     => $this->invitation->role,
                'email'    => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at->format('d/m/Y \à\s H:i'),
            ],
        );
    }
}
```

- [ ] **Step 2: Create email view**

`resources/views/emails/user-invitation.blade.php`:
```html
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
  .btn { display: inline-block; background: #1d4ed8; color: #fff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; margin: 20px 0; }
  .role { background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 4px; padding: 4px 12px; font-weight: bold; }
  .footer { font-size: 12px; color: #888; margin-top: 30px; border-top: 1px solid #eee; padding-top: 16px; }
</style>
</head>
<body>
  <h2>Bem-vindo(a) à plataforma Piscinas MMCrespo</h2>
  <p>Foi convidado(a) para aceder à plataforma com o cargo <span class="role">{{ ucfirst($role) }}</span>.</p>
  <p>Clique no botão abaixo para completar o registo:</p>
  <a href="{{ $url }}" class="btn">Aceitar Convite</a>
  <p style="color:#888; font-size:13px">Link válido até {{ $expiresAt }}. Use apenas uma vez.</p>
  <div class="footer">
    <p>Piscinas MMCrespo · Este email foi enviado para {{ $email }}</p>
    <p>Se não esperava este convite, ignore este email.</p>
  </div>
</body>
</html>
```

- [ ] **Step 3: Commit**

```bash
git add app/Mail/UserInvitationMail.php resources/views/emails/user-invitation.blade.php
git commit -m "feat: UserInvitationMail and email template"
```

---

## Task 6: Invitation acceptance page (public route)

**Files:**
- Create: `app/Http/Controllers/InvitationController.php`
- Create: `resources/views/auth/accept-invitation.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create controller**

`app/Http/Controllers/InvitationController.php`:
```php
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $invitation = UserInvitation::findValid($token);

        if (! $invitation) {
            return view('auth.accept-invitation', ['expired' => true]);
        }

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'expired'    => false,
        ]);
    }

    public function store(Request $request, string $token, InvitationService $service): RedirectResponse
    {
        $invitation = UserInvitation::findValid($token);

        if (! $invitation) {
            return back()->withErrors(['token' => 'Este convite expirou ou já foi utilizado.']);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'password'   => ['nullable', 'string', 'min:8', 'confirmed'],
            'pin'        => ['nullable', 'digits_between:4,6'],
        ], [
            'first_name.required' => 'O primeiro nome é obrigatório.',
            'last_name.required'  => 'O último nome é obrigatório.',
            'password.min'        => 'A palavra-passe deve ter pelo menos 8 caracteres.',
            'pin.digits_between'  => 'O PIN deve ter entre 4 e 6 dígitos.',
        ]);

        if (empty($validated['password']) && empty($validated['pin'])) {
            return back()->withErrors(['password' => 'Deve definir uma palavra-passe ou um PIN.'])->withInput();
        }

        $user = $service->accept($invitation, $validated);

        Auth::login($user);

        return redirect('/admin')->with('success', 'Bem-vindo(a)! A sua conta foi criada com sucesso.');
    }
}
```

- [ ] **Step 2: Create acceptance view**

`resources/views/auth/accept-invitation.blade.php`:
```html
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aceitar Convite — Piscinas MMCrespo</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 40px; max-width: 440px; width: 100%; }
  .logo { font-size: 22px; font-weight: 800; color: #1d4ed8; margin-bottom: 8px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .meta { background: #f0f4ff; border-radius: 8px; padding: 12px 16px; margin: 20px 0; font-size: 14px; }
  .meta strong { color: #1d4ed8; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 4px; }
  input[type=text], input[type=email], input[type=tel], input[type=password], input[type=number] {
    width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 6px; font-size: 15px; outline: none;
  }
  input:focus { border-color: #1d4ed8; }
  .hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }
  .divider { text-align: center; color: #94a3b8; font-size: 12px; margin: 20px 0 4px; }
  .btn { width: 100%; padding: 13px; background: #1d4ed8; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 24px; }
  .btn:hover { background: #1e40af; }
  .error { color: #dc2626; font-size: 12px; margin-top: 4px; }
  .alert { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; color: #991b1b; text-align: center; }
  .badge { display: inline-block; background: #dbeafe; color: #1d4ed8; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 99px; text-transform: uppercase; letter-spacing: .5px; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">Piscinas MMCrespo</div>

  @if($expired)
    <div class="alert">
      <strong>Convite inválido ou expirado.</strong><br>
      Contacte o administrador para receber um novo convite.
    </div>
  @else
    <h1>Complete o seu registo</h1>
    <p style="color:#64748b; font-size:14px; margin:4px 0 0">Preencha os seus dados para ativar a conta.</p>

    <div class="meta">
      <div><strong>Email:</strong> {{ $invitation->email }}</div>
      <div style="margin-top:6px"><strong>Cargo:</strong> <span class="badge">{{ $invitation->role }}</span></div>
    </div>

    @if($errors->any())
      <div class="alert" style="margin-bottom:12px">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="/convite/{{ $invitation->token }}">
      @csrf

      <label>Primeiro nome *</label>
      <input type="text" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name">

      <label>Último nome *</label>
      <input type="text" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name">

      <label>Telefone</label>
      <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="+351 9XX XXX XXX">
      <div class="hint">Opcional — usado para contacto em caso de urgência.</div>

      <div class="divider">― Credenciais de acesso ―</div>
      <p style="font-size:12px; color:#64748b; margin:0 0 12px">Defina uma palavra-passe, um PIN, ou ambos.</p>

      <label>Palavra-passe</label>
      <input type="password" name="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres">

      <label>Confirmar palavra-passe</label>
      <input type="password" name="password_confirmation" autocomplete="new-password">

      <label>PIN numérico <span style="font-weight:400; color:#94a3b8">(4–6 dígitos)</span></label>
      <input type="number" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" placeholder="Ex: 1234" min="0" max="999999">
      <div class="hint">Para acesso rápido em tablet ou telemóvel.</div>

      <button class="btn" type="submit">Criar conta</button>
    </form>
  @endif
</div>
</body>
</html>
```

- [ ] **Step 3: Register routes**

`routes/web.php` — add before the existing routes:
```php
Route::get('/convite/{token}', [\App\Http\Controllers\InvitationController::class, 'show'])
    ->name('invitation.show');

Route::post('/convite/{token}', [\App\Http\Controllers\InvitationController::class, 'store'])
    ->name('invitation.store')
    ->middleware('throttle:10,1');
```

- [ ] **Step 4: Add HTTP tests to InvitationFlowTest**

```php
public function test_invitation_page_shows_form_for_valid_token(): void
{
    $inv = $this->makeInvitation();
    $response = $this->get("/convite/{$inv->token}");
    $response->assertStatus(200);
    $response->assertSee($inv->email);
    $response->assertSee($inv->role);
}

public function test_invitation_page_shows_expired_for_bad_token(): void
{
    $response = $this->get('/convite/invalidtoken123');
    $response->assertStatus(200);
    $response->assertSee('Convite inválido ou expirado');
}

public function test_accepting_invitation_creates_user_and_logs_in(): void
{
    \Illuminate\Support\Facades\Mail::fake();
    $inv = $this->makeInvitation(['role' => 'tecnico']);

    $response = $this->post("/convite/{$inv->token}", [
        'first_name' => 'Ana',
        'last_name'  => 'Costa',
        'password'   => 'secret12345',
        'password_confirmation' => 'secret12345',
    ]);

    $response->assertRedirect('/admin');
    $this->assertDatabaseHas('users', ['email' => $inv->email, 'first_name' => 'Ana']);
    $this->assertAuthenticated();
}

public function test_submitting_without_password_or_pin_fails(): void
{
    \Illuminate\Support\Facades\Mail::fake();
    $inv = $this->makeInvitation();

    $response = $this->post("/convite/{$inv->token}", [
        'first_name' => 'Ana',
        'last_name'  => 'Costa',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('password');
}
```

- [ ] **Step 5: Run all flow tests**

```bash
C:\php\php.exe vendor/bin/phpunit tests/Feature/InvitationFlowTest.php --testdox
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/InvitationController.php resources/views/auth/accept-invitation.blade.php routes/web.php
git commit -m "feat: public invitation acceptance route, controller, and view"
```

---

## Task 7: PIN login — Custom Filament Login page

**Files:**
- Create: `app/Filament/Pages/Auth/Login.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/PinLoginTest.php`

- [ ] **Step 1: Write PIN login tests first**

`tests/Feature/PinLoginTest.php`:
```php
<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $user->assignRole('tecnico');

        $response = $this->post('/admin/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $this->assertAuthenticated();
    }

    public function test_user_can_login_with_pin(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'pin'      => Hash::make('1234'),
        ]);
        $user->assignRole('tecnico');

        $response = $this->post('/admin/login', [
            'email'    => $user->email,
            'password' => '1234',
        ]);

        $this->assertAuthenticated();
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $user = User::factory()->create(['pin' => Hash::make('1234')]);
        $user->assignRole('tecnico');

        $this->post('/admin/login', [
            'email'    => $user->email,
            'password' => '9999',
        ]);

        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Create custom Login page**

`app/Filament/Pages/Auth/Login.php`:
```php
<?php declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email'    => $data['email'],
            'password' => $data['password'],
        ];
    }

    public function authenticate(): ?\Filament\Http\Responses\Auth\Contracts\LoginResponse
    {
        $data     = $this->form->getState();
        $email    = $data['email'];
        $password = (string) $data['password'];

        $user = \App\Models\User::where('email', $email)->first();

        if ($user) {
            // If input is 4–6 digits and user has a PIN, try PIN authentication
            $isPinAttempt = ctype_digit($password) && strlen($password) >= 4 && strlen($password) <= 6;

            if ($isPinAttempt && $user->pin && Hash::check($password, $user->pin)) {
                Auth::login($user, $data['remember'] ?? false);
                return app(\Filament\Http\Responses\Auth\LoginResponse::class);
            }

            // Fall through to standard password authentication
            if ($user->password && Hash::check($password, $user->password)) {
                Auth::login($user, $data['remember'] ?? false);
                return app(\Filament\Http\Responses\Auth\LoginResponse::class);
            }
        }

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
```

- [ ] **Step 3: Register custom login in AdminPanelProvider**

`app/Providers/Filament/AdminPanelProvider.php` — replace `.login()` with:
```php
->login(\App\Filament\Pages\Auth\Login::class)
```

- [ ] **Step 4: Run PIN tests**

```bash
C:\php\php.exe vendor/bin/phpunit tests/Feature/PinLoginTest.php --testdox
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/Auth/Login.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/PinLoginTest.php
git commit -m "feat: custom Filament login with PIN auto-detection"
```

---

## Task 8: Filament — Invite action in UserResource

**Files:**
- Modify: `app/Filament/Resources/UserResource.php`
- Modify: `app/Filament/Resources/UserResource/Pages/ListUsers.php`

- [ ] **Step 1: Add "Convidar Utilizador" header action to ListUsers**

`app/Filament/Resources/UserResource/Pages/ListUsers.php`:
```php
<?php declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\InvitationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convidar')
                ->label('Convidar Utilizador')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->form([
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required(),
                    Select::make('role')
                        ->label('Cargo')
                        ->options([
                            'gestor'            => 'Gestor',
                            'tecnico'           => 'Técnico',
                            'nadador_salvador'  => 'Nadador Salvador',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(InvitationService::class)->send(
                            $data['email'],
                            $data['role'],
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Convite enviado')
                            ->body("Convite enviado para {$data['email']}.")
                            ->success()
                            ->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('Erro')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => auth()->user()?->hasAnyRole(['admin', 'gestor'])),

            \Filament\Actions\CreateAction::make(),
        ];
    }
}
```

- [ ] **Step 2: Update UserResource form with first_name/last_name/phone**

In `app/Filament/Resources/UserResource.php` — update the form schema to include:
```php
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;

// Replace the 'name' field with:
Grid::make(2)->schema([
    TextInput::make('first_name')
        ->label('Primeiro nome')
        ->maxLength(100),
    TextInput::make('last_name')
        ->label('Último nome')
        ->maxLength(100),
]),
TextInput::make('name')
    ->label('Nome completo')
    ->maxLength(255)
    ->helperText('Preenchido automaticamente a partir do primeiro e último nome.'),
TextInput::make('phone')
    ->label('Telefone')
    ->maxLength(20),
```

- [ ] **Step 3: Update table columns**

Add to the table columns in UserResource:
```php
Tables\Columns\TextColumn::make('full_name')
    ->label('Nome')
    ->searchable(['first_name', 'last_name', 'name'])
    ->sortable(),
Tables\Columns\TextColumn::make('phone')
    ->label('Telefone')
    ->toggleable(),
```

- [ ] **Step 4: Manual test via browser**

```bash
C:\php\php.exe artisan serve
```

Navigate to http://localhost:8000/admin/users → click "Convidar Utilizador" → fill email + cargo → check mail log at `storage/logs/laravel.log` for sent email content.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/UserResource.php app/Filament/Resources/UserResource/Pages/ListUsers.php
git commit -m "feat: invite action in UserResource, first_name/last_name/phone fields"
```

---

## Task 9: Seed real accounts + gestor access to resources

**Files:**
- Modify: `database/seeders/UserSeeder.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Update UserSeeder with real accounts**

`database/seeders/UserSeeder.php`:
```php
<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Real production accounts
        $daniel = User::firstOrCreate(
            ['email' => 'daniel@mmcrespo.pt'],
            [
                'name'       => 'Daniel Paz',
                'first_name' => 'Daniel',
                'last_name'  => 'Paz',
                'password'   => Hash::make(env('ADMIN_PASSWORD_DANIEL', 'changeme123!')),
            ]
        );
        $daniel->syncRoles(['admin']);

        $marcio = User::firstOrCreate(
            ['email' => 'marcio@mmcrespo.pt'],
            [
                'name'       => 'Márcio',
                'first_name' => 'Márcio',
                'last_name'  => '',
                'password'   => Hash::make(env('ADMIN_PASSWORD_MARCIO', 'changeme123!')),
            ]
        );
        $marcio->syncRoles(['admin']);

        // Dev/test accounts (only in non-production)
        if (! app()->isProduction()) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@mmcrespo.pt'],
                ['name' => 'Admin Teste', 'password' => Hash::make('password')]
            );
            $admin->syncRoles(['admin']);

            $tec = User::firstOrCreate(
                ['email' => 'tecnico@mmcrespo.pt'],
                ['name' => 'Técnico Teste', 'password' => Hash::make('password')]
            );
            $tec->syncRoles(['tecnico']);

            $ns = User::firstOrCreate(
                ['email' => 'ns@mmcrespo.pt'],
                ['name' => 'NS Teste', 'password' => Hash::make('password')]
            );
            $ns->syncRoles(['nadador_salvador']);
        }
    }
}
```

- [ ] **Step 2: Set env vars for production passwords**

In `.env` add:
```
ADMIN_PASSWORD_DANIEL=changeme123!
ADMIN_PASSWORD_MARCIO=changeme123!
```

In Railway, set real strong passwords before going live.

- [ ] **Step 3: Run seeder**

```bash
C:\php\php.exe artisan db:seed --class=UserSeeder
```

- [ ] **Step 4: Set correct resource access per role**

**UserResource** — update from Admin-only to Admin+Gestor:
```php
// app/Filament/Resources/UserResource.php
public static function canAccess(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'gestor']);
}
```

**StockInstallationLogResource** — currently unrestricted, block gestor:
```php
// app/Filament/Resources/StockInstallationLogResource.php
public static function canAccess(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'tecnico']);
}
```

**StockWarehouseLogResource** — currently unrestricted, block gestor:
```php
// app/Filament/Resources/StockWarehouseLogResource.php
public static function canAccess(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'tecnico']);
}
```

All other restricted resources are already correct:
- `InstallationResource`, `PoolResource`, `HannaDeviceResource`, `UserResource` → Admin only (gestor blocked ✓)
- `ProductResource`, `StockInstallationResource`, `StockWarehouseResource` → Admin+Técnico (gestor blocked ✓)
- `ActivityLog` plugin → Admin only in AdminPanelProvider (gestor blocked ✓)
- `DailyRecordResource`, `FilterCheckResource`, `IncidentResource`, `AnaliseParametros`, `RelatorioPdf` → No role restriction (gestor can access ✓)

- [ ] **Step 5: Commit**

```bash
git add database/seeders/UserSeeder.php app/Filament/Resources/UserResource.php .env
git commit -m "feat: real admin accounts in seeder, gestor access to UserResource"
```

---

## Task 10: Final run — all tests green

- [ ] **Step 1: Run full test suite**

```bash
C:\php\php.exe vendor/bin/phpunit --testdox
```

Expected: all PASS. Fix any failures before proceeding.

- [ ] **Step 2: Migrate fresh + seed to verify clean state**

```bash
C:\php\php.exe artisan migrate:fresh --seed
```

- [ ] **Step 3: Smoke test in browser**

```bash
C:\php\php.exe artisan serve
```

1. Login as `admin@mmcrespo.pt` / `password`
2. Go to Users → click "Convidar Utilizador"
3. Enter a test email + role → submit
4. Check `storage/logs/laravel.log` for invitation email content + URL
5. Open the `/convite/{token}` URL manually
6. Complete form with password OR PIN
7. Verify redirect to /admin and user created

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete invitation system — invite, accept, PIN login, gestor role"
```

---

## Spec Coverage Checklist

| Requirement | Task |
|---|---|
| Admin + Gestor can invite users | Task 8 (ListUsers action) |
| Role pre-filled from token, read-only | Task 6 (accept-invitation.blade.php) |
| Email pre-filled, read-only | Task 6 |
| first_name, last_name, phone fields | Tasks 2 + 8 |
| Password OR PIN (at least one required) | Tasks 4 + 6 |
| PIN stored hashed | Task 4 (InvitationService) |
| Invitations expire 48h | Task 3 (UserInvitation model) |
| One pending per email guard | Task 4 (InvitationService) |
| Gestor role created + has panel access | Task 1 |
| daniel@mmcrespo.pt + marcio@mmcrespo.pt as admin | Task 9 |
| PIN login auto-detect | Task 7 |
| Audit trail (invited_by_id, accepted_at) | Task 3 |
