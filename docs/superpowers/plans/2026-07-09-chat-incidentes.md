# Chat de Incidentes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir a comunicação por WhatsApp/telefone por uma thread de mensagens dentro de cada `Incident`, com notificação imediata (sino do Filament) a quem tem de agir.

**Architecture:** Nova tabela `incident_messages` (append-only, `tipo` = `mensagem` | `sistema`). `CreateIncident` e a ação "Resolver" passam a gravar mensagens de sistema automáticas. Um novo `Filament\Widgets\Widget` (`IncidentChatWidget`) mostra a timeline e o campo de resposta, registado como footer widget de `ViewIncident`. Notificações via `Illuminate\Notifications\Notification` com canal `database`, seguindo o padrão já usado em `HannaOvertimeAlert`/`HannaThresholdAlert`.

**Tech Stack:** Laravel 12, Filament 3.3, Livewire (via `Filament\Widgets\Widget`), spatie/laravel-permission, PHPUnit + Livewire testing helpers.

---

### Task 1: Migração `incident_messages`

**Files:**
- Create: `database/migrations/2026_07_09_000002_create_incident_messages_table.php`

- [ ] **Step 1: Escrever a migração**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thread de mensagens por incidente: mistura mensagens de chat (tipo=mensagem)
// com mensagens automáticas de mudança de estado (tipo=sistema) na mesma
// timeline, para substituir a comunicação por WhatsApp/telefone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('tipo', 20)->default('mensagem');
            $table->text('texto');
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_messages');
    }
};
```

- [ ] **Step 2: Correr a migração**

Run: `php artisan migrate`
Expected: `2026_07_09_000002_create_incident_messages_table ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_09_000002_create_incident_messages_table.php
git commit -m "feat: criar tabela incident_messages para a thread de incidentes"
```

---

### Task 2: Model `IncidentMessage` + relação em `Incident`

**Files:**
- Create: `app/Models/IncidentMessage.php`
- Create: `database/factories/IncidentMessageFactory.php`
- Modify: `app/Models/Incident.php`
- Test: `tests/Unit/Models/IncidentMessageTest.php`

- [ ] **Step 1: Escrever o teste (falha porque a classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_belongs_to_incident_and_author(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create(['name' => 'Ana']);
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $mensagem = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'A situação está a agravar-se.',
        ]);

        $this->assertTrue($mensagem->incidente->is($incidente));
        $this->assertTrue($mensagem->autor->is($user));
        $this->assertFalse($mensagem->eSistema());
    }

    public function test_incident_lists_messages_ordered_by_created_at(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create();
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'outro',
            'descricao' => 'Problema qualquer',
            'status' => 'aberto',
        ]);

        $primeira = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_SISTEMA,
            'texto' => 'Incidente reportado: Problema qualquer',
        ]);
        $segunda = IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'A caminho.',
        ]);

        $ids = $incidente->mensagens()->pluck('id')->all();

        $this->assertSame([$primeira->id, $segunda->id], $ids);
    }
}
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

Run: `php artisan test --filter=IncidentMessageTest`
Expected: FAIL com `Class "App\Models\IncidentMessage" not found`

- [ ] **Step 3: Criar o model `IncidentMessage`**

```php
<?php declare(strict_types=1);
namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentMessage extends Model
{
    use HasFactory;

    public const TIPO_MENSAGEM = 'mensagem';
    public const TIPO_SISTEMA = 'sistema';

    protected $fillable = [
        'incident_id', 'user_id', 'tipo', 'texto',
    ];

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eSistema(): bool
    {
        return $this->tipo === self::TIPO_SISTEMA;
    }
}
```

- [ ] **Step 4: Criar a factory**

```php
<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentMessage>
 */
class IncidentMessageFactory extends Factory
{
    protected $model = IncidentMessage::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => fake()->sentence(),
        ];
    }
}
```

- [ ] **Step 5: Adicionar a relação `mensagens()` a `Incident`**

Modificar `app/Models/Incident.php`, adicionando o import e o método (depois de `resolvidoPor()`):

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
    /**
     * @return HasMany
     */
    public function mensagens(): HasMany
    {
        return $this->hasMany(IncidentMessage::class, 'incident_id')->orderBy('created_at');
    }
```

