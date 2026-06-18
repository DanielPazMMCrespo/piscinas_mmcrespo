# Security Guide

Visão geral das medidas de segurança implementadas em Piscinas MMCrespo.

---

## 🔐 Nível de Segurança

**Rating: ✅ ACIMA DA MÉDIA** (para aplicação CRUD típica)

| Área | Implementação | Rating |
|------|---|---|
| **Autenticação** | Laravel Fortify + CSRF | ✅ |
| **Autorização** | spatie/laravel-permission (roles) | ✅ |
| **Encriptação Transporte** | HTTPS (SSL/TLS) | ✅ |
| **Encriptação Dados** | Laravel Encryption (AES-256) | ✅ |
| **SQL Injection** | Eloquent prepared statements | ✅ |
| **XSS** | Blade escaping + CSP (partial) | ⚠️ |
| **CSRF** | Token validation | ✅ |
| **Headers Segurança** | X-Frame-Options, X-Content-Type-Options | ✅ |
| **Rate Limiting** | Throttle middleware | ⚠️ |
| **Backup** | Automático Railway | ✅ |

---

## 🔑 Autenticação & Autorização

### Roles (spatie/laravel-permission)

```php
// Três papéis definidos:
- Admin              // Acesso total
- Técnico           // Registos + incidentes
- Nadador-Salvador  // Apenas leitura (observações)
```

### Gate & Policy

```php
// Em AuthServiceProvider:
Gate::define('view-audit-log', function (User $user) {
    return $user->hasRole('Admin');
});

// Em Resource (Filament):
public static function canAccess(): bool
{
    return auth()->user()?->hasRole('Admin');
}
```

### Password Hashing

```php
// Laravel default: bcrypt (5-12 rounds)
user->password = Hash::make('password');  // NEVER plaintext

// Verificar:
Hash::check('inputPassword', user->password);
```

---

## 🛡️ Proteção contra Ataques Comuns

### 1. SQL Injection

**Status: ✅ SAFE**

```php
// ✅ SEGURO (Eloquent):
DailyRecord::where('ph', '>', 7.0)->get();

// ✅ SEGURO (Raw com bindings):
DailyRecord::whereRaw('ph > ?', [7.0])->get();

// ❌ INSEGURO (Rejeitado):
// DB::select("SELECT * FROM daily_records WHERE ph > " . $input);
```

**Regra:** Nunca usar concatenação de strings em queries. Eloquent ORM é padrão.

### 2. Cross-Site Scripting (XSS)

**Status: ⚠️ MITIGADO (não total)**

```blade
{{-- ✅ SEGURO (default Blade escaping) --}}
{{ $dailyRecord->acao_corretiva }}
{{-- Output: &lt;script&gt;... &lt;/script&gt; --}}

{{-- ❌ PERIGOSO (raw HTML) --}}
{!! $dailyRecord->acao_corretiva !!}
{{-- Use APENAS se conteúdo trusted --}}
```

**Mitigação:** Usar `{{ }}` sempre, never `{!! !!}` para user input.

**CSP (partial):** Middleware `SecurityHeaders` configura:

```php
// Em app/Http/Middleware/SecurityHeaders.php:
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');  # Clickjacking protection
header('Referrer-Policy: strict-origin-when-cross-origin');
```

**TODO (future):** Adicionar CSP full header (`Content-Security-Policy: default-src 'self'`).

### 3. Cross-Site Request Forgery (CSRF)

**Status: ✅ SAFE**

```php
// ✅ AUTOMÁTICO em forms:
@csrf  {{-- Gera <input type="hidden" name="_token"> --}}

// ✅ AUTOMÁTICO em Filament:
// Filament adiciona automaticamente

// ❌ API calls sem token falham:
// POST /api/daily-records sem _token → 419 error
```

### 4. Authentication Bypass

**Status: ✅ SAFE**

```php
// Routes protegidas por middleware:
Route::middleware('auth')->group(function () {
    Route::resource('daily-records', DailyRecordController::class);
});

// Sem token/sessão válida → 401 Unauthorized
```

### 5. Authorization Bypass

**Status: ✅ SAFE**

```php
// Filament resources checam role:
public static function canCreate(): bool
{
    return auth()->user()?->hasAnyRole(['Admin', 'Técnico']);
}

// Sem role → ação oculta + erro 403 se acesso direto
```

### 6. Insecure Direct Object References (IDOR)

**Status: ✅ SAFE**

```php
// ❌ Inseguro (sem check):
Route::get('/daily-records/{id}', function ($id) {
    return DailyRecord::find($id);  // Qualquer um acessa
});

// ✅ Seguro (com policy):
Route::get('/daily-records/{id}', function (DailyRecord $record) {
    Gate::authorize('view', $record);  // Check owner/role
    return $record;
});

// Filament automático: só mostra records do user
```

---

## 🔐 Encriptação

### Em Trânsito (Transport)

**Status: ✅ HTTPS OBRIGATÓRIO (Prod)**

```php
// Em config/session.php:
'secure' => env('SESSION_SECURE_COOKIE', false),  # true em prod
'http_only' => true,  # Cookies não acessíveis via JS
'same_site' => 'strict',  # CSRF protection
```

**Railway:** SSL automático. Local (dev) = HTTP OK.

### Em Repouso (At Rest)

**Status: ✅ ENCRIPTADO**

```php
// Laravel criptografa:
// - Sessions (se SESSION_ENCRYPT=true)
// - Cookies
// - Cache (se usar redis/database)

// Em .env:
SESSION_ENCRYPT=true  # ✅ Ativo em prod
```

