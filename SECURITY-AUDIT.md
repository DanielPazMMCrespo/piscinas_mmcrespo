# Auditoria de Segurança — Piscinas MMCrespo

**Data:** 2026-08-19
**Branch auditado:** `test`
**Produção confirmada:** `https://piscinasmmcrespo.up.railway.app/admin`
**Perfil do atacante simulado:** uma conta `tecnico` + cópia do repositório. Também se avalia o visitante anónimo.

> **NÃO COMMITAR ESTE FICHEIRO.** Já está no `.gitignore`.

---

## 1. Método e limites

O que fiz:

- Leitura do código. Cada achado tem `ficheiro:linha`.
- `composer audit` e `npm audit` executados de verdade. Saída real citada.
- Pesquisa do histórico Git completo (798 commits) por segredos.

O que **não** fiz:

- Nenhum pedido a produção. Nem um GET.
- Nenhum exploit executado. **Não existe instância local a correr nem cópia de staging acessível.** Todos os comandos de prova abaixo estão marcados `NÃO EXECUTADO`.
- Não contei linhas afetadas. Sem acesso à base de dados de produção, o raio é descrito por *âmbito* (que tabelas, que utilizadores), não por número. Os comandos para obter os números estão no fim.

Quando não consegui confirmar algo, está marcado `NÃO VERIFICADO` com o que falta.

---

## 2. Ambiente confirmado

| Item | Realidade |
|---|---|
| Backend | Laravel 12, PHP 8.4, painel Filament 3.3 |
| Base de dados | PostgreSQL 16 (Railway). SQLite em dev |
| Autenticação | Sessão em cookie cifrado. Password **ou** PIN de 4–6 dígitos |
| Autorização | **Só PHP.** Sem RLS, sem políticas na base de dados. 47 tabelas, zero regras ao nível da linha |
| Ingestão de sensores | **Não existe endpoint de entrada.** `hanna:sync` puxa da API da Hanna |
| Rede pública do Postgres | `NÃO VERIFICADO` — ver §7 |
| Backups | Comando existe, escreve para disco efémero. Ver H6 |

**Contexto que agrava tudo:** a autorização vive inteiramente em código PHP. Não há segunda linha de defesa. Um `canAccess()` esquecido ou uma policy em falta é acesso concedido, não acesso reduzido.

---

## 3. Resumo dos achados

| # | Achado | Gravidade | Quem explora |
|---|---|---|---|
| C1 | `/m` sem login, PWA aponta para lá, gravações não persistem | Crítico | Qualquer pessoa / equipa de campo |
| C2 | Upload de ficheiros sem login | Crítico | Anónimo |
| C3 | Service worker guarda páginas `/admin`; logout não limpa nada | Crítico | Quem pegar no telemóvel |
| C4 | Todos os `throttle:` contornáveis por `X-Forwarded-For` | Crítico | Anónimo |
| C5 | PIN de 4 dígitos com limite fraco; logins falhados não auditados | Crítico | Anónimo |
| H1 | Password de admin reposta em cada deploy a partir do ambiente | Alto | Quem vê o ambiente Railway |
| H2 | `offline-sync/operational-actions` sem validação; falha em silêncio | Alto | `tecnico` |
| H3 | `registado_em` livre — livro sanitário datável à vontade | Alto | `tecnico`, `nadador_salvador` |
| H4 | Disco R2 público — todas as fotos legíveis sem sessão | Alto | Anónimo com URL |
| H5 | Sem policy = Filament autoriza. `Product` apagável pelo técnico | Alto | `tecnico` |
| H6 | Backups escritos em disco efémero — não há backup | Alto | — (risco operacional) |
| H7 | `APP_KEY` igual em dev e produção | Alto | Quem comprometer a máquina de dev |
| H8 | `hanna:sync` grava valores sem validação de gama | Alto | Quem controlar a conta Hanna |
| M1 | Stack traces completos enviados por e-mail | Médio | — |
| M2 | Password Hanna em texto simples; token em cache sem cifra | Médio | Quem vê o ambiente ou o Redis |
| M3 | `/api/health`: teste de "interno" contornável e código morto | Médio | Anónimo |
| M4 | CVEs em `guzzlehttp/guzzle` e `league/commonmark` | Médio | — |
| M5 | `/api/metrics`: comparação de token não timing-safe | Médio | Anónimo |
| M6 | `SESSION_LIFETIME` de 30 dias em telemóvel partilhado | Médio | Quem pegar no telemóvel |
| M7 | `Definicoes::canAccess()` deixa entrar qualquer autenticado | Médio | `tecnico` |
| L1 | `/api/pdf/export` sem login, devolve texto a fingir ser PDF | Baixo | Anónimo |
| L2 | Hostname morto na CSP e no CLAUDE.md | Baixo | — |
| L3 | `APP_KEY` antiga no histórico do Git (não em uso) | Baixo | — |
| L4 | `nadador_salvador` pode conseguir escrever `registado_em` | Baixo `PLAUSÍVEL` | `nadador_salvador` |
| L5 | `IncidentChatWidget` sem verificação própria | Baixo `NÃO VERIFICADO` | `nadador_salvador` |
| L6 | Tabelas mortas `incident_pools`, `incident_products` | Baixo | — |

---

## 4. Achados críticos

### C1 — `/m` está aberto, é para lá que a PWA abre, e não grava nada