- [ ] **Step 6: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=IncidentMessageTest`
Expected: PASS (2 testes)

- [ ] **Step 7: Commit**

```bash
git add app/Models/IncidentMessage.php app/Models/Incident.php database/factories/IncidentMessageFactory.php tests/Unit/Models/IncidentMessageTest.php
git commit -m "feat: model IncidentMessage e relacao mensagens() em Incident"
```

---

### Task 3: Corrigir `IncidentPolicy::create` (Gestor não conseguia criar incidentes)

**Files:**
- Modify: `app/Policies/IncidentPolicy.php:26-29`
- Test: `tests/Unit/Policies/IncidentPolicyTest.php`

**Contexto:** `create()` hoje só permite `ADMIN`, `TECNICO`, `NADADOR_SALVADOR` — falta `GESTOR`. Isto bloqueava silenciosamente `IncidentResource::canCreate()` para o role Gestor, apesar de o requisito ser "todos os 4 roles podem criar".

- [ ] **Step 1: Escrever o teste (falha com o bug atual)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Constants\UserRole;
use App\Models\User;
use App\Policies\IncidentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_all_four_roles_can_create_incidents(): void
    {
        $policy = new IncidentPolicy();

        foreach (UserRole::all() as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue(
                $policy->create($user),
                "Role '{$role}' deveria conseguir criar incidentes"
            );
        }
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha para `gestor`**

Run: `php artisan test --filter=IncidentPolicyTest`
Expected: FAIL — assertion falsa para o role `gestor`

- [ ] **Step 3: Corrigir a policy**

Em `app/Policies/IncidentPolicy.php`, substituir:

```php
    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::NADADOR_SALVADOR]);
    }
