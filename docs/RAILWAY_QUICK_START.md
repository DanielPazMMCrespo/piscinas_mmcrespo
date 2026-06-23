# 🚀 RAILWAY DEPLOYMENT — GUIA RÁPIDO

**⏱️ Tempo: ~15 minutos até app online**

---

## PASSO 1: CRIAR PROJETO RAILWAY (2 min)

1. Ir a: **https://railway.app**
2. Login com GitHub
3. Clica **"New Project"**
4. Seleciona **"Deploy from GitHub"**
5. Autoriza repositório: `DanielPazMMCrespo/piscinas_mmcrespo`
6. Seleciona **Branch: `deploy/postgresql-clean`**

Railway começa a fazer clone... ✅

---

## PASSO 2: ADICIONAR POSTGRESQL (1 min)

Na dashboard Railway:

1. Clica **"Add Service"**
2. Seleciona **"Database"** → **"PostgreSQL"**
3. Railway cria automaticamente:
   - Username: `postgres`
   - Password: (gerado)
   - Database: `piscinas_mmcrespo`
   - `DATABASE_URL` (copiado automaticamente)

✅ PostgreSQL está criado!

---

## PASSO 3: CONFIGURAR ENVIRONMENT VARIABLES (5 min)

Na dashboard Railway, clica em **"Variables"** (ou "Environment")

**COPIA E COLA EXATAMENTE ISTO:**

```
APP_NAME=Piscinas MMCrespo
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:frr9NNKElsyLBlFQoyaP8WGFkphOJFSRsHwsuMCZDJw=
APP_URL=https://seu-dominio.com
APP_LOCALE=pt_PT
APP_FALLBACK_LOCALE=en

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_DOMAIN=null

QUEUE_CONNECTION=database
CACHE_STORE=database

FILESYSTEM_DISK=local

LOG_CHANNEL=stack
LOG_LEVEL=debug

MAIL_MAILER=log
MAIL_SCHEME=tls
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=seu-email@mmcrespo.pt
MAIL_PASSWORD=sua-senha
MAIL_FROM_ADDRESS=no-reply@mmcrespo.pt
MAIL_FROM_NAME=Piscinas MMCrespo

BROADCAST_CONNECTION=log

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

HANNA_CLOUD_EMAIL=daniel.paz@mmcrespo.pt
HANNA_CLOUD_PASSWORD=sua_senha_hanna

GEMINI_API_KEY=placeholder

VITE_APP_NAME=Piscinas MMCrespo
```

**IMPORTANTE:**
- ❌ NÃO precisa adicionar `DATABASE_URL` (Railway cria automaticamente)
- ✅ `APP_KEY` já está gerado (ver DEPLOYMENT_SECRETS.local)
- ✅ Tudo o resto pode deixar como está

---

## PASSO 4: DEPLOY (Automático - 3 min)

Railway detecta:
- ✅ `Procfile` (como rodar app)
- ✅ `composer.json` (dependências)
- ✅ `artisan` (Laravel CLI)

Depois de colar variáveis, Railway faz:
1. Rodar `composer install`
2. Rodar migrations (`php artisan migrate`)
3. Iniciar app (`php artisan serve`)

**Vai aparecer na dashboard:** ✅ "Build Successful" + "Deployment Successful"

---

## PASSO 5: TESTAR (2 min)

Railway cria URL automática tipo: `https://xxx-xxx-xxx.railway.app`

1. Clica no link
2. Deve aparecer login do Piscinas MMCrespo
3. Login com:
   ```
   Email: admin@example.com
   Password: password
   ```
4. Se entra no dashboard = ✅ SUCESSO!

---

## PASSO 6: DOMÍNIO (3 min config + DNS propagação)

### Em Railway:
1. Project Settings → **Domains**
2. Add Domain
3. Inserir: `leiria.mmcrespo.pt` (ou seu-dominio.com)
4. Railway gera CNAME automático

### No teu registador de domínio (ex: GoDaddy, NIC, etc.):
1. Vai a DNS settings
2. Cria record:
   ```
   Tipo: CNAME
   Nome: leiria
   Valor: (copia de Railway)
   TTL: 3600
   ```
3. Salva

⏳ Aguarda 5-30 min para propagação DNS

Depois: `https://leiria.mmcrespo.pt` funciona! ✅

---

## 📋 CHECKLIST RÁPIDO

```
ANTES DE CLICAR DEPLOY
□ GitHub conectado (deploy/postgresql-clean branch)
□ PostgreSQL adicionado
□ Variáveis coladas (copia/cola exatamente)
□ Procfile existe (verificar em repo)

DEPOIS DE DEPLOY
□ Build completo (Railway dashboard)
□ Migrations correu (ver logs)
□ App acessível em https://xxx.railway.app
□ Login funciona
□ Dashboard carrega

DEPOIS DNS
□ CNAME adicionado no registador
□ DNS propagado (5-30 min)
□ https://leiria.mmcrespo.pt funciona
```

---

## 🆘 SE FALHAR

### "Build failed"
→ Ver logs em Railway → Logs
→ Procura erro (tipo "migrations failed")
→ Diz-me o erro exato

### "App online mas login não funciona"
→ Database não conectou
→ Verificar se DATABASE_URL está em Railway

### "Domínio não funciona após 30 min"
```bash
# Testar DNS
nslookup leiria.mmcrespo.pt

# Se ainda não resolver:
# Esperar mais 1-2h ou contactar registador domínio
```

---

## 📞 LINKS ÚTEIS

- Railway Dashboard: https://railway.app
- Logs em tempo real: Railway → Project → Logs
- Documentação Railway: https://docs.railway.app/

---

**🎉 PRONTO! Segue os passos acima e app está online em ~15 minutos!**

Quando terminares Railway, diz-me os resultados:
- ✅ ou ❌ Deploy sucesso?
- URL gerado por Railway?
- Login funcionou?
