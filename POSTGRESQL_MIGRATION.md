# 🚀 Guia de Migração: SQLite → PostgreSQL

**Status:** Pronto para testar e fazer deploy para Railway

---

## ✅ O QUE FOI PREPARADO

1. ✅ **Auditoria completa da BD** — zero tabelas mortas, zero colunas órfãs
2. ✅ **Migração de otimização PostgreSQL** — índices de performance adicionados
3. ✅ **Procfile para Railway** — configuração de deployment
4. ✅ **Script automatizado** — `scripts/migrate-to-postgres.sh`
5. ✅ **Configuração pronta** — `config/database.php` + `.env.production.example`

---

## 🔧 TESTE LOCAL COM DOCKER (15 min)

### 1. Iniciar PostgreSQL via Docker

```bash
# Windows PowerShell
docker run --name piscinas-postgres `
  -e POSTGRES_USER=postgres `
  -e POSTGRES_PASSWORD=password `
  -e POSTGRES_DB=piscinas_mmcrespo `
  -p 5432:5432 `
  -d postgres:15-alpine
```

Ou em Bash:
```bash
docker run --name piscinas-postgres \
  -e POSTGRES_USER=postgres \
  -e POSTGRES_PASSWORD=password \
  -e POSTGRES_DB=piscinas_mmcrespo \
  -p 5432:5432 \
  -d postgres:15-alpine
```

### 2. Criar ficheiro `.env.postgresql` para teste

```bash
cp .env .env.postgresql
```

Editar `.env.postgresql`:
```
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=piscinas_mmcrespo
DB_USERNAME=postgres
DB_PASSWORD=password
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
```

### 3. Testar a migração

```bash
# Usar o ficheiro .env.postgresql
cp .env .env.sqlite.backup
cp .env.postgresql .env

# Rodar migrations
php artisan migrate --force

# Verificar conectividade
php artisan tinker
>>> \App\Models\User::count()
>>> \App\Models\Pool::count()
```

### 4. Testar a aplicação

```bash
php artisan serve
# Abrir http://localhost:8000
# Tester login, criar registo, ver gráficos, etc.
```

### 5. Restaurar para SQLite

```bash
# Se tudo OK e quer voltar ao SQLite
cp .env.sqlite.backup .env
php artisan migrate:reset --force
php artisan migrate --force
```

### 6. Limpar Docker

```bash
docker stop piscinas-postgres
docker rm piscinas-postgres
```

---

## 🚀 DEPLOY PARA RAILWAY (30 min)

### 1. Criar conta Railway

1. Ir a [railway.app](https://railway.app)
2. Criar conta (GitHub login recomendado)
3. Criar novo projeto

### 2. Conectar GitHub

1. Autorizar repositório: `DanielPazMMCrespo/piscinas_mmcrespo`
2. Selecionar branch: `deploy/postgresql-clean`

### 3. Adicionar PostgreSQL

1. Clique "Add services" → "PostgreSQL"
2. Railway cria automaticamente credenciais
3. Copiar `DATABASE_URL` (Railway preenche automaticamente)

### 4. Configurar Environment Variables

Na dashboard Railway, adicionar:

```
APP_NAME=Piscinas MMCrespo
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:... (copiar de .env local)
APP_URL=https://seu-dominio.com

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

HANNA_CLOUD_EMAIL=seu-email@mmcrespo.pt
HANNA_CLOUD_PASSWORD=seu-password

GEMINI_API_KEY=placeholder-se-nao-usar
```

**Importante:** Railway preenche `DATABASE_URL` automaticamente. A app usa `config/database.php` que já está configurado para usar `DATABASE_URL` ou as variáveis individuais `DB_*`.

### 5. Deploy Automático

Railway detecta `Procfile` e `composer.json`:
1. `composer install` corre automaticamente
2. Migrations correm via release phase
3. App fica online em `https://seu-projeto.railway.app`

### 6. Configurar Domínio

1. Na Railway: Project Settings → Domains
2. Adicionar domínio: `leiria.mmcrespo.pt`
3. Copiar CNAME de Railway
4. Adicionar em DNS do domínio (registar)
5. Aguardar propagação (5-30 min)
6. Verificar SSL (automático via Railway)

---

## 📋 CHECKLIST ANTES DE PUSH

```
DATABASE
✅ Migração PostgreSQL otimização criada
✅ Procfile criado para Railway
✅ Script migrate-to-postgres.sh pronto
✅ config/database.php pronto
✅ .env.production.example completo

SEGURANÇA
✅ APP_DEBUG=false
✅ SESSION_ENCRYPT=true
✅ Credenciais rotacionadas (placeholders)
✅ SQL injection fix aplicado
✅ UserRole constants implementados

RAILWAY
⏳ Conta criada
⏳ GitHub conectado
⏳ PostgreSQL adicionado
⏳ Environment variables configuradas
⏳ Deploy executado
⏳ Domínio configurado

TESTES PÓS-DEPLOY
⏳ Aceder http/https
⏳ Login funciona
⏳ Criar registo diário
⏳ Ver gráficos
⏳ Sensor Hanna funciona
⏳ PDFs geram
```

---

## 🔄 PROCESSO COMPLETO

### Fase 1: Validação Local (15 min)
```bash
docker run --name piscinas-postgres -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=password -e POSTGRES_DB=piscinas_mmcrespo -p 5432:5432 -d postgres:15-alpine
cp .env .env.postgresql
# Editar .env.postgresql
cp .env.postgresql .env
php artisan migrate --force
php artisan serve
# Testar em http://localhost:8000
```

### Fase 2: Commit & Push (5 min)
```bash
git add .
git commit -m "feat: PostgreSQL migration + Railway deployment ready"
git push origin deploy/postgresql-clean
```

### Fase 3: Railway Setup (15 min)
1. Create project
2. Connect GitHub (`deploy/postgresql-clean`)
3. Add PostgreSQL
4. Configure environment
5. Deploy

### Fase 4: Validação Produção (10 min)
1. Teste https://seu-projeto.railway.app
2. Configure domínio
3. Teste https://seu-dominio.com
4. Recolher feedback Leiria

**⏱️ Tempo total: ~45 minutos**

---

## 🆘 Troubleshooting

### PostgreSQL não conecta localmente
```bash
# Verificar se Docker está rodando
docker ps

# Ver logs do container
docker logs piscinas-postgres

# Testar conexão manualmente
psql -h localhost -U postgres -d piscinas_mmcrespo
```

### Migrations falham
```bash
# Ver erro completo
php artisan migrate --force --verbose

# Reverter (dev apenas)
php artisan migrate:reset --force
```

### Railway não encontra DATABASE_URL
Railway cria automaticamente. Se não aparecer:
1. Ir a Project → Resources → PostgreSQL
2. Copiar connection string
3. Criar variável `DATABASE_URL=postgresql://...` manualmente

### App não inicia em Railway
```bash
# Ver logs em Railway dashboard
# Ou via CLI:
railway logs --tail 100
```

---

## 📞 SUPORTE

- Dúvidas PostgreSQL: [PostgreSQL Docs](https://www.postgresql.org/docs/)
- Dúvidas Railway: [Railway Docs](https://docs.railway.app/)
- Dúvidas Laravel: [Laravel Docs](https://laravel.com/docs)

---

**Status:** ✅ Pronto para deployment. Comece pelo teste local com Docker.