```

por:

```php
    public function create(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=IncidentPolicyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Policies/IncidentPolicy.php tests/Unit/Policies/IncidentPolicyTest.php
git commit -m "fix: permitir Gestor criar incidentes (role em falta na policy)"
```

---

### Task 4: Notification classes

**Files:**
- Create: `app/Notifications/IncidentCreatedNotification.php`
- Create: `app/Notifications/IncidentMessageNotification.php`

- [ ] **Step 1: Criar `IncidentCreatedNotification`**

```php
<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparado quando um incidente é reportado — chega ao sino de Admin/Técnico
 * para que ajam sem depender de WhatsApp/telefone.
 */
class IncidentCreatedNotification extends Notification
{
    public function __construct(
        private readonly Incident $incident,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // TODO: adicionar 'mail' aqui quando o SMTP estiver configurado.
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return new DatabaseMessage([
            'title' => "Novo incidente — {$instalacao}",
            'body' => "{$reportante}: {$this->incident->descricao}",
            'format' => 'filament',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
        ]);
    }
}
```

- [ ] **Step 2: Criar `IncidentMessageNotification`**

```php
<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparado a cada mensagem nova na thread de um incidente — mensagem de
 * chat ou mudança de estado gerada pelo sistema (ex: resolução).
 */
class IncidentMessageNotification extends Notification
{
    public function __construct(
        private readonly Incident $incident,
        private readonly User $autor,
        private readonly string $texto,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // TODO: adicionar 'mail' aqui quando o SMTP estiver configurado.
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return new DatabaseMessage([
            'title' => "Incidente — {$instalacao}: {$this->autor->name}",
            'body' => $this->texto,
            'format' => 'filament',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => 'warning',
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Notifications/IncidentCreatedNotification.php app/Notifications/IncidentMessageNotification.php
git commit -m "feat: notificacoes de incidente criado e de nova mensagem na thread"
```

(Sem teste isolado — cobertas pelos testes de integração das Tasks 5, 6 e 7, que verificam `Notification::fake()`.)

---

### Task 5: `CreateIncident` grava mensagem inicial e notifica Admin/Técnico

**Files:**
- Modify: `app/Filament/Resources/IncidentResource/Pages/CreateIncident.php`
- Test: `tests/Feature/IncidentChatTest.php` (criado nesta task, ampliado nas Tasks 6 e 7)

- [ ] **Step 1: Escrever o teste (falha porque `afterCreate` ainda não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Installation;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_creating_incident_posts_system_message_and_notifies_admin_and_tecnico(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($ns);

        Livewire::test(CreateIncident::class)
            ->fillForm([
                'installation_id' => $inst->id,
                'user_id' => $ns->id,
                'ocorreu_em' => now(),
                'type' => 'fuga_agua',
                'descricao' => 'Fuga junto ao filtro',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $incidente = Incident::first();
        $this->assertNotNull($incidente);

        $mensagem = IncidentMessage::where('incident_id', $incidente->id)->first();
        $this->assertNotNull($mensagem);
        $this->assertSame(IncidentMessage::TIPO_SISTEMA, $mensagem->tipo);
        $this->assertStringContainsString('Fuga junto ao filtro', $mensagem->texto);

        Notification::assertSentTo($admin, IncidentCreatedNotification::class);
        Notification::assertSentTo($tecnico, IncidentCreatedNotification::class);
        Notification::assertNotSentTo($ns, IncidentCreatedNotification::class);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=test_creating_incident_posts_system_message_and_notifies_admin_and_tecnico`
Expected: FAIL — nenhuma `IncidentMessage` é criada (não há `afterCreate`)

- [ ] **Step 3: Implementar `afterCreate` em `CreateIncident`**

```php
<?php declare(strict_types=1);
namespace App\Filament\Resources\IncidentResource\Pages;


use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource;
use App\Models\IncidentMessage;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Notification;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar incidente')
                ->modalDescription('Confirme que a informação do incidente está correta antes de submeter.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterCreate(): void
    {
        $incident = $this->record;

        IncidentMessage::create([
            'incident_id' => $incident->id,
            'user_id' => $incident->user_id,
            'tipo' => IncidentMessage::TIPO_SISTEMA,
            'texto' => "Incidente reportado: {$incident->descricao}",
        ]);

        Notification::send(
            User::role([UserRole::ADMIN, UserRole::TECNICO])->get(),
            new IncidentCreatedNotification($incident)
        );
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=test_creating_incident_posts_system_message_and_notifies_admin_and_tecnico`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/IncidentResource/Pages/CreateIncident.php tests/Feature/IncidentChatTest.php
git commit -m "feat: mensagem inicial e notificacao a admin/tecnico ao criar incidente"
```

---

### Task 6: Ação "Resolver" grava mensagem de sistema e notifica o reportante

**Files:**
- Modify: `app/Filament/Resources/IncidentResource.php:169-203`
- Test: `tests/Feature/IncidentChatTest.php` (adicionar método)

- [ ] **Step 1: Adicionar o teste (falha porque a mensagem/notificação não é gravada)**

Adicionar a `tests/Feature/IncidentChatTest.php`:

```php
    public function test_resolving_incident_posts_system_message_and_notifies_reporter(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $this->actingAs($tecnico);

        \Livewire\Livewire::test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->callTableAction('resolver', $incidente, data: [
                'resolucao' => 'Junta do filtro substituída, sem fugas após 30 min de teste.',
            ]);

        $incidente->refresh();
        $this->assertSame('resolvido', $incidente->status);

        $mensagem = $incidente->mensagens()->latest('id')->first();
        $this->assertSame(IncidentMessage::TIPO_SISTEMA, $mensagem->tipo);
        $this->assertStringContainsString('Resolvido', $mensagem->texto);
        $this->assertStringContainsString('Junta do filtro substituída', $mensagem->texto);

        Notification::assertSentTo($ns, \App\Notifications\IncidentMessageNotification::class);
    }
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=test_resolving_incident_posts_system_message_and_notifies_reporter`
Expected: FAIL — `mensagens()` vazio

- [ ] **Step 3: Atualizar a ação "resolver" em `IncidentResource::table()`**

Substituir o bloco `->action(function (Incident $record, array $data): void { ... })` (linhas 185-203) por:

```php
                    ->action(function (Incident $record, array $data): void {
                        if (! auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                            Notification::make()->danger()->title('Sem permissão')->send();
                            return;
                        }

                        $record->update([
                            'status' => 'resolvido',
                            'resolvido_em' => now(),
                            'resolvido_por' => auth()->id(),
                            'resolucao' => $data['resolucao'],
                        ]);

                        $texto = "Estado alterado para: Resolvido — {$data['resolucao']}";

                        \App\Models\IncidentMessage::create([
                            'incident_id' => $record->id,
                            'user_id' => auth()->id(),
                            'tipo' => \App\Models\IncidentMessage::TIPO_SISTEMA,
                            'texto' => $texto,
                        ]);

                        $record->utilizador?->notify(new \App\Notifications\IncidentMessageNotification(
                            $record,
                            auth()->user(),
                            $texto,
                        ));

                        Notification::make()
                            ->success()
                            ->title('Incidente resolvido')
                            ->body('Saiu do quadro de operação.')
                            ->send();
                    }),
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=test_resolving_incident_posts_system_message_and_notifies_reporter`
Expected: PASS

- [ ] **Step 5: Correr toda a suite de Incident para garantir que nada quebrou**

Run: `php artisan test --filter=Incident`
Expected: PASS (todos os testes de `IncidentTest`, `IncidentPolicyTest`, `IncidentMessageTest`, `IncidentChatTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/IncidentResource.php tests/Feature/IncidentChatTest.php
git commit -m "feat: acao Resolver grava mensagem de sistema e notifica o reportante"
```

---

### Task 7: `IncidentChatWidget` — timeline + resposta + reabertura automática

**Files:**
- Create: `app/Filament/Widgets/IncidentChatWidget.php`
- Create: `resources/views/filament/widgets/incident-chat.blade.php`
- Modify: `app/Filament/Resources/IncidentResource/Pages/ViewIncident.php`
- Test: `tests/Feature/IncidentChatTest.php` (adicionar métodos)

- [ ] **Step 1: Adicionar os testes (falham porque o widget não existe)**

Adicionar a `tests/Feature/IncidentChatTest.php`:

```php
    public function test_reporter_message_notifies_admin_and_tecnico_not_other_ns(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $outroNs = User::factory()->create();
        $outroNs->assignRole(UserRole::NADADOR_SALVADOR);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $this->actingAs($ns);

        \Livewire\Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', 'A situação está a agravar-se.')
            ->call('enviarMensagem');

        $this->assertSame(1, $incidente->mensagens()->count());

        Notification::assertSentTo($admin, \App\Notifications\IncidentMessageNotification::class);
        Notification::assertNotSentTo($outroNs, \App\Notifications\IncidentMessageNotification::class);
    }

    public function test_new_message_reopens_resolved_incident(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'resolvido',
            'resolvido_em' => now(),
            'resolvido_por' => $tecnico->id,
            'resolucao' => 'Junta substituída.',
        ]);

        $this->actingAs($ns);

        \Livewire\Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', 'Voltou a haver fuga.')
            ->call('enviarMensagem');

        $incidente->refresh();

        $this->assertSame('aberto', $incidente->status);
        $this->assertNull($incidente->resolvido_em);
        $this->assertNull($incidente->resolvido_por);
        $this->assertNull($incidente->resolucao);

        $textos = $incidente->mensagens()->pluck('texto')->all();
        $this->assertContains('Voltou a haver fuga.', $textos);
        $this->assertContains('Reaberto automaticamente após nova mensagem.', $textos);
    }

    public function test_blank_message_is_ignored(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'outro',
            'descricao' => 'Problema qualquer',
            'status' => 'aberto',
        ]);

        $this->actingAs($ns);

        \Livewire\Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', '   ')
            ->call('enviarMensagem');

        $this->assertSame(0, $incidente->mensagens()->count());
    }
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test --filter=IncidentChatTest`
Expected: FAIL — `Class "App\Filament\Widgets\IncidentChatWidget" not found`

- [ ] **Step 3: Criar `IncidentChatWidget`**

```php
<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Constants\UserRole;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use App\Notifications\IncidentMessageNotification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Timeline de mensagens de um incidente (chat entre quem reporta e quem
 * corrige) + caixa de resposta. Mistura mensagens livres com mensagens
 * automáticas de mudança de estado geradas noutros pontos (CreateIncident,
 * ação "Resolver"). Responder a um incidente resolvido reabre-o.
 */
class IncidentChatWidget extends Widget
{
    protected static string $view = 'filament.widgets.incident-chat';

    protected int|string|array $columnSpan = 'full';

    public ?Incident $record = null;

    public string $texto = '';

    public function enviarMensagem(): void
    {
        $texto = trim($this->texto);

        if ($texto === '' || $this->record === null) {
            return;
        }

        $incident = $this->record;
        $autor = auth()->user();

        DB::transaction(function () use ($incident, $autor, $texto): void {
            IncidentMessage::create([
                'incident_id' => $incident->id,
                'user_id' => $autor->id,
                'tipo' => IncidentMessage::TIPO_MENSAGEM,
                'texto' => $texto,
            ]);

            if ($incident->status === 'resolvido') {
                $incident->update([
                    'status' => 'aberto',
                    'resolvido_em' => null,
                    'resolvido_por' => null,
                    'resolucao' => null,
                ]);

                IncidentMessage::create([
                    'incident_id' => $incident->id,
                    'user_id' => $autor->id,
                    'tipo' => IncidentMessage::TIPO_SISTEMA,
                    'texto' => 'Reaberto automaticamente após nova mensagem.',
                ]);
            }
        });

        if ($autor->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            $incident->utilizador?->notify(new IncidentMessageNotification($incident, $autor, $texto));
        } else {
            Notification::send(
                User::role([UserRole::ADMIN, UserRole::TECNICO])->get(),
                new IncidentMessageNotification($incident, $autor, $texto)
            );
        }

        $this->texto = '';
        $this->record->refresh();
    }
}
```

- [ ] **Step 4: Criar a view do widget**

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Conversa</x-slot>

        <div class="mmc-incident-chat space-y-3 mb-4 max-h-96 overflow-y-auto">
            @forelse ($record?->mensagens ?? [] as $mensagem)
                @if ($mensagem->eSistema())
                    <div class="text-center">
                        <span class="inline-block rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $mensagem->texto }} · {{ $mensagem->created_at->format('d/m H:i') }}
                        </span>
                    </div>
                @else
                    <div class="flex {{ $mensagem->user_id === auth()->id() ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[75%] rounded-lg px-3 py-2 {{ $mensagem->user_id === auth()->id() ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-800' }}">
                            <p class="text-xs opacity-75 mb-0.5">{{ $mensagem->autor->name }}</p>
                            <p class="text-sm">{{ $mensagem->texto }}</p>
                            <p class="text-xs opacity-60 mt-0.5">{{ $mensagem->created_at->format('d/m H:i') }}</p>
                        </div>
                    </div>
                @endif
            @empty
                <p class="text-sm text-gray-500">Ainda sem mensagens.</p>
            @endforelse
        </div>

        <form wire:submit.prevent="enviarMensagem" class="flex gap-2">
            <textarea
                wire:model="texto"
                rows="2"
                class="fi-input flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900"
                placeholder="Escreva uma mensagem..."
            ></textarea>
            <x-filament::button type="submit">Enviar</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
```

- [ ] **Step 5: Registar o widget em `ViewIncident`**

```php
<?php declare(strict_types=1);
namespace App\Filament\Resources\IncidentResource\Pages;


use App\Filament\Resources\IncidentResource;
use App\Filament\Widgets\IncidentChatWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewIncident extends ViewRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            IncidentChatWidget::class,
        ];
    }
}
```

- [ ] **Step 6: Correr os testes e confirmar que passam**

Run: `php artisan test --filter=IncidentChatTest`
Expected: PASS (todos os métodos da classe)

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Widgets/IncidentChatWidget.php resources/views/filament/widgets/incident-chat.blade.php app/Filament/Resources/IncidentResource/Pages/ViewIncident.php tests/Feature/IncidentChatTest.php
git commit -m "feat: widget de chat na vista do incidente com reabertura automatica"
```

---

### Task 8: Verificação final

**Files:** nenhum (só verificação)

- [ ] **Step 1: Correr a suite completa**

Run: `php artisan test`
Expected: PASS em todos os testes (nenhuma regressão nos testes existentes de `Incident`, `AlertasService`, `QuadroOperacionalWidget`, etc.)

- [ ] **Step 2: Verificação manual no browser (dev)**

1. Login como Nadador-Salvador → Registo Diário → Incidentes → Criar novo → confirmar que aparece a mensagem "Incidente reportado: ..." na vista.
2. Login como Admin → confirmar notificação no sino → abrir o incidente → responder.
3. Voltar a login NS → confirmar notificação no sino → abrir e responder.
4. Login como Técnico → "Resolver" o incidente → confirmar mensagem de sistema "Estado alterado para: Resolvido".
5. Voltar a login NS → responder ao incidente resolvido → confirmar que reabre (badge de estado volta a "Aberto" na listagem).

- [ ] **Step 3: Commit final (se houver ajustes da verificação manual)**

```bash
git add -A
git commit -m "fix: ajustes pos-verificacao manual do chat de incidentes"
```
(só se necessário — sem alterações, não criar commit vazio)
