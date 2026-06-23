# 🚀 CHECKLIST DEPLOYMENT — HORA H

**Status:** Tudo pronto. Faltam apenas detalhes de configuração Railway + domínio.

---

## 📋 CHECKLIST PRÉ-DEPLOYMENT (15 min)

### ✅ CÓDIGO & SEGURANÇA (Tudo OK)
- [x] APP_DEBUG=false em .env
- [x] SESSION_ENCRYPT=true
- [x] SQL injection fix aplicado
- [x] UserRole constants implementados
- [x] Schema limpo (zero morto)
- [x] PostgreSQL otimizações adicionadas
- [x] Procfile criado

### ⏳ RAILWAY (Faz isto antes da hora H)

#### 1️⃣ CONECTAR GITHUB (5 min)
```
Railway Dashboard → Create Project
├─ Selecionar: "Deploy from GitHub"
├─ Autorizar repositório: DanielPazMMCrespo/piscinas_mmcrespo
└─ Selecionar branch: deploy/postgresql-clean
```

#### 2️⃣ ADICIONAR POSTGRESQL (2 min)
```
Railway → Add Service → PostgreSQL
├─ Cria automático credenciais
├─ Cria automático DATABASE_URL
└─ Cria automático postgres user
```

#### 3️⃣ CONFIGURAR ENVIRONMENT VARIABLES (5 min)

Ir a: Railway → Project Settings → Variables

Copiar valores de `.env.production.example` e preencher:

```
APP_NAME = Piscinas MMCrespo
APP_ENV = production
APP_DEBUG = false
APP_KEY = base64:... (GERA NOVO COM: php artisan key:generate)
APP_URL = https://seu-dominio.com (ou https://xxx.railway.app temporário)

SESSION_ENCRYPT = true
SESSION_SECURE_COOKIE = true
SESSION_DRIVER = database
SESSION_LIFETIME = 120

QUEUE_CONNECTION = database
CACHE_STORE = database

LOG_CHANNEL = stack
LOG_LEVEL = debug

MAIL_MAILER = log
MAIL_FROM_ADDRESS = no-reply@mmcrespo.pt
MAIL_FROM_NAME = Piscinas MMCrespo

HANNA_CLOUD_EMAIL = seu-email@mmcrespo.pt
HANNA_CLOUD_PASSWORD = seu-password

GEMINI_API_KEY = placeholder
```

**Importante:**
- `DATABASE_URL` é criado automaticamente por Railway (não precisa adicionar)
- `APP_KEY` gerado local: `php artisan key:generate --show`

#### 4️⃣ DEPLOY (1 min — automático)
```
Railway detecta Procfile + composer.json
├─ Composer install automático
├─ Migrations correm automático
└─ App fica online
```

#### 5️⃣ DOMÍNIO (3 min configuração + 5-30 min propagação DNS)

**Em Railway:**
```
Project → Settings → Domains
├─ Add Domain
├─ Inserir: leiria.mmcrespo.pt (ou seu-dominio.com)
├─ Railway gera: CNAME apontando para railway.app
└─ SSL automático ✅
```

**No teu registar de DNS (registador de domínio):**
```
Tipo: CNAME
Nome: leiria (ou seu-subdomínio)
Valor: xxx.railway.app (copiar de Railway)
TTL: 3600
```

Aguardar propagação (5-30 min).

---

## 🔑 VALORES QUE PRECISAS GERAR AGORA

### 1. APP_KEY (Laravel)
```bash
php artisan key:generate --show
# Vai aparecer: base64:xxxxxxxxxxxxx
# Copia isto para Railway → APP_KEY
```

### 2. HANNA_CLOUD_EMAIL e PASSWORD
Já tens isto (contactos Hanna Cloud).

### 3. DATABASE_URL
Railway gera automaticamente — não precisa fazer nada.

---

## 📝 STEP-BY-STEP PARA HORA H

### Quando estiver pronto para deploy (amanhã ou hoje):

#### Passo 1: Gerar APP_KEY (1 min)
```bash
php artisan key:generate --show
# Copiar valor
```

#### Passo 2: Railway Dashboard (10 min)
```
1. Criar novo projeto
2. Conectar GitHub (deploy/postgresql-clean branch)
3. Adicionar PostgreSQL
4. Preencher 15 variáveis de ambiente (usar template abaixo)
5. Configurar domínio
6. Deploy automático começa
```

#### Passo 3: Validar Deploy (10 min)
```
1. Aguardar Railway finish build (2-3 min)
2. Aceder: https://seu-projeto.railway.app
3. Testar login
4. Testar criar registo
5. Testar gráficos
6. Testar sensor Hanna (se configurado)
```

