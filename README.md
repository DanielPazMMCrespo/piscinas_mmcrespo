# Piscinas MMCrespo — Sistema de Gestão Operacional

[![Build Status](https://github.com/DanielPazMMCrespo/piscinas_mmcrespo/workflows/tests/badge.svg)](https://github.com/DanielPazMMCrespo/piscinas_mmcrespo)
[![Laravel 12](https://img.shields.io/badge/Laravel-12%20LTS-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5.6-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Aplicação web de gestão operacional para piscinas municipais sob contrato da **MMCrespo**, em conformidade com **CN 14/DA (DGS 2009)**, **NP 4542:2017**, e **DR 5/97**.

## 📋 Conteúdo

- [Visão Geral](#visão-geral)
- [Techs & Stack](#techs--stack)
- [Quick Start](#quick-start)
  - [Local](#local)
  - [Produção (Railway)](#produção-railway)
- [Estrutura do Projeto](#estrutura-do-projeto)
- [Funcionalidades Principais](#funcionalidades-principais)
- [Configuração](#configuração)
- [Testes](#testes)
- [Deploy](#deploy)
- [Documentação](#documentação)
- [Troubleshooting](#troubleshooting)
- [Contactos](#contactos)

---

## 🏊 Visão Geral

**Piscinas MMCrespo** é uma plataforma de **monitorização e conformidade** para piscinas públicas. Permite a:

- **Registo diário** de parâmetros de água (pH, cloro, temperatura, turbidez)
- **Validação em tempo real** contra limites legais (CN 14/DA)
- **Gestão de incidentes** e ações corretivas
- **Histórico de stock** de químicos
- **Integração de sensores Hanna** (sondas BL132)
- **Relatórios PDF** regulamentares
- **Dashboard de exceções** (Kanban operacional + alertas)
- **Múltiplos papéis** (Admin, Técnico, Nadador-Salvador)

### Instalações Cobertas

| Instalação | Piscinas | Volume | Temp. Normal |
|---|---|---|---|
| **Leiria** | Competição (25x17.4m) | 900 m³ | 26–27°C |
| **Leiria** | Lazer (25x17.4m) | 600 m³ | 28–30°C |
| **Leiria** | Infantil (17.4x5m) | 50 m³ | 28–30°C |
| **Maceira** | Maceira (16.6x10m) | 170 m³ | 28–30°C |
| **Caranguejeira** | Caranguejeira (16.6x10m) | 170 m³ | 28–30°C |

---

## 🛠 Techs & Stack

| Camada | Tecnologia | Versão |
|---|---|---|
| **Backend** | Laravel | 12 LTS |
| **PHP** | PHP | 8.5.6 NTS |
| **Admin** | Filament | 3.3.x |
| **DB (Dev)** | SQLite | - |
| **DB (Prod)** | PostgreSQL | 16 |
| **Cache** | Redis / Database | - |
| **Queues** | Database | - |
| **PDF** | DomPDF | - |
| **Charts** | Chart.js | - |
| **Sensores** | Hanna Cloud API | - |
| **Deploy** | Railway | - |
| **Npm** | SortableJS, GSAP | - |

### Dependências Principais

```json
{
  "laravel/framework": "^12.0",
  "filament/filament": "^3.3",
  "spatie/laravel-permission": "^6.7",
  "spatie/laravel-activitylog": "^4.7",
  "barryvdh/laravel-dompdf": "^2.1"
}
```

---

## 🚀 Quick Start

### Local

#### Pré-requisitos
- **PHP 8.5+** (NTS, não usar Herd Lite)
- **Composer**
- **Node.js 18+**
- **SQLite** (ou PostgreSQL)
- **Git**

#### 1. Clone & Instale

```bash
# Clone
git clone https://github.com/DanielPazMMCrespo/piscinas_mmcrespo.git
cd piscinas_mmcrespo

# Checkout deploy branch (latest stable)
git checkout deploy/postgresql-clean

# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Copy .env
cp .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database (users, piscinas)
php artisan db:seed

# Build assets
npm run build
```

#### 2. Run Local

```bash
# Terminal 1: Laravel server
php artisan serve
# Acede a http://localhost:8000

# Terminal 2: Vite (hot reload)
npm run dev
```

#### 3. Login

Seeders criam 3 utilizadores:

| Email | Senha | Papel |
|---|---|---|
| `admin@mmcrespo.pt` | `password` | Admin |
| `tecnico@mmcrespo.pt` | `password` | Técnico |
| `nadador@mmcrespo.pt` | `password` | Nadador-Salvador |

> ⚠️ **MUDAR EM PRODUÇÃO** — Vê [Produção](#produção-railway)

#### 4. Opcional: Sensor Hanna

```bash
# Descobrir sensores conectados
php artisan hanna:sync --discover

# Sincronizar leituras (a cada 15 min em cron)
php artisan hanna:sync
```

---

### Produção (Railway)

Para deployment rápido em Railway, siga **[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)**.

**Resumo (15 min):**

1. Criar projeto Railway
2. Conectar GitHub (branch `deploy/postgresql-clean`)
3. Adicionar PostgreSQL
4. Preencher 15 environment variables (template em checklist)
5. Deploy automático
6. Configurar domínio (CNAME)

> **Link direto:** [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)

---

## 📁 Estrutura do Projeto

```
piscinas_mmcrespo/
├── app/
│   ├── Console/
│   │   └── Commands/          # Artisan commands (hanna:sync, etc)
│   ├── Filament/
│   │   ├── Pages/             # Admin pages (Dashboard, etc)
│   │   ├── Resources/         # Admin tables (DailyRecord, Stock, etc)
│   │   └── Widgets/           # Dashboard widgets (Kanban, Charts, etc)
│   ├── Models/                # Eloquent models
│   ├── Services/              # Business logic (AlertasService, etc)
│   └── Mail/                  # Email notifications
├── config/
│   ├── database.php           # DB config (SQLite + PostgreSQL)
│   └── services.php           # 3rd-party services (Hanna, Gemini)
├── database/
│   ├── migrations/            # Schema changes
│   ├── seeders/               # Initial data
│   └── factories/             # Test factories
├── docs/                      # 📌 Documentação (NEW)
│   ├── API.md
│   ├── TROUBLESHOOTING.md
│   ├── PERFORMANCE.md
│   ├── SECURITY.md
│   ├── DATABASE.md
│   └── adr/
│       ├── 0001-postgresql-choice.md
│       ├── 0002-redis-caching.md
│       └── 0003-append-only-pattern.md
├── public/
│   ├── images/                # Pool photos, etc
│   └── storage/               # User uploads (private)
├── resources/
│   ├── js/
│   │   ├── app.js            # Main app (ResizeObserver, keyboard UX)
│   │   └── components/        # Blade components
│   ├── views/
│   │   ├── livewire/         # Livewire components
│   │   └── pdf/              # PDF templates (relatório sanitário)
│   └── css/                  # Tailwind
├── routes/
│   ├── web.php               # Web routes
│   ├── api.php               # API routes (saúde, métricas)
│   └── console.php           # Scheduled commands
├── storage/
│   ├── logs/                 # App logs
│   ├── app/uploads/          # User uploads (private)
│   └── framework/            # Cache, sessions
├── tests/
│   ├── Unit/                 # Unit tests
│   ├── Feature/              # Integration tests
│   └── Browser/              # Dusk browser tests (optional)
├── .env.example              # Environment template
├── .env.production.example   # Production template
├── .github/
│   └── workflows/            # CI/CD (optional)
├── CLAUDE.md                 # Project context & rules
├── DEPLOYMENT_CHECKLIST.md   # 📌 Railway deployment
├── POSTGRESQL_MIGRATION.md   # PostgreSQL setup
├── README.md                 # Este ficheiro
├── package.json
├── composer.json
└── Procfile                  # Railway process definition
```

---

## ✨ Funcionalidades Principais

### 1. Registo Diário Turbo
- **Smart defaults**: piscina pré-selecionada, bomba/água/tanque herdam último estado
- **Validação em tempo real**: semáforo (🟢🟡🔴) contra limites CN 14/DA
- **Ação corretiva**: campo obrigatório quando parâmetro viola limites
- **Adições de químicos**: debita stock instalação, cria log consumo
- **Fotos**: campos opcionais bomba/tanque (evidência fotográfica)

### 2. Dashboard Exception-First
- **Kanban Operacional**: 3 colunas (Por tratar / Em tratamento / Resolvido hoje)
- **Drag-and-drop**: persistente com SortableJS
- **Alertas prioridade**: piscinas sem registo, parâmetros fora limites, stock baixo
- **Notificações admin**: quando ação corretiva não é suficiente

### 3. Histórico & Stock
- **StockInstallationLogResource**: entrada/consumo por instalação
- **StockWarehouseLogResource**: entrada/saída central
- **Validação de quantidade**: não deixa usar stock > disponível
- **Transferência warehouse→instalação**: com debito/crédito + logs

### 4. Incidentes & Resolução
- **Ciclo de vida**: aberto → resolvido (data, utilizador, resolução)
- **Ação "Resolver"** em `IncidentResource`
- **Kanban auto-resolve** quando condição desaparece

### 5. Sensores Hanna
- **Leitura automática**: cron `hanna:sync` (15 min)
- **Sonda discreta** nos cartões de piscina (pH, ORP, Temp)
- **Integração manual**: fallback se sensor offline

### 6. Relatórios PDF
- **CN 14/DA compliant**: coluna "Conforme" (✓/✗)
- **Multi-piscina**: per pool ou por intervalo de datas
- **Assinaturas**: campo impressão
- **Exclui correções**: `whereDoesntHave('correcoes')`

### 7. Papéis & Segurança
- **Admin**: tudo (dashboard, históricos, activity log, audit trail)
- **Técnico**: registos + incidentes (sem audit trail)
- **Nadador-Salvador**: apenas informação geral + observações

### 8. Audit Trail
- **ActivitylogPlugin** ativo: todas as ações registadas
- **Append-only**: correções criam novo registo + referência
- **Activity Logs UI**: menu admin

---

## ⚙️ Configuração

### Environment Variables

**Dev (`.env`):**

```bash
APP_NAME="Piscinas MMCrespo"
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:...
APP_URL=http://localhost:8000
APP_LOCALE=pt_PT

DB_CONNECTION=sqlite
DB_DATABASE=database.sqlite

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

HANNA_CLOUD_EMAIL=seu-email@mmcrespo.pt
HANNA_CLOUD_PASSWORD=seu-password
```

**Produção (Railway `.env`):**

Ver template completo em [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md).

```bash
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### Database

**Dev:** SQLite (automático, `database.sqlite`)

**Prod:** PostgreSQL (Railway)

```bash
# Migrar para PostgreSQL localmente:
DB_CONNECTION=pgsql \
DB_HOST=localhost \
DB_DATABASE=piscinas \
DB_USERNAME=postgres \
DB_PASSWORD=... \
php artisan migrate
```

Vê [POSTGRESQL_MIGRATION.md](POSTGRESQL_MIGRATION.md) para detalhes.

### Cache & Sessions

```bash
# Dev: file-based
CACHE_STORE=file
SESSION_DRIVER=file

# Prod: database (escala melhor em Railway)
CACHE_STORE=database
SESSION_DRIVER=database
```

---

## 🧪 Testes

```bash
# Unit tests
php artisan test --testsuite=Unit

# Feature tests
php artisan test --testsuite=Feature

# Todos
php artisan test

# Com coverage
php artisan test --coverage

# Dusk (browser) — opcional
php artisan dusk
```

---

## 🚀 Deploy

### Pré-requisitos

- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` gerado (`php artisan key:generate --show`)
- [ ] Passwords seeders alteradas
- [ ] PostgreSQL configurado
- [ ] `SESSION_SECURE_COOKIE=true`

### Passos (15 min)

1. **Gerar APP_KEY:**
   ```bash
   php artisan key:generate --show
   # Copiar valor: base64:...
   ```

2. **Railway Dashboard:**
   - Criar projeto, conectar GitHub (branch `deploy/postgresql-clean`)
   - Adicionar PostgreSQL
   - Preencher 15 variáveis (template em checklist)
   - Deploy automático

3. **Validar:**
   ```bash
   curl https://seu-dominio.com/api/health
   # Resposta: {"status":"ok"}
   ```

4. **Configurar domínio:**
   - CNAME em Railway → DNS registador
   - Aguardar propagação (5–30 min)

> **Link direto:** [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)

---

## 📚 Documentação

| Ficheiro | Descrição |
|---|---|
| **[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)** | 15 min setup Railway + domínio |
| **[POSTGRESQL_MIGRATION.md](POSTGRESQL_MIGRATION.md)** | Guia migração SQLite → PostgreSQL |
| **[RAILWAY_QUICK_START.md](RAILWAY_QUICK_START.md)** | CLI Railway (deploy via terminal) |
| **[docs/API.md](docs/API.md)** | Endpoints health, metrics, etc |
| **[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)** | "App não inicia", sensor offline, etc |
| **[docs/PERFORMANCE.md](docs/PERFORMANCE.md)** | Métricas, query tuning, caching |
| **[docs/SECURITY.md](docs/SECURITY.md)** | Headers, encryption, auth |
| **[docs/DATABASE.md](docs/DATABASE.md)** | Schema, migrations, indexes |
| **[docs/adr/](docs/adr/)** | Architecture Decision Records |
| **[CLAUDE.md](CLAUDE.md)** | Contexto projeto & strict rules |

---

## 🆘 Troubleshooting

### "Não consigo aceder a localhost:8000"

```bash
# Porta ocupada? Usar outra:
php artisan serve --port=8001

# Ou verificar se Laravel está a correr:
php artisan tinker
> DB::connection()->getPdo();  # Se OK, app funciona
```

### "Sensor Hanna não conecta"

1. **Verificar credenciais:**
   ```bash
   echo $HANNA_CLOUD_EMAIL
   echo $HANNA_CLOUD_PASSWORD
   ```

2. **Testar manualmente:**
   ```bash
   php artisan hanna:sync --discover
   ```

3. **Ver logs:**
   ```bash
   tail storage/logs/laravel.log | grep -i hanna
   ```

Vê [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) para mais.

### "Dashboard muito lento"

Vê [docs/PERFORMANCE.md](docs/PERFORMANCE.md) — provavelmente N+1 queries ou cache desativado.

```bash
# Listar queries lentas:
php artisan db:show --only-tables | grep -i daily
```

---

## 🔐 Segurança

- ✅ Tipagem estrita (`declare(strict_types=1)`)
- ✅ SQL injection: prepared statements (Eloquent)
- ✅ Sessions: encrypted, secure cookies (prod)
- ✅ Uploads: privados + MIME validation
- ✅ Authorization: spatie/laravel-permission por role
- ✅ Audit trail: spatie/laravel-activitylog

Vê [docs/SECURITY.md](docs/SECURITY.md) para CSP, rate limiting, etc.

---

## 🎯 Regras de Código

1. **Tipagem estrita** — Todo PHP começa com `declare(strict_types=1);`
2. **Append-only** — Correções criam novo registo, não alteram original
3. **Transações DB** — Stock sempre com `DB::transaction()` + `lockForUpdate()`
4. **Validação reativa** — Em formulários, avaliar apenas campos existentes (null-safe)
5. **Migrations idempotentes** — `if (!Schema::hasColumn(...))` para coexistência SQLite/PostgreSQL

Vê [CLAUDE.md](CLAUDE.md) para mais.

---

## 📞 Contactos

- **Daniel Paz** (Desenvolvedor)
  - Email: daniel.paz@mmcrespo.pt
  - GitHub: [@DanielPazMMCrespo](https://github.com/DanielPazMMCrespo)

---

## 📄 Licença

MIT. Vé [LICENSE](LICENSE).

---

## 🔄 Próximas Melhorias

- [ ] Testes Dusk (browser automation)
- [ ] Backup automático PostgreSQL
- [ ] Webhook integrações (Slack, Teams)
- [ ] Mobile app (Flutter)
- [ ] BI dashboard (Metabase)

---

**Última atualização:** 2026-06-18 | **Versão:** 1.0 (PostgreSQL-ready)