**Manual:** Para dados sensíveis, usar `Crypt::encrypt()`:

```php
$encrypted = Crypt::encrypt($password);
$decrypted = Crypt::decrypt($encrypted);
```

---

## 📋 Conformidade Regulatória

### CN 14/DA (DGS 2009)

**Requisitos:**

1. ✅ **Auditoria completa** — Activity log via spatie/laravel-activitylog
2. ✅ **Histórico de valores** — Append-only pattern (ADR 0002)
3. ✅ **Rastreabilidade** — Cada ação registada com utilizador + timestamp
4. ⚠️ **Conformidade contínua** — Dashboard mostra status CN 14/DA

### NP 4542:2017

**Requisitos:**

1. ✅ **Tabela de registos** — PDF report `RelatorioPdf`
2. ✅ **Coluna conforme/não-conforme** — Baseada em limites CN 14/DA
3. ⚠️ **Assinaturas** — Campo em PDF, não integrado digitalmente

### DR 5/97

**Requisitos:**

1. ✅ **Limites temperaturas** — Configuráveis por piscina
2. ✅ **Validação parametros** — Smart validation em DailyRecord

---

## 🚨 Gestão de Secrets

### Armazenamento

**Status: ✅ SAFE**

```bash
# ❌ Nunca em código:
DB_PASSWORD=mypassword  # NO!

# ✅ Em .env:
DB_PASSWORD=***secret***  # .env não commitado

# ✅ Em produção:
Railway → Project Settings → Variables  # Secreto, não no git
```

### Checklist Secrets

- [x] `.env` não commitado (`.gitignore`)
- [x] `.env.example` sem valores sensíveis
- [x] `APP_KEY` gerado (não default)
- [x] `HANNA_CLOUD_PASSWORD` não em logs
- [x] Seeders com passwords fake (não production)

### Rotação de Secrets

```bash
# Mudar APP_KEY:
php artisan key:generate --show
# Nota: invalida cookies/sessions existentes

# Mudar DB password:
# 1. Atualizar em Railway Variables
# 2. Redeployar
```

---

## 🔒 File Upload Security

**Status: ✅ SAFE**

```php
// Em DailyRecord:
public function fotos()
{
    return $this->hasMany(RecordPhoto::class);
}

// Validação em form:
FileUpload::make('fotos')
    ->image()
    ->maxSize(5120)  # 5MB
    ->acceptedFileTypes(['image/jpeg', 'image/png'])
    ->visibility('private'),

// Storage privado:
// arquivo salvo em storage/app/uploads/ (não public/)
// Acesso via signed URL temporário
```

**MIME validation:** Filament valida extensão + MIME type.

**Limitações:**
- Max 5MB por foto
- Apenas JPEG/PNG
- Stored privately (não accessible direto)

---

## 📊 Session Security

**Status: ✅ SAFE**

```php
// Em .env:
SESSION_DRIVER=database    # Sessões em DB
SESSION_LIFETIME=120       # 2 horas
SESSION_ENCRYPT=true       # Encriptado
SESSION_SECURE_COOKIE=true # HTTPS only (prod)
SESSION_HTTP_ONLY=true     # Não acessível via JS
SESSION_SAME_SITE=strict   # CSRF protection
```

**Logout:** `php artisan session:clear` remove todas as sessões (se necessário).

---

## 🚨 Rate Limiting

**Status: ⚠️ BÁSICO (future improvement)**

```php
// Em routes/api.php:
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/health', ...);
});

// Default: 60 requests/minuto por IP
// API calls: 300 requests/minuto se autenticado
```

**TODO:** Implementar rate limiting por utilizador em endpoints sensíveis (ex: PDF generation).

---

## 🛠️ Security Checklist (Pre-Production)

- [x] `APP_DEBUG=false` em .env produção
- [x] `APP_ENV=production`
- [x] `.env` não commitado
- [x] `APP_KEY` gerado (não default)
- [x] `SESSION_SECURE_COOKIE=true`
- [x] `SESSION_ENCRYPT=true`
- [x] HTTPS forçado (Railway automático)
- [x] Password seeders alteradas
- [x] Sensitive logs não expostos
- [x] CORS configurado (se necessário)
- [ ] CSP header completo (future)
- [ ] Rate limiting (future)
- [ ] 2FA (future)
- [ ] OWASP Top 10 audit (future)

---

## 🔍 Audit & Monitoring

### Activity Log

```php
// Todas as ações registadas:
Activity::create([
    'description' => 'created',
    'subject_type' => 'DailyRecord',
    'subject_id' => 123,
    'user_id' => 1,
    'properties' => ['ph' => 7.4],
]);

// Aceder: /admin/activity-logs
```

### Logs de Erro

```bash
# Ver errors:
tail storage/logs/laravel.log

# Em produção (Railway):
railway logs | grep -i error
```

### Failed Logins

```php
// TODO: Implementar rate limiting failed logins
// Após 5 falhas → block temporário
```

---

## 📞 Reportar Vulnerabilidades

Se encontrar issue de segurança:

1. **NÃO publicar** em GitHub/público
2. **Contactar:** daniel.paz@mmcrespo.pt
3. **Descrever:** Tipo, passos reprodução, impacto
4. **Aguardar:** Resposta <48h

---

## 📚 Referências

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Laravel Security](https://laravel.com/docs/12/security)
- [spatie/laravel-permission](https://github.com/spatie/laravel-permission)
- [DGS CN 14/DA](https://www.dgs.pt/)

---

**Última atualização:** 2026-06-18  
**Reviewado por:** Daniel Paz  
**Próxima revisão:** 2026-12-18