**Ficheiros:**
- [routes/web.php:16-20](routes/web.php#L16) — cinco rotas fora do grupo `auth`
- [public/manifest.json:8](public/manifest.json#L8) — `"start_url": "/m"`
- [resources/views/filament/pwa-head.blade.php:2](resources/views/filament/pwa-head.blade.php#L2) — o painel `/admin` carrega esse mesmo manifest
- [app/Livewire/Mobile/DailyLog.php:28](app/Livewire/Mobile/DailyLog.php#L28) — `// Em um cenário real, gravaríamos no modelo DailyRecord`
- [app/Livewire/Mobile/ReportIncident.php:41](app/Livewire/Mobile/ReportIncident.php#L41) — `// Simula guardar`
- [app/Livewire/Mobile/Analysis.php:12-14](app/Livewire/Mobile/Analysis.php#L12) — pH e cloro fixos no código

**Nome:** *Missing Authentication for Critical Function* + *Silent Data Loss*.

**O que acontece.** Quem instala a app no telemóvel a partir de `/admin` recebe um ícone. Esse ícone abre em `/m`. Em `/m` não há login. Os ecrãs parecem reais. O de registo diário mostra "Registo guardado." e **descarta tudo**. O de incidentes mostra "Incidente reportado com sucesso." e **descarta tudo**. O de análises mostra sete dias de pH e cloro **inventados**.

Pedido de prova (`NÃO EXECUTADO`):

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://piscinasmmcrespo.up.railway.app/m
```

Um `200` confirma acesso anónimo. Um `302` para `/admin/login` refuta este achado.

**Raio.** Não é fuga de dados — nenhum destes componentes lê a base de dados. É **perda de registos**. Todo o turno que um nadador-salvador ou técnico registe a partir do ícone da PWA desaparece. O livro de registo sanitário é obrigatório por CN 14/DA. Um dia em falta é uma não-conformidade numa inspeção da DGS, e o registo já não é recuperável.

Isto é o achado mais grave do relatório e não é uma falha de segurança clássica — é a app a mentir ao utilizador.

**Antes:**
```php
// routes/web.php
Route::get('/m', \App\Livewire\MobileDashboard::class)->name('mobile.dashboard');
Route::get('/m/diario', \App\Livewire\Mobile\DailyLog::class)->name('mobile.daily');
Route::get('/m/analise', \App\Livewire\Mobile\Analysis::class)->name('mobile.analysis');
Route::get('/m/incidentes/novo', \App\Livewire\Mobile\ReportIncident::class)->name('mobile.incident');
Route::get('/m/exportar', \App\Livewire\Mobile\ExportPdf::class)->name('mobile.export');
```

**Depois** — apagar as cinco rotas, apagar `app/Livewire/MobileDashboard.php` e `app/Livewire/Mobile/`, apagar `resources/views/livewire/mobile*`, e corrigir o manifest:
```json
"start_url": "/admin",
```

Se o protótipo tiver de ficar para desenho, tem de sair da produção:
```php
if (app()->environment('local')) {
    Route::middleware('auth')->group(function (): void {
        Route::get('/m', \App\Livewire\MobileDashboard::class)->name('mobile.dashboard');
        // ...
    });
}
```

---

### C2 — Upload de ficheiros sem qualquer autenticação

**Ficheiros:**
- [app/Livewire/Mobile/ReportIncident.php:10](app/Livewire/Mobile/ReportIncident.php#L10) — `use WithFileUploads;`
- [routes/web.php:19](routes/web.php#L19) — rota sem `auth`
- [config/livewire.php:70](config/livewire.php#L70) — `'middleware' => null` → por omissão `throttle:60,1`
- [vendor/livewire/livewire/src/Features/SupportFileUploads/WithFileUploads.php:111](vendor/livewire/livewire/src/Features/SupportFileUploads/WithFileUploads.php#L111) — a limpeza só apaga ficheiros com mais de 24 h

**Nome:** *Unauthenticated File Upload / Resource Exhaustion*.

**Cadeia.** Um anónimo carrega `/m/incidentes/novo`. O componente monta e o Livewire gera um URL de upload assinado. O anónimo usa esse URL para enviar ficheiros de até 12 MB, 60 por minuto. Os ficheiros vão para `storage/app/private/livewire-tmp` (`LIVEWIRE_TMP_DISK=local`), **no disco do contentor**. A limpeza do Livewire só apaga o que tem mais de 24 horas.

Somado ao **C4**, o limite de 60/min também cai. Fica sem teto.

Pedido de prova (`NÃO EXECUTADO`):

```bash
curl -s https://piscinasmmcrespo.up.railway.app/m/incidentes/novo | grep -o 'wire:snapshot' | head -1
```

Se aparecer `wire:snapshot`, o componente Livewire está montado para um anónimo e o caminho de upload está aberto.

**Raio.** 720 MB por minuto no melhor caso com um IP, mais com rotação de cabeçalho. Disco cheio significa: a app deixa de escrever logs, deixa de aceitar uploads legítimos, e o PostgreSQL pode falhar escritas. **Todos os 5 postos e todos os utilizadores ficam sem app.** Não há perda de dados já gravados, mas há paragem do serviço.

**Antes:** rota pública, `middleware => null`.

**Depois** — a correção do C1 (apagar as rotas) fecha isto. Adicionalmente, apertar o Livewire para toda a app:
```php
// config/livewire.php
'temporary_file_upload' => [
    'middleware' => ['auth', 'throttle:10,1'],
    'rules' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:20480'],
    // ...
],
```

---

### C3 — O telemóvel guarda páginas `/admin` e o logout não apaga nada

**Ficheiros:**
- [public/sw.js:97-104](public/sw.js#L97) — guarda em cache **qualquer** navegação com status 200
- [public/sw.js:33-39](public/sw.js#L33) — pré-carrega `/admin` e `/admin/daily-records/create`
- [resources/js/app.js:1025-1032](resources/js/app.js#L1025) — IndexedDB `DailyRecordDraftDB` com `photos`, `form_drafts`, `offline_queue`
- [resources/js/app.js:1611](resources/js/app.js#L1611) — rascunho em `localStorage`
- Ausência: nenhum `caches.delete` fora do `activate` do service worker; nenhum tratador de logout em todo o `app.js`

**Nome:** *Sensitive Data Stored in Client-Side Cache Without Invalidation*.

**O que fica no aparelho.**

| Onde | O quê |
|---|---|
| Cache API `mmcrespo-v15` | O **HTML completo** de cada página `/admin` visitada. Valores das 5 piscinas, nomes de técnicos, texto livre de incidentes, stock |
| IndexedDB `photos` | Fotos tiradas no local, em bruto |
| IndexedDB `form_drafts` | Rascunhos do registo diário |
| IndexedDB `offline_queue` | Registos por sincronizar |
| `localStorage` | Rascunho + temporizadores `mmc_timer_*` |

Nada disto é apagado ao sair da conta. O `activate` do service worker só limpa caches de **versões antigas** (`c !== VERSAO`), não a atual.

Prova no aparelho (`NÃO EXECUTADO`) — na consola do browser, depois de fazer logout:

```javascript
caches.keys().then(async ks => {
  for (const k of ks) {
    const c = await caches.open(k);
    console.log(k, (await c.keys()).map(r => r.url).filter(u => u.includes('/admin')));
  }
});
```

Se listar URLs `/admin` depois do logout, o achado está confirmado: essas respostas leem-se offline sem credenciais.

**Raio.** Todo o telemóvel de campo. O contexto declarado é *telemóvel pessoal, partilhado, raramente bloqueado*. Quem pegar no aparelho lê, em modo avião, as páginas que o último utilizador visitou — incluindo o texto livre de incidentes, que é onde acaba escrito qualquer episódio com um banhista. Somado ao **M6** (sessão de 30 dias), muitas vezes nem é preciso ler a cache: a sessão ainda está viva.

**Antes:** `sw.js` guarda qualquer 200.

**Depois** — duas mudanças. Primeiro, não guardar respostas autenticadas:
```javascript
// public/sw.js — dentro do bloco isNavigation
if (networkResponse && networkResponse.status === 200 && !url.pathname.startsWith('/admin')) {
    const responseClone = networkResponse.clone();
    caches.open(VERSAO).then((cache) => cache.put(event.request, responseClone));
}
```
E retirar `/admin` e `/admin/daily-records/create` de `CORE_ASSETS`.

Segundo, limpar tudo no logout. Um `Route::post('/logout', ...)` próprio, ou um ouvinte do evento `Logout` que devolva uma view com:
```javascript
(async () => {
  for (const k of await caches.keys()) await caches.delete(k);
  indexedDB.deleteDatabase('DailyRecordDraftDB');
  Object.keys(localStorage).filter(k => k.startsWith('mmc_') || k.includes('daily-record')).forEach(k => localStorage.removeItem(k));
  const regs = await navigator.serviceWorker.getRegistrations();
  await Promise.all(regs.map(r => r.unregister()));
})();
```

Se o registo offline tiver de sobreviver ao logout, então tem de ficar cifrado com uma chave derivada da password — mas isso é trabalho grande. A decisão simples é: **o offline só serve o utilizador com sessão ativa.**

---

### C4 — Todos os limites de tentativas são contornáveis com um cabeçalho

**Ficheiros:**
- [bootstrap/app.php:25](bootstrap/app.php#L25) — `$middleware->trustProxies(at: '*');`
- [vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:227](vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php#L227) — a chave para pedidos sem sessão é `domínio|$request->ip()`

**Nome:** *Rate Limit Bypass via Spoofed Forwarded Header*.

**Porquê.** `trustProxies(at: '*')` é necessário para o HTTPS do Railway. O efeito colateral é que `request()->ip()` passa a devolver o valor que o cliente escreve em `X-Forwarded-For`. O `ThrottleRequests` do Laravel usa exatamente esse valor como chave. Logo, **um `X-Forwarded-For` diferente por pedido é um balde de tentativas novo por pedido.**

Rotas afetadas, todas sem sessão:

| Rota | Limite anunciado | Limite real |
|---|---|---|
| `/api/health` | `throttle:30,1` | ilimitado |
| `/api/metrics` | `throttle:30,1` | ilimitado |
| `/convite/accept` | `throttle:10,1` | ilimitado |
| `/livewire/upload-file` | `throttle:60,1` | ilimitado |

O curioso é que **alguém já percebeu isto** e escreveu-o com todo o detalhe no comentário de [app/Filament/Pages/Auth/Login.php:51-58](app/Filament/Pages/Auth/Login.php#L51). O login usa `request()->server('REMOTE_ADDR')` de propósito. Ninguém aplicou o mesmo raciocínio às restantes rotas.

Prova (`NÃO EXECUTADO`) — 40 pedidos a uma rota com limite de 30, cada um com um IP forjado:

```bash
for i in $(seq 1 40); do curl -s -o /dev/null -w "%{http_code} " -H "X-Forwarded-For: 10.0.0.$i" https://piscinasmmcrespo.up.railway.app/api/health; done; echo
```

40 respostas `200` confirmam o contorno. Um `429` a partir do 31.º refuta.
**Não corras isto contra produção** — é exatamente o "load" que ficou proibido. Corre contra staging.

**Raio.** Por si só não expõe dados. É o multiplicador que torna o C2 e o C5 exploráveis a sério.

**Antes:**
```php
Route::get('/health', [HealthController::class, 'check'])->middleware('throttle:30,1');
```

**Depois** — um limitador com chave em `REMOTE_ADDR`, registado no `AppServiceProvider::boot()`:
```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('publico', fn (Request $r) => Limit::perMinute(30)
    ->by((string) $r->server('REMOTE_ADDR')));
```
E depois `->middleware('throttle:publico')` em cada rota da tabela acima.

O `trustProxies(at: '*')` também devia ser apertado. O Railway tem gama de IPs conhecida; confiar em `*` significa confiar em qualquer coisa que fale com o nginx.

---

### C5 — PIN de 4 dígitos, limite fraco, e nenhum registo dos falhados

**Ficheiros:**
- [app/Filament/Pages/Auth/Login.php:47-49](app/Filament/Pages/Auth/Login.php#L47) — deteta PIN: 4 a 6 dígitos
- [app/Filament/Pages/Auth/Login.php:63](app/Filament/Pages/Auth/Login.php#L63) — `RateLimiter::tooManyAttempts($throttleKey, 5)`
- [app/Filament/Pages/Auth/Login.php:118](app/Filament/Pages/Auth/Login.php#L118) — `RateLimiter::hit($throttleKey, 60)` → **decaimento de 60 segundos**
- [app/Http/Controllers/InvitationController.php:61](app/Http/Controllers/InvitationController.php#L61) — o convite aceita `pin` com `digits_between:4,6` e **sem password**
- [app/Providers/AppServiceProvider.php:74](app/Providers/AppServiceProvider.php#L74) — o ouvinte do evento `Failed` existe
- Ausência: nenhum `Auth::attempt` em toda a `app/`. O evento **nunca dispara**

**Nome:** *Insufficient Credential Complexity* + *Brute Force* + *Missing Audit of Authentication Failures*.

**A conta da força bruta.** O espaço de um PIN de 4 dígitos são 10 000 combinações. O limite são 5 tentativas com decaimento de 60 segundos, com chave em `email + REMOTE_ADDR`. Atrás do proxy do Railway, `REMOTE_ADDR` é o mesmo para todo o mundo, logo a chave é na prática **por e-mail**: 5 tentativas por minuto, 300 por hora.

10 000 ÷ 300 = **33 horas para esgotar o espaço. Cerca de 17 horas em média.** Um fim de semana.

E é pior por três razões:

1. **Um convite pode criar uma conta só com PIN.** A validação em [InvitationController.php:69](app/Http/Controllers/InvitationController.php#L69) aceita PIN sem password, e o [InvitationService.php:87-89](app/Services/InvitationService.php#L87) gera uma password aleatória de 32 caracteres que ninguém conhece. Para essas contas, **4 dígitos são a única credencial que existe**.
2. **O ataque é invisível.** Só há `Log::warning` para `storage/logs`, que é disco efémero no Railway e desaparece a cada deploy. O trilho de auditoria do painel — o `activity_log` que o admin consegue ler — **não registra um único login falhado**, porque o login personalizado não usa `Auth::attempt` e o evento `Failed` nunca é emitido. O ouvinte em `AppServiceProvider.php:74` é código morto.
3. **Bloqueio de conta como sabotagem.** 5 tentativas erradas por minuto mantêm um utilizador fora da conta indefinidamente. Um técnico à beira da piscina não consegue registar nada e ninguém sabe porquê.

Prova (`NÃO EXECUTADO`, contra staging) — confirmar que o 6.º pedido é travado e que o 1.º do minuto seguinte não é:

```bash
for i in 1 2 3 4 5 6; do curl -s -c /tmp/c -b /tmp/c -X POST https://piscinasmmcrespo-testes.up.railway.app/admin/login -d "email=ns@mmcrespo.pt&password=000$i" -o /dev/null -w "%{http_code} "; done; echo
```

Para provar a ausência de auditoria: depois de tentativas falhadas, abrir `/admin/activitylogs` como admin e filtrar por `log_name = auth`. Zero linhas confirma o achado.

**Raio.** Uma conta comprometida. Se for `tecnico`, o atacante lê **todos** os registos diários e **todos** os incidentes de todas as 5 piscinas (a `IncidentPolicy::view` e a `DailyRecordPolicy::view` dão acesso total ao técnico), escreve registos no livro sanitário, e mexe no stock e no catálogo de produtos (ver H5). Se for `nadador_salvador`, o âmbito é menor mas o texto livre dos seus incidentes é legível.

**Antes:**
```php
$isPinAttempt = ctype_digit($password) && strlen($password) >= 4 && strlen($password) <= 6;
// ...
RateLimiter::hit($throttleKey, 60);
```

**Depois** — três mudanças no mesmo ficheiro:

```php
// 1. PIN mínimo de 6 dígitos (1 000 000 de combinações em vez de 10 000)
$isPinAttempt = ctype_digit($password) && strlen($password) === 6;

// 2. Decaimento longo e progressivo, não 60 segundos
RateLimiter::hit($throttleKey, 900);   // 5 tentativas por 15 minutos

// 3. Emitir o evento para o trilho de auditoria funcionar
event(new \Illuminate\Auth\Events\Failed('web', $user, [
    'email' => $email,
]));
```

E no `InvitationController::store()`, exigir sempre password:
```php
$validated = $request->validate([
    // ...
    'password' => ['required', 'string', 'min:8', 'confirmed'],
    'pin' => ['nullable', 'digits:6'],
]);
```
Isto torna o bloco de "password ou PIN" em [InvitationController.php:69-73](app/Http/Controllers/InvitationController.php#L69) desnecessário — o PIN passa a ser atalho, nunca credencial única. O mesmo em [PasswordChangeController.php:85](app/Http/Controllers/PasswordChangeController.php#L85).

---

## 5. Achados altos

### H1 — Cada deploy repõe a password de admin a partir do ambiente

**Ficheiros:**
- [database/seeders/UserSeeder.php:29](database/seeders/UserSeeder.php#L29) — `User::updateOrCreate` com `'password' => Hash::make($passwordDaniel)`
- [database/seeders/UserSeeder.php:44](database/seeders/UserSeeder.php#L44) — o mesmo para `marcio@mmcrespo.pt`
- [docker-entrypoint.sh:57](docker-entrypoint.sh#L57) — `php artisan db:seed --force` corre **em cada arranque do contentor**

**Nome:** *Hardcoded/Environment-Pinned Credential*.

**O que isto significa.** `updateOrCreate` reescreve a password em cada seed. O seed corre em cada deploy. Logo:

- Se o Daniel mudar a password no painel, **o próximo deploy reverte-a** para o valor de `ADMIN_PASSWORD_DANIEL`.
- Essa variável de ambiente **não é uma password inicial. É a password de admin, permanentemente.**
- Quem alguma vez vê o ambiente do Railway — um colaborador do projeto, uma captura de ecrã, um log de build — tem acesso de administrador para sempre. Rodar a password na interface não corrige nada.

O comentário no código diz que isto é intencional: *"updateOrCreate garante que a password é sempre sincronizada com a env var a cada redeploy"*. É uma escolha, mas é a escolha errada: transforma um segredo de arranque num segredo permanente e anula a rotação de passwords.

O `syncRoles(['admin'])` nas linhas 42 e 58 tem o mesmo problema em menor escala: um admin despromovido volta a ser admin no deploy seguinte.

Nota boa: as contas de teste com password `password` estão corretamente fechadas a `local`/`testing` em [UserSeeder.php:61](database/seeders/UserSeeder.php#L61). Isso está bem.

**Raio.** As duas únicas contas de administrador. Admin lê e escreve tudo: 47 tabelas, todas as piscinas, todos os utilizadores, o trilho de auditoria (que também pode apagar via `activitylog:clean`).

**Antes:**
```php
$daniel = User::updateOrCreate(
    ['email' => 'daniel@mmcrespo.pt'],
    [
        'name' => 'Daniel Paz',
        // ...
        'password' => Hash::make($passwordDaniel),
        'email_verified_at' => now(),
    ]
);
```

**Depois** — a password só na criação; os restantes campos podem continuar a sincronizar:
```php
$daniel = User::firstOrCreate(
    ['email' => 'daniel@mmcrespo.pt'],
    [
        'name' => 'Daniel Paz',
        'first_name' => 'Daniel',
        'last_name' => 'Paz',
        'password' => Hash::make($passwordDaniel),
        'email_verified_at' => now(),
        'must_change_password' => true,
    ]
);
if (! $daniel->hasAnyRole(\App\Constants\UserRole::all())) {
    $daniel->assignRole('admin');
}
```

Depois de aplicar: mudar as duas passwords no painel e **apagar** `ADMIN_PASSWORD_DANIEL` e `ADMIN_PASSWORD_MARCIO` do Railway. O seeder deixa de precisar delas em contas já existentes.

---

### H2 — `offline-sync/operational-actions` aceita tudo e falha em silêncio

**Ficheiros:**
- [app/Http/Controllers/OfflineSyncController.php:113](app/Http/Controllers/OfflineSyncController.php#L113) — `OperationalAction::create($data)`
- [app/Models/OperationalAction.php:69-71](app/Models/OperationalAction.php#L69) — `$fillable` inclui `pool_id`, `tipo`, `registado_em`, `dados`, `observacoes`, `foto`
- [app/Http/Controllers/OfflineSyncController.php:117-123](app/Http/Controllers/OfflineSyncController.php#L117) — `catch` engole o erro
- [app/Http/Controllers/OfflineSyncController.php:126-130](app/Http/Controllers/OfflineSyncController.php#L126) — resposta `success: true` mesmo assim

**Nome:** *Mass Assignment Without Validation* + *Silent Failure*.

**Comparação que expõe o problema.** O endpoint irmão, `storeDailyRecords`, faz as coisas bem: tem lista branca de campos em [DailyRecordService.php:26-34](app/Services/DailyRecordService.php#L26), força o `user_id` no servidor, e verifica as piscinas do nadador-salvador em [DailyRecordService.php:71](app/Services/DailyRecordService.php#L71). Este endpoint **não tem nada disso**. Só força o `user_id`.

Pedido de prova (`NÃO EXECUTADO`, com sessão de `tecnico` em staging):

```bash
curl -X POST https://piscinasmmcrespo-testes.up.railway.app/offline-sync/operational-actions \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: $CSRF" -b cookies.txt \
  -d '{"records":[{"offline_id":"x1","data":{"pool_id":1,"tipo":"lavagem_filtro","registado_em":"2020-01-01 08:00:00","observacoes":"retroativo","dados":{"qualquer":"coisa"}}}]}'
```

Resposta esperada: `{"success":true,"synced_count":1,...}`. Uma ação operacional datada de 2020 numa piscina qualquer.

**A parte da falha silenciosa é a mais séria.** Se o `create()` lançar exceção — `pool_id` inexistente, `tipo` fora do enum, coluna a estourar — o `catch` escreve no log e o `offline_id` **não** entra em `syncedIds`... mas a resposta continua `success: true` com `synced_count` a zero. O `synced_count` de `storeDailyRecords` é ainda pior: em [OfflineSyncController.php:54](app/Http/Controllers/OfflineSyncController.php#L54) conta as piscinas **antes** de tentar gravar, logo reporta sucesso para registos que rebentaram.

**Raio.** Tabela `operational_actions`, todas as 5 piscinas, escrita por qualquer `tecnico` ou `admin`. Datas arbitrárias contaminam o relatório PDF, que inclui a secção de ações operacionais ([RelatorioPdf.php:120](app/Filament/Pages/RelatorioPdf.php#L120)). E o caminho de perda silenciosa afeta **registos diários reais de campo** — o mesmo dano do C1, por outra porta.

**Antes:**
```php
try {
    $data['user_id'] = $user->id;
    OperationalAction::create($data);
    $syncedCount += 1;
    if ($offlineId !== null) { $syncedIds[] = $offlineId; }
} catch (\Throwable $e) {
    Log::error('Erro na sincronização offline da ação operacional', [...]);
}
```

**Depois:**
```php
$falhas = [];
// ...
try {
    $validado = validator($data, [
        'pool_id' => ['required', 'integer', 'exists:pools,id'],
        'tipo' => ['required', 'string', Rule::in(array_keys(OperationalAction::TIPOS))],
        'registado_em' => ['required', 'date', 'before_or_equal:now', 'after:'.now()->subDays(7)->toDateString()],
        'observacoes' => ['nullable', 'string', 'max:2000'],
        'dados' => ['nullable', 'array'],
        'foto' => ['nullable', 'string', 'max:255'],
    ])->validate();

    $validado['user_id'] = $user->id;
    OperationalAction::create($validado);

    $syncedCount++;
    if ($offlineId !== null) { $syncedIds[] = $offlineId; }
} catch (\Throwable $e) {
    Log::error('Erro na sincronização offline da ação operacional', [
        'offline_id' => $offlineId, 'user_id' => $user->id, 'error' => $e->getMessage(),
    ]);
    $falhas[] = ['offline_id' => $offlineId, 'motivo' => $e->getMessage()];
}

return response()->json([
    'success' => $falhas === [],
    'synced_count' => $syncedCount,
    'synced_ids' => $syncedIds,
    'failed' => $falhas,
], $falhas === [] ? 200 : 207);
```

E o cliente em `resources/js/app.js` só pode apagar da `offline_queue` os `offline_id` que vêm em `synced_ids`. Nunca a fila inteira.

---

### H3 — A data do livro sanitário vem do cliente, sem limite

**Ficheiros:**
- [app/Services/DailyRecordService.php:51](app/Services/DailyRecordService.php#L51) — `Carbon::parse($data['registado_em'] ?? now())`
- [app/Filament/Resources/DailyRecordResource/DailyRecordFormBuilder.php:584-589](app/Filament/Resources/DailyRecordResource/DailyRecordFormBuilder.php#L584) — o `DatePicker` **não tem** `maxDate()` nem `minDate()`

**Nome:** *Improper Input Validation on Regulated Record Timestamp*.

O `DailyRecord` é desenhado como append-only por causa do CN 14/DA. Corrigir cria uma linha nova com `e_correcao=true`. Todo esse cuidado fica inútil se a **data** do registo for escolhida livremente: em vez de corrigir, insere-se um registo novo com a data que convier.

Caminho confirmado, plano HTTP, sem ambiguidade de Livewire (`NÃO EXECUTADO`):

```bash
curl -X POST https://piscinasmmcrespo-testes.up.railway.app/offline-sync/daily-records \
  -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $CSRF" -b cookies.txt \
  -d '{"records":[{"offline_id":"y1","data":{"registado_em":"2026-01-15","hora_colheita":"09:30","pools":{"1":{"ph":7.2,"cloro_livre":1.0,"temperatura":27}}}}]}'
```

Uma linha em `daily_records` datada de janeiro, conforme, criada hoje. O `created_at` guarda o instante real — logo é **detetável** numa auditoria forense, mas o relatório PDF só imprime `registado_em`.

**Raio.** Tabela `daily_records`, todas as 5 piscinas. Escrita por qualquer `tecnico` e por qualquer `nadador_salvador` nas suas piscinas. O impacto é regulamentar: permite fabricar dias em falta antes de uma inspeção da DGS, e o `RelatorioPdf` imprime-os como legítimos.

**Antes:**
```php
$registadoEm = Carbon::parse($data['registado_em'] ?? now());
```

**Depois:**
```php
$registadoEm = Carbon::parse($data['registado_em'] ?? now());

// O livro sanitário aceita atraso de sincronização offline, não reescrita de história.
if ($registadoEm->gt(now()->addMinutes(5)) || $registadoEm->lt(now()->subDays(7))) {
    throw ValidationException::withMessages([
        'registado_em' => 'A data do registo tem de estar entre os últimos 7 dias e agora.',
    ]);
}
```

E no formulário:
```php
Forms\Components\DatePicker::make('registado_em')
    ->label('Data do Registo')
    ->default(now())
    ->required()
    ->minDate(fn () => now()->subDays(7))
    ->maxDate(fn () => now())
    ->disabled(fn () => self::isNS())
    ->dehydrated(),
```

---

### H4 — Todas as fotos do R2 são públicas

**Ficheiro:** [config/filesystems.php:70](config/filesystems.php#L70) — `'visibility' => 'public'`

**Nome:** *Unauthenticated Access to User-Uploaded Media*.

Os 8 campos de foto do registo diário, mais as fotos de incidentes e de ações operacionais, vão para o disco `r2` com visibilidade pública. Servem-se a partir de `R2_PUBLIC_URL` **sem qualquer verificação de sessão**. Quem tiver o URL vê o ficheiro.

Os nomes são aleatórios (UUID do Filament), logo não se adivinham por tentativa. Mas o URL fuga por vários caminhos normais: fica na cache do service worker, fica no histórico do browser, fica no `Referer`, e é partilhável por quem quer que abra a página.

Prova (`NÃO EXECUTADO`) — com um URL de foto obtido do painel, num contexto sem cookies:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "$URL_DA_FOTO"
```

`200` sem cookies confirma. `403` refuta.

**Raio.** Todas as fotos já carregadas, sem exceção. São fotos tiradas em instalações municipais em funcionamento; podem apanhar banhistas, incluindo menores. Isso torna o achado relevante para RGPD, não só para segurança.

**Antes:**
```php
'r2' => [
    // ...
    'visibility' => 'public',
    'url' => env('R2_PUBLIC_URL'),
],
```

**Depois** — bucket privado e URLs assinados de curta duração:
```php
'r2' => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => 'auto',
    'bucket' => env('R2_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => false,
    'visibility' => 'private',
    'throw' => true,
    'report' => false,
],
```

Cada `ImageEntry` e `FileUpload` passa a usar `->visibility('private')`, e a leitura passa por `Storage::disk('r2')->temporaryUrl($caminho, now()->addMinutes(10))`.

Atenção a dois efeitos: o `img-src` da CSP em [SecurityHeaders.php:27](app/Http/Middleware/SecurityHeaders.php#L27) já permite `https://*.r2.dev`, logo continua a funcionar; e o service worker deixa de poder guardar as fotos em cache com URL fixo, o que afeta o modo offline. Se o offline de fotos for requisito, tem de ser resolvido à parte.

No Cloudflare, o acesso público do bucket tem de ser desligado do lado deles também — mudar só o Laravel não fecha o bucket.

---

### H5 — Sem policy, o Filament autoriza. O técnico manda no catálogo de produtos

**Ficheiros:**
- [vendor/filament/filament/src/helpers.php:26-42](vendor/filament/filament/src/helpers.php#L26) — sem policy ou sem o método, devolve `Response::allow()`
- [app/Filament/Resources/ProductResource.php:37-44](app/Filament/Resources/ProductResource.php#L37) — `canAccess` permite `admin`, `tecnico`, `gestor`
- [app/Filament/Resources/ProductResource.php:184](app/Filament/Resources/ProductResource.php#L184) — `EditAction`
- [app/Filament/Resources/ProductResource.php:188](app/Filament/Resources/ProductResource.php#L188) — `DeleteBulkAction`
- Ausência: não existe `app/Policies/ProductPolicy.php`. Só há 5 policies para 28 modelos

**Nome:** *Missing Function Level Access Control*.

Este é o padrão estrutural, não um caso isolado. O Filament, quando não encontra policy, **permite**. Só o `canAccess()` do Resource trava. Onde o `canAccess()` deixa entrar mas não há policy, quem entrou pode tudo ao nível da linha.

Onde isso é explorável por um `tecnico`:

| Modelo | O que o técnico pode fazer | Porque |
|---|---|---|
| `Product` | Editar e **apagar em lote** o catálogo | Sem policy, sem `canEdit`, `DeleteBulkAction` presente |
| `DosingContainer` | Editar capacidade e nível dos bidões | Sem policy, sem `canEdit` |

**O mais silencioso não é apagar, é editar.** Um técnico pode mudar a `unidade` de um produto de `L` para `kg`. Nada valida isso. Todas as quantidades já em `stock_warehouse` e `stock_installations` passam a ser lidas na unidade nova, sem conversão e sem registo de que alguma coisa mudou. O mesmo com `limite_minimo`: baixá-lo apaga o alerta de stock baixo.

Apagar tem um travão parcial que vale registar com honestidade: `stock_warehouse_logs.product_id` e `record_additions.product_id` são `constrained()` sem cascata ([migração:13](database/migrations/2026_05_27_150006_create_stock_warehouse_logs_table.php#L13)), logo apagar um produto **com histórico** falha com erro de chave estrangeira, provavelmente um 500. Mas para um produto **sem** histórico o apagar passa, e cascateia para `stock_warehouse` e `stock_installations`, que são `cascadeOnDelete` ([migração:13](database/migrations/2026_05_27_150004_create_stock_warehouse_table.php#L13), [migração:14](database/migrations/2026_05_27_150005_create_stock_installations_table.php#L14)).

Prova (`NÃO EXECUTADO`) — como `tecnico` em staging, abrir `/admin/products`, selecionar linhas, usar "Apagar selecionados". A ação existe e não há policy a travá-la.

O resto do mapa está bem fechado, e isso deve ser dito: `Pool`, `Installation`, `HannaDevice`, `User`, `UserInvitation` e `PoolAccessRequest` estão todos limitados a `admin` (ou `admin`+`gestor`) pelo `canAccess`. Os dois Resources de logs de stock só têm página de lista, sem ações de editar ou apagar. O `OperationalActionResource` tem guardas explícitas e bem pensadas em [OperationalActionResource.php:68-80](app/Filament/Resources/OperationalActionResource.php#L68). Para o `nadador_salvador`, o `canAccess` fecha **todos** estes Resources.

**Raio.** Tabela `products` inteira, mais as linhas de `stock_warehouse` e `stock_installations` dos produtos apagáveis. Escrita por qualquer `tecnico` ou `gestor`.

**Antes:** nenhuma policy; `ProductResource` sem `canEdit`/`canDelete`.

**Depois** — criar `app/Policies/ProductPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR]);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
```

E o mesmo padrão para `DosingContainerPolicy`.

**A correção de fundo** é não depender de lembrar-se de criar policies. No `AppServiceProvider::boot()`:
```php
use Illuminate\Support\Facades\Gate;

// Sem policy explícita, nega. O Filament passa a receber "deny" em vez de "allow".
Gate::before(function ($user, string $ability) {
    return null; // deixa as policies decidirem
});

Gate::after(function ($user, string $ability, ?bool $result) {
    return $result ?? false;
});
```
Mais direto e mais legível: pôr `protected static bool $shouldCheckPolicyExistence = false;` numa classe base `App\Filament\Resources\BaseResource` e fazer todos os Resources herdarem dela. Aí, modelo sem policy passa a dar 403 em vez de acesso livre, e o erro aparece em desenvolvimento em vez de em produção.

---

### H6 — Os backups vão para um disco que é apagado

**Ficheiros:**
- [app/Console/Commands/BackupDatabase.php:29](app/Console/Commands/BackupDatabase.php#L29) — `$backupDir = storage_path('backups')`
- [routes/console.php:22](routes/console.php#L22) — agendado às 03:00
- [.gitignore:3](.gitignore#L3) — `/storage/backups`

**Nome:** *Ineffective Backup Strategy*.

O comando funciona: faz `pg_dump --format=custom` e mantém 30 cópias. O problema é o destino. `storage/backups` está **dentro do contentor**. O sistema de ficheiros do Railway é efémero — cada deploy arranca um contentor novo e o anterior desaparece com tudo o que escreveu.

Resultado: o `pg_dump` corre todas as noites, escreve o ficheiro, e o próximo deploy apaga-o. **Na prática não existe backup**, a não ser que os backups do plugin Postgres do Railway estejam ligados. Isso é `NÃO VERIFICADO` — precisas de olhar no serviço Postgres, aba *Backups*.

Um detalhe relacionado: `pg_dump` tem de estar no `PATH`. O [Dockerfile:16-22](Dockerfile#L16) instala `libpq-dev` mas **não** o pacote `postgresql-client`. Logo o `exec` em [BackupDatabase.php:95](app/Console/Commands/BackupDatabase.php#L95) provavelmente devolve exit code diferente de zero e o comando falha todas as noites em silêncio, porque o agendamento usa `runInBackground()` e ninguém lê a saída. `NÃO VERIFICADO` — confirma com `railway run pg_dump --version` ou vendo os logs do scheduler às 03:00.

**Raio.** Todas as 47 tabelas. Sem backup, uma migração má, um `activitylog:clean` mal parametrizado, ou o `archive:daily-records` (que **apaga** os originais depois de copiar, [ArchiveDailyRecordsCommand.php](app/Console/Commands/ArchiveDailyRecordsCommand.php)) são perdas irreversíveis de registo sanitário obrigatório.

**Antes:** destino em `storage_path('backups')`.

**Depois** — enviar o dump para o R2 e verificar que o `pg_dump` existe:
```php
// Dockerfile, junto às outras deps
RUN apt-get update && apt-get install -y postgresql-client && rm -rf /var/lib/apt/lists/*
```
```php
// BackupDatabase.php, depois do pg_dump bem-sucedido
Storage::disk('r2')->put(
    'backups/'.basename($destino),
    file_get_contents($destino),
);
unlink($destino);
```
E ligar os backups automáticos do plugin Postgres no Railway, que é a rede de segurança que não depende do código da app.

---

### H7 — A mesma `APP_KEY` em desenvolvimento e em produção

**Confirmado nesta sessão.** A chave de produção que me deste é, byte por byte, a que está no `.env` local:

```bash
grep -c "APP_KEY=base64:8E6zoHCeW6fNqd7cp7tFYWoJwicWhofTQunV7kPYHAc=" .env
# devolveu 1
```

**Nome:** *Cryptographic Key Reuse Across Environments*.

A `APP_KEY` cifra os cookies e, com `SESSION_ENCRYPT=true`, o payload das sessões. Partilhá-la entre a máquina de desenvolvimento e a produção significa que comprometer o portátil é comprometer as sessões de produção.

Duas notas:

- **A chave que ficou no histórico do Git não é esta.** Confirmei que `base64:frr9NNKEl...` (em `38df734:RAILWAY_QUICK_START.md`) não está em uso. Essa ameaça está fechada. Fica como L3, para higiene.
- **Esta chave foi colada numa conversa.** Está numa transcrição. Trata-a como queimada.

**Rodar é seguro.** Verifiquei que nada na base de dados está cifrado com ela — zero ocorrências de `Crypt::`, de casts `encrypted` e de `encryptUsing` em toda a `app/`. O único efeito de rodar é que todas as sessões morrem e os utilizadores entram outra vez.

**Raio.** Todas as sessões ativas, todos os utilizadores.

**Depois:**
```bash
php artisan key:generate --show
```
O valor vai para a variável `APP_KEY` no serviço da app no Railway. Uma chave **diferente** para o `.env` local. Nunca correr `key:generate` sem `--show` contra produção.

---

### H8 — `hanna:sync` grava o que a API mandar

**Ficheiros:**
- [app/Console/Commands/HannaCloudSync.php:132-149](app/Console/Commands/HannaCloudSync.php#L132) — `SensorReading::upsert` com `$reading['ph']`, `orp`, `temperatura_agua`, `temperatura_ar`, `caudal_ph`, `caudal_cloro` diretos
- [app/Console/Commands/HannaCloudSync.php:127](app/Console/Commands/HannaCloudSync.php#L127) — `lida_em` de `Carbon::parse($reading['dt'])`, sem verificação
- [app/Console/Commands/HannaCloudSync.php:262-274](app/Console/Commands/HannaCloudSync.php#L262) — `descontarBidao()` desconta `dose_cloro_ml` sem limite

**Nome:** *Missing Validation of Data from External Source*.

Não há validação de gama em nenhum campo. Um pH de 47, uma temperatura de −900, um ORP absurdo — entram na tabela e passam a alimentar o painel, os alertas de conformidade e a tabela do controlador no relatório PDF. Uma `dt` no futuro envenena a lógica de "leitura fresca" que decide, em [EnsureHannaReadingsAreFresh.php:44](app/Http/Middleware/EnsureHannaReadingsAreFresh.php#L44) e no `PainelPiscinasWidget`, se se mostra a sonda ou o registo manual.

**Isto não é explorável pelo técnico.** Requer controlar a conta Hanna Cloud ou a resposta HTTPS. É integridade de dados, não escalada de privilégios. Mas é a fonte de verdade de 5 piscinas e alimenta um documento legal, o que justifica a gravidade alta.

**Raio.** Tabela `sensor_readings` (todas as 5 sondas) e `dosing_containers`.

**Antes:**
```php
$affected = SensorReading::upsert(
    [[
        'pool_id' => $device->pool_id,
        // ...
        'ph' => $reading['ph'],
        'orp' => $reading['orp'],
        'temperatura_agua' => $reading['temperatura_agua'],
```

**Depois** — filtrar antes de gravar:
```php
/** Fora destes limites físicos, o valor é avaria de sonda, não leitura. */
private const LIMITES = [
    'ph' => [0.0, 14.0],
    'orp' => [-2000.0, 2000.0],
    'temperatura_agua' => [-5.0, 60.0],
    'temperatura_ar' => [-30.0, 60.0],
    'caudal_ph' => [0.0, 100000.0],
    'caudal_cloro' => [0.0, 100000.0],
];

private function saneado(array $reading): array
{
    foreach (self::LIMITES as $campo => [$min, $max]) {
        $valor = $reading[$campo] ?? null;
        if ($valor !== null && ((float) $valor < $min || (float) $valor > $max)) {
            Log::warning("HannaCloudSync: {$campo}={$valor} fora dos limites físicos; descartado.");
            $reading[$campo] = null;
        }
    }

    return $reading;
}
```
E a data:
```php
$lida_em = $reading['dt'] ? Carbon::parse($reading['dt']) : now();

if ($lida_em->gt(now()->addMinutes(10)) || $lida_em->lt(now()->subDays(30))) {
    Log::warning("HannaCloudSync [{$device->hanna_device_id}]: dt implausível ({$lida_em}); leitura ignorada.");
    continue;
}
```
E um teto por ciclo no desconto dos bidões, em `descontarBidao()`:
```php
if ($ml <= 0 || $ml > 20000) {   // 20 L num ciclo é avaria, não dosagem
    Log::warning("HannaCloudSync: dosagem de {$ml} mL implausível; ignorada.");
    return;
}
```

---

## 6. Achados médios

### M1 — Stack traces completos por e-mail

**Ficheiro:** [bootstrap/app.php:50-67](bootstrap/app.php#L50)

A cada erro novo em produção, é enviado um e-mail com o **URL completo do pedido** e o **stack trace inteiro** (`$e->getTraceAsString()`). Vai para um endereço fixo, em texto simples, por SMTP. Um trace do Laravel pode conter argumentos de função.

Dois detalhes:
- `env('LOG_ALERT_EMAIL', 'daniel.paz@mmcrespo.pt')` é chamado em tempo de execução. Com `config:cache` ativo — e está, [docker-entrypoint.sh:62](docker-entrypoint.sh#L62) — o `env()` devolve `null` e usa sempre o endereço escrito no código. A variável de ambiente é ignorada.
- O rate limit de 10 minutos por erro único está bem feito.

**Depois:** mandar o Sentry fazer este trabalho (já está configurado e sabe redigir dados) e reduzir o e-mail a título, classe e ficheiro:linha. Nunca o trace. E mover o endereço para `config/logging.php` para o `config:cache` o apanhar.

O Sentry em si está **bem configurado** e isso deve ficar registado: `send_default_pii` não está definido, logo é `false` por omissão do SDK ([Options.php:1481](vendor/sentry/sentry/src/Options.php#L1481)); `breadcrumbs.sql_queries` é `true` mas `sql_bindings` não está definido, logo é `false` ([EventHandler.php:126](vendor/sentry/sentry-laravel/src/Sentry/Laravel/EventHandler.php#L126)) — vai o texto SQL, não os valores; `traces_sample_rate` e `profiles_sample_rate` a 0.1; DSN em variável de ambiente. Nada a corrigir aqui.

### M2 — Credenciais da Hanna em texto simples; token em cache sem cifra

**Ficheiros:** [config/services.php:31-34](config/services.php#L31), [app/Services/HannaCloudService.php:71](app/Services/HannaCloudService.php#L71)

`HANNA_CLOUD_PASSWORD` é a password real da conta Hanna Cloud, em texto simples no ambiente do Railway. O token de acesso fica no cache por 3600 segundos, sem cifra — em Redis, se `CACHE_STORE=redis`.

A chave AES em [HannaCloudService.php:282](app/Services/HannaCloudService.php#L282) **não é problema**: o comentário do ficheiro explica que é a chave pública da webapp da Hanna, e está correto.

**Depois:** não há forma de evitar a password (a API da Hanna não tem tokens de serviço). O que se pode fazer é limitar quem vê o ambiente do Railway, garantir que o Redis não tem porta pública, e reduzir o TTL do token cacheado.

### M3 — `/api/health`: teste de "interno" contornável e código morto

**Ficheiro:** [app/Http/Controllers/HealthController.php:17](app/Http/Controllers/HealthController.php#L17)

```php
$isInternal = in_array(request()->ip(), ['127.0.0.1', '::1', '169.155.0.0/16'], true);
```

Três problemas na mesma linha:
1. Um CIDR dentro de `in_array` nunca corresponde a nada. Código morto.
2. `request()->ip()` confia no `X-Forwarded-For` (ver C4). Um `X-Forwarded-For: 127.0.0.1` passa o teste.
3. `HEALTH_CHECK_IPS` existe no `.env.example:107` e **nunca é lido por código nenhum**.

O que se ganha a contornar é `app()->version()`. Pouco, mas é divulgação de versão gratuita.

**Depois:** usar `REMOTE_ADDR`, e ler a lista da configuração:
```php
$permitidos = array_filter(array_map('trim', explode(',', (string) config('app.health_check_ips', '127.0.0.1'))));
$isInternal = in_array((string) request()->server('REMOTE_ADDR'), $permitidos, true);
```
Ou, mais simples: tirar o `version` da resposta e acabar com o conceito de "interno".

### M4 — CVEs em dependências

Saída real de `composer audit`, 8 avisos em 2 pacotes. Versões instaladas confirmadas no `composer.lock`:

| Pacote | Instalado | Corrigido em | Avisos |
|---|---|---|---|
| `guzzlehttp/guzzle` | **7.15.1** | 7.15.2 | CVE-2026-69246 (high, bypass de verificação por host não-canónico), CVE-2026-69245 (medium, âmbito de cookie em subdomínio) |
| `league/commonmark` | **2.8.2** | 2.9.0 | 4 high de DoS + CVE-2026-71488 (high, parsing quadrático) + CVE-2026-71478 (medium, bypass de filtro de link) |

`npm audit --production` → `found 0 vulnerabilities`.

O Guzzle é usado pelo `HannaCloudService` para chamar a API da Hanna. O `commonmark` vem por dependência do Filament e não recebe Markdown de utilizador em nenhum sítio que eu tenha encontrado, logo o risco real de DoS é baixo. Corrigir de qualquer forma — é um comando.

**Depois:**
```bash
composer update guzzlehttp/guzzle league/commonmark
```

### M5 — `/api/metrics`: comparação de token não timing-safe

**Ficheiro:** [app/Http/Controllers/MetricsController.php:24](app/Http/Controllers/MetricsController.php#L24)

```php
if (empty($token) || $request->bearerToken() !== $token) {
```

`!==` em strings não é de tempo constante. Sendo honesto: extrair 48 caracteres aleatórios por timing através da internet, com a variância de rede do Railway, não é exploração prática. Corrige-se porque custa uma linha, não porque alguém vai fazê-lo.

O `empty($token)` a fechar a porta quando a variável não está definida está **bem pensado** — sem isso, um token vazio autorizaria todos.

**Antes:**
```php
if (empty($token) || $request->bearerToken() !== $token) {
```
**Depois:**
```php
if (empty($token) || ! hash_equals($token, (string) $request->bearerToken())) {
```

### M6 — Sessão de 30 dias em telemóvel partilhado

`SESSION_LIFETIME=43200` no [.env.example:36](.env.example#L36) — 43200 minutos são 30 dias. O [.env.production.example:20](.env.production.example#L20) diz `120`. Os dois ficheiros discordam e **não sei qual está em produção** — `NÃO VERIFICADO`, confirma na variável `SESSION_LIFETIME` do Railway.

Se forem 30 dias: um telemóvel de campo partilhado e raramente bloqueado fica com sessão válida durante um mês. Somado ao C3 (nada é limpo no logout), o aparelho é uma credencial permanente.

O resto da configuração de sessão está **bem**: `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=strict`, e o `AuthenticateSession` está na lista de middleware do painel em [AdminPanelProvider.php:170](app/Providers/Filament/AdminPanelProvider.php#L170), o que invalida as outras sessões quando a password muda.

**Depois:** `SESSION_LIFETIME=480` (8 horas, um turno) e alinhar os dois ficheiros de exemplo.

### M7 — `Definicoes` deixa entrar qualquer autenticado

**Ficheiro:** [app/Filament/Pages/Definicoes.php:71-74](app/Filament/Pages/Definicoes.php#L71)

```php
public static function canAccess(): bool
{
    return (bool) auth()->user();
}
```

Qualquer utilizador com sessão abre `/admin/definicoes`. Os métodos de escrita **estão todos protegidos** — verifiquei um por um: `save()` tem `abort_unless($this->podeGerir(), 403)` na linha 274, e `getUsuariosNotificacoes()`, `getUsuariosLista()`, `pedirAtivacao()`, `limparSubscricoesUtilizador()` e `enviarManual()` têm todos a guarda `podeGerir()`. O `mount()` só preenche o formulário de sistema se `podeGerir()`.

Logo o impacto real é pequeno: um técnico vê a estrutura dos separadores, não os valores. Mas o gate está errado, e o próximo método público que alguém acrescentar sem guarda fica exposto.

**Depois:** o gate certo em vez de guardas espalhadas por 6 métodos:
```php
public static function canAccess(): bool
{
    $user = auth()->user();

    return $user !== null && $user->hasAnyRole(UserRole::all());
}
```
E manter as guardas de `podeGerir()` como estão — defesa em profundidade.

---

## 7. Achados baixos e notas

**L1 — `/api/pdf/export` sem login, devolve texto.** [routes/web.php:24-32](routes/web.php#L24). Rota anónima que devolve `"Relatorio gerado para o periodo: X"` com `Content-Type: application/pdf`. É o que o `Mobile/ExportPdf` chama. Não expõe dados. Confirmei que **não** há injeção de cabeçalho: o PHP rejeita nova-linha em `header()`. Apagar junto com o C1.

**L2 — Hostname morto.** `piscinas-mmcrespo-main.up.railway.app` aparece em [SecurityHeaders.php:31](app/Http/Middleware/SecurityHeaders.php#L31) e no CLAUDE.md, secção "Regras de Sessão", onde manda fazer testes manuais nesse endereço. Apagar dos dois. Um domínio Railway libertado pode ser reclamado por outra pessoa, e está na lista de `img-src` da CSP.

**L3 — `APP_KEY` antiga no histórico do Git.** `base64:frr9NNKEl...` em `38df734:RAILWAY_QUICK_START.md`. **Não está em uso** — confirmado. Higiene: reescrever o histórico é desproporcionado; basta garantir que essa chave nunca é usada.

**L4 — `nadador_salvador` a escrever `registado_em`.** `PLAUSÍVEL, NÃO CONFIRMADO`. Em [DailyRecordFormBuilder.php:588-589](app/Filament/Resources/DailyRecordResource/DailyRecordFormBuilder.php#L588) o campo é `->disabled(fn () => self::isNS())->dehydrated()`. O `->dehydrated()` explícito reativa a hidratação que o `disabled()` normalmente desliga, logo o valor de `data.registado_em` é lido do estado do formulário — que é uma propriedade pública do Livewire e portanto escrevível pelo cliente. Para confirmar preciso de uma instância local e de um pedido Livewire que atualize `data.registado_em` numa sessão de NS. A correção do H3 (`minDate`/`maxDate` + validação no serviço) fecha isto de qualquer maneira, o que é o argumento para corrigir no servidor e não no formulário.

**L5 — `IncidentChatWidget` sem verificação própria.** `NÃO VERIFICADO`. `enviarMensagem()` em [IncidentChatWidget.php:40](app/Filament/Widgets/IncidentChatWidget.php#L40) não verifica nada. Fica protegido de forma indireta: a página do incidente é filtrada pela `IncidentPolicy::view`, e o Livewire 3 assina o snapshot, o que em princípio impede o cliente de apontar `$record` para outro incidente. Para confirmar preciso de um teste que tente trocar a chave do modelo. Independentemente do resultado, vale acrescentar a verificação explícita:
```php
abort_unless(auth()->user()->can('view', $this->record), 403);
```

**L6 — Tabelas mortas.** `incident_pools` e `incident_products` continuam a ser criadas por migração, apesar de o CLAUDE.md (Sessão 12) dizer que foram removidas com os models. Sem impacto de segurança. Confirma antes de apagar.

---

## 8. O que verifiquei e está bem

Registo isto porque poupa tempo na próxima auditoria.

- **Sem injeção de SQL.** Todos os `DB::raw`, `selectRaw` e `orderByRaw` que encontrei usam literais escritos no código ou valores gerados no servidor. Nenhum recebe entrada de utilizador.
- **Sem segredos no bundle.** Procurei em `public/build/assets/`; só há maquinaria do axios e CSRF. **Sem source maps** em produção.
- **Sem segredos reais no histórico do Git.** 798 commits pesquisados por `SERVICE_ROLE`, `sk-ant`, `PGPASSWORD`, `postgres://`, `HANNA_CLOUD_PASSWORD`, `R2_SECRET`. Só placeholders. `.env` nunca foi versionado.
- **Contas de teste fechadas.** As contas com password `password` só são criadas em `local`/`testing` — [UserSeeder.php:61](database/seeders/UserSeeder.php#L61).
- **Sem endpoint de ingestão de sensores.** Ninguém pode enviar valores falsos de cloro ou pH. O `hanna:sync` puxa.
- **PDF sem IDOR, sem travessia de caminho, sem SSRF.** O relatório é gerado por uma action Livewire, sem rota com id — [RelatorioPdf.php:463](app/Filament/Pages/RelatorioPdf.php#L463). O template é fixo (`pdf.livro-sanitario`). O dompdf usa os valores por omissão: `enable_remote => false`, `enable_php => false`, `chroot => base_path()`. Não fica em cache pública.
- **`canAccess` bem posto na maioria dos Resources.** 15 dos 17 têm gate explícito; os dois que não têm (`DailyRecordResource`, `IncidentResource`) têm policy completa a cobrir.
- **Enumeração de contas no login mitigada.** Há um hash falso gastado de propósito quando o e-mail não existe — [Login.php:29](app/Filament/Pages/Auth/Login.php#L29). Bem feito.
- **Rate limit do login não usa `request()->ip()`**, por decisão explícita e bem documentada em [Login.php:51-58](app/Filament/Pages/Auth/Login.php#L51). O problema do C4 é não ter sido aplicado ao resto.
- **Configuração de sessão sólida.** Cifrada, `Secure`, `SameSite=strict`, `HttpOnly`, com `AuthenticateSession`.
- **Cabeçalhos de segurança presentes.** CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` — [SecurityHeaders.php](app/Http/Middleware/SecurityHeaders.php). A CSP tem `unsafe-inline` e `unsafe-eval`, que o Filament e o Livewire exigem; há uma versão `Report-Only` mais apertada a preparar a migração para nonce.
- **Escrituras de push e timers bem isoladas.** Todas usam `$request->user()`, sem id vindo do cliente.
- **Convites bem feitos.** Token de 64 caracteres, guardado como SHA-256, validade configurável, uso único — [InvitationService.php:40-52](app/Services/InvitationService.php#L40).
- **Sentry sem PII.** Ver M1.
- **`nadador_salvador` corretamente limitado.** Filtro por query em [DailyRecordResource.php:60](app/Filament/Resources/DailyRecordResource.php#L60), `abort_unless` no serviço em [DailyRecordService.php:71](app/Services/DailyRecordService.php#L71), e `canAccess` a fechar todos os Resources de estrutura, stock e sistema.
- **O `EnsureHannaReadingsAreFresh` está bem construído.** Arrefecimento de 300 s com `Cache::add` atómico, só em GET de utilizador autenticado.
- **Stock com transação e lock.** `DB::transaction()` + `lockForUpdate()` como documentado.

---

## 9. Inventário de dados pessoais (RGPD)

| Tabela | Conteúdo pessoal | Quem lê |
|---|---|---|
| `users` | Nome, e-mail, telefone, hash de password, hash de PIN | `admin`, `gestor` |
| `user_invitations` | E-mail do convidado, cargo, piscinas | `admin`, `gestor` |
| `incidents` | `descricao` e `observacoes` em **texto livre**; `type` inclui `qualidade_agua`. **É aqui que um episódio com um banhista acaba escrito** | `admin`, `gestor`, **`tecnico` (todos)**; `nadador_salvador` só os seus |
| `incident_messages` | Conversa entre funcionários sobre o incidente | idem |
| `daily_records` | `observacoes`, `tanque_observacoes` (texto livre), `banhistas` (contagem), nome do técnico | `admin`, `gestor`, **`tecnico` (todos)**; `nadador_salvador` só as suas piscinas |
| Fotos (8 campos + `record_photos`) | **Fotos tiradas nas instalações.** Podem apanhar pessoas | Painel + **URL público do R2 → ver H4** |
| `pool_access_requests` | Justificação escrita pelo funcionário | `admin` |
| `activity_log` | **IP e user-agent** de cada ação, mais o diff dos campos | `admin` |
| `sessions` | **IP, user-agent, payload** por utilizador | Só quem tiver a base de dados |
| `push_subscriptions` | Endpoint do browser por aparelho | Ninguém pelo painel |
| `notifications` | Payload das notificações, com nomes | Cada utilizador vê as suas |

**Superfícies sem autenticação que chegam a estes dados:**
- **H4 (R2 público):** chega às fotos. É o caminho mais direto e o mais sensível.
- **C3 (cache do service worker):** chega ao HTML de páginas `/admin` já visitadas, incluindo texto livre de incidentes.
- `/api/metrics` e `/api/health` **não** devolvem dados pessoais. As rotas `/m` **não** leem a base de dados.

Retenção: `activity_log` tem 730 dias configurados e poda semanal. Nenhuma outra tabela tem política de retenção. `record_photos` cresce sem limite.

---

## 10. Lotes de correção

Ordenados por risco a dividir por esforço. Cada lote é um commit.

### Lote 1 — Apagar o protótipo `/m` `≈30 min`
Fecha **C1** e **C2**, os dois achados críticos, de uma vez.
- Apagar as 5 rotas em `routes/web.php:15-32` (inclui `/api/pdf/export`, fecha L1)
- Apagar `app/Livewire/MobileDashboard.php`, `app/Livewire/Mobile/`, `resources/views/livewire/mobile*`, `resources/views/components/layouts/mobile.blade.php`
- `public/manifest.json`: `"start_url": "/admin"`
- Confirmar que nada referencia `mobile.*` por nome de rota

### Lote 2 — Limitadores com chave em `REMOTE_ADDR` `≈45 min`
Fecha **C4**, o multiplicador de tudo.
- Limitador `publico` no `AppServiceProvider::boot()`
- Aplicar em `/api/health`, `/api/metrics`, `/convite/accept`
- `config/livewire.php`: `middleware => ['auth', 'throttle:10,1']` e `rules` explícitas
- Apertar `trustProxies` à gama do Railway

### Lote 3 — Credenciais de acesso `≈1 h`
Fecha **C5** e **H1**.
- `Login.php`: PIN de 6 dígitos, decaimento de 900 s, `event(new Failed(...))`
- `InvitationController` e `PasswordChangeController`: password sempre obrigatória, PIN `digits:6`
- `UserSeeder`: `firstOrCreate` em vez de `updateOrCreate`, `assignRole` condicional
- Depois do deploy: mudar as duas passwords de admin e apagar `ADMIN_PASSWORD_*` do Railway

### Lote 4 — Rodar a `APP_KEY` `≈10 min`
Fecha **H7**. Todos vão ter de entrar outra vez — avisar a equipa antes.
- `php artisan key:generate --show` → `APP_KEY` no Railway
- Chave diferente no `.env` local

### Lote 5 — Fechar o bucket R2 `≈1 h 30`
Fecha **H4**, o pior caminho de dados pessoais.
- `visibility => 'private'` em `config/filesystems.php`
- `->visibility('private')` nos `FileUpload`; leitura por `temporaryUrl()`
- Desligar o acesso público do bucket no Cloudflare
- Testar que as fotos abrem no painel e no lightbox

### Lote 6 — Limpar o telemóvel no logout `≈2 h`
Fecha **C3**. É o lote com mais trabalho de teste em aparelho real.
- `sw.js`: não guardar respostas `/admin`; tirar `/admin*` de `CORE_ASSETS`; subir `VERSAO`
- Rota de logout que limpa Cache API, IndexedDB, `localStorage` e desregista o service worker
- `SESSION_LIFETIME=480` no Railway (fecha M6)
- Testar num telemóvel: entrar, navegar, sair, modo avião, confirmar que não há nada legível

### Lote 7 — Validação de entrada nos endpoints offline `≈1 h 30`
Fecha **H2** e **H3**.
- `validator()` completo em `storeOperationalActions`
- Resposta `207` com `failed[]`; cliente só apaga os `synced_ids`
- Janela de 7 dias para `registado_em` no `DailyRecordService`
- `minDate`/`maxDate` no `DatePicker` (fecha L4 de lado)

### Lote 8 — Policies e o comportamento por omissão do Filament `≈2 h`
Fecha **H5** e **M7**.
- `ProductPolicy` e `DosingContainerPolicy`
- `BaseResource` com `$shouldCheckPolicyExistence = false`, herdada por todos os Resources
- `Definicoes::canAccess()` com verificação de cargo
- `abort_unless` explícito no `IncidentChatWidget` (fecha L5)
- Correr a suite: espera-se que alguns testes fiquem vermelhos, é o sinal de que o gate passou a funcionar

### Lote 9 — Backups a sério `≈1 h`
Fecha **H6**.
- `postgresql-client` no `Dockerfile`
- Dump enviado para o R2, ficheiro local apagado
- Ligar os backups automáticos do plugin Postgres no Railway
- Verificar que o de amanhã às 03:00 aparece no R2

### Lote 10 — Validação de gama no `hanna:sync` `≈1 h`
Fecha **H8**.
- `LIMITES` e `saneado()` antes do `upsert`
- Verificação de plausibilidade da `dt`
- Teto por ciclo em `descontarBidao()`

### Lote 11 — Dependências e limpezas `≈45 min`
Fecha **M4**, **M3**, **M5**, **M1**, **L2**, **L6**.
- `composer update guzzlehttp/guzzle league/commonmark`
- `HealthController`: `REMOTE_ADDR` + `HEALTH_CHECK_IPS` da config, ou tirar o `version`
- `hash_equals` no `MetricsController`
- E-mail de erro sem trace; endereço para `config/logging.php`
- Apagar `piscinas-mmcrespo-main` da CSP e do CLAUDE.md
- Corrigir o CLAUDE.md: Sentry está instalado, `Failed` era código morto, documentar `/m` (ou o facto de ter sido apagado), `/api/*` e `/offline-sync/*`

---

## 11. O que ficou em aberto

Três coisas que preciso de confirmar contigo ou com acesso que não tenho:

1. **Rede pública do Postgres.** Serviço Postgres no Railway → *Settings* → *Networking*. Se houver TCP Proxy, a base de dados está exposta à internet e isso passa a ser o achado número um deste relatório.
2. **Backups do plugin Postgres.** Serviço Postgres → *Backups*. Se estiverem desligados, o H6 sobe para crítico.
3. **`SESSION_LIFETIME` em produção.** 120 ou 43200? Decide a gravidade do M6.

Para os números do raio, quando tiveres acesso de leitura à base de dados:

```sql
SELECT 'daily_records' t, count(*) FROM daily_records
UNION ALL SELECT 'incidents', count(*) FROM incidents
UNION ALL SELECT 'record_photos', count(*) FROM record_photos
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'products', count(*) FROM products
UNION ALL SELECT 'sensor_readings', count(*) FROM sensor_readings;
```