#### Passo 4: Apontar Domínio (5 min config + 30 min propagação)
```
1. Copiar CNAME de Railway
2. Adicionar em registador de domínio
3. Testar: nslookup leiria.mmcrespo.pt
4. Quando resolver: https://leiria.mmcrespo.pt funciona
```

---

## 🎯 TEMPLATE DE VARIÁVEIS RAILWAY

Copia isto, preenche valores, cola em Railway:

```
APP_NAME=Piscinas MMCrespo
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:... (gera com php artisan key:generate --show)
APP_URL=https://xxx.railway.app (muda depois para domínio real)
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
MAIL_USERNAME=seu-email@exemplo.com
MAIL_PASSWORD=seu-password
MAIL_FROM_ADDRESS=no-reply@mmcrespo.pt
MAIL_FROM_NAME=Piscinas MMCrespo

BROADCAST_CONNECTION=log

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

HANNA_CLOUD_EMAIL=seu-email@mmcrespo.pt
HANNA_CLOUD_PASSWORD=seu-password-hanna

GEMINI_API_KEY=placeholder

VITE_APP_NAME=Piscinas MMCrespo
```

---

## ⚡ CHECKLIST RÁPIDO ANTES DE CLICAR DEPLOY

```
CODE
✅ Branch: deploy/postgresql-clean
✅ Procfile existe
✅ .env.production.example completo
✅ composer.json OK
✅ Schema limpo

RAILWAY
⏳ Conta criada
⏳ GitHub conectado
⏳ PostgreSQL adicionado
⏳ Environment variables preenchidas
⏳ APP_KEY gerado

DOMÍNIO
⏳ DNS configurado (ou pronto para configurar pós-deploy)
⏳ CNAME de Railway copiado

SEGURANÇA
✅ APP_DEBUG=false
✅ SESSION_SECURE_COOKIE=true
✅ Credenciais seguras
```

---

## 📊 TIMELINE FINAL

| Atividade | Tempo | Total |
|-----------|-------|-------|
| Gerar APP_KEY | 1 min | 1 min |
| Railroad setup (9 steps) | 10 min | 11 min |
| Deploy automático | 2-3 min | 13-14 min |
| Validação (testes) | 5 min | 18-19 min |
| DNS configuração | 5 min config | 23-24 min |
| Propagação DNS | 5-30 min | 28-54 min |
| **TOTAL** | | **~30 min até online** |

---

## 🆘 PROBLEMAS COMUNS & SOLUÇÃO

### "Railway says: Procfile missing"
✅ Já existe. Se não aparecer:
```bash
git add Procfile
git commit -m "add: Procfile"
git push
# Railway redeploy automático
```

### "DATABASE_URL not found"
✅ Railway cria automático. Se não aparecer:
1. Railway Dashboard → Resources → PostgreSQL
2. Clicar em PostgreSQL → Ver credenciais
3. Copiar connection string completa
4. Adicionar manualmente como `DATABASE_URL`

### "Migrations fail"
Provavelmente variáveis de ambiente não sincronizadas:
1. Ver logs: Railway → Logs
2. Redeployar: Railway → Redeploy
3. Se persistir, fazer rollback para main

### "App online mas sensor Hanna não funciona"
Normal em staging. Verificar:
1. HANNA_CLOUD_EMAIL e PASSWORD corretos
2. Testar: `php artisan hanna:sync --discover` em railway via CLI

### "Domínio não resolve após 30 min"
1. Verificar CNAME em DNS: `nslookup leiria.mmcrespo.pt`
2. Se ainda não resolver, esperar mais 1-2h (TTL longo)
3. Limpar cache local: `ipconfig /flushdns` (Windows)

---

## ✨ QUANDO ESTIVER ONLINE

### Verificar Checklist:
```
□ Aceder https://leiria.mmcrespo.pt (ou domínio seu)
□ Login funciona
□ Dashboard carrega
□ Criar registo diário funciona
□ Gráficos carregam
□ PDF gera
□ Sensor Hanna responde (se ativo)
□ Logs limpas (sem erros 5xx)
```

### Monitoramento:
```
Railway Dashboard:
├─ Logs → verificar erros
├─ Metrics → CPU/memória OK
├─ Deployments → histórico de builds
└─ Settings → backups automáticos
```

---

## 📞 LINKS ÚTEIS

- Railway Docs: https://docs.railway.app/
- Railway CLI: `npm i -g @railway/cli` (opcional)
- Laravel Docs: https://laravel.com/docs
- PostgreSQL: https://www.postgresql.org/docs/

---

**🎯 CONCLUSÃO:** Tudo está pronto. Quando chegar a hora H, segue os passos acima (15-20 min setup Railway + 30 min propagação DNS = ~1h até estar 100% online).

**Próximo passo:** Gera APP_KEY agora (copia para arquivo seguro) e estás pronto. 🚀
