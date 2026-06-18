# 🚀 ROADMAP SESSÃO 15 — DEPLOYMENT RAILWAY

**Data:** 2026-06-18 (Amanhã)  
**Objetivo:** App online em Leiria até fim do dia  
**Status:** 9.4/10 — Pronta para launch

---

## 📋 PLANO EXECUTIVO (90 min total)

```
FASE 1: Railway Setup (25 min)
├─ [5 min] Criar conta Railway + GitHub auth
├─ [10 min] Conectar repo + add PostgreSQL
├─ [5 min] Preencher environment variables (template em DEPLOYMENT_CHECKLIST.md)
└─ [5 min] Deploy automático (Railway corre migrations)

FASE 2: Validação (20 min)
├─ [5 min] Aceder https://xxx.railway.app
├─ [5 min] Testar login + criar registo
├─ [5 min] Verificar gráficos + sensor Hanna
└─ [5 min] Ver logs em Railway (zero 5xx errors)

FASE 3: Domínio (30 min setup + 5-30 min propagação)
├─ [5 min] Copiar CNAME de Railway
├─ [10 min] Adicionar CNAME em registador domínio
├─ [5 min] Testar: nslookup leiria.mmcrespo.pt
└─ [10+ min] Aguardar propagação DNS (pode demorar 5-30 min)

FASE 4: Sentry + Monitoring (10 min)
├─ [5 min] Criar projeto Sentry
├─ [5 min] Adicionar SENTRY_LARAVEL_DSN a Railway
└─ Redeploy automático

FASE 5: Validação Leiria Live (5 min)
├─ Aceder https://leiria.mmcrespo.pt
├─ Testar completo (login, registo, gráficos)
└─ ✅ PRONTO PARA USO
```

---

## 📚 FICHEIROS CRÍTICOS

| Ficheiro | Uso | Status |
|----------|-----|--------|
| `RAILWAY_QUICK_START.md` | Passo-a-passo deploy | ✅ Pronto |
| `DEPLOYMENT_CHECKLIST.md` | Checklist + variables | ✅ Pronto |
| `.env.production.example` | Template variáveis | ✅ Pronto |
| `Procfile` | Configuração Railway | ✅ Pronto |
| `POSTGRESQL_MIGRATION.md` | Fallback se algo corre mal | ✅ Pronto |

---

## 🎯 QUANDO CHEGAR AMANHÃ

### Passo 0: Setup (1 min)
```bash
# Verificar branch e estado
git status
git log --oneline -5
```

### Passo 1: Railway Dashboard (5 min)
1. Ir a https://railway.app
2. Login com GitHub
3. New Project → Deploy from GitHub
4. Selecionar `deploy/postgresql-clean`
5. Add Service → PostgreSQL

### Passo 2: Variables (5 min)
Copiar `.env.production.example` → Railway Variables
- APP_KEY: gera com `php artisan key:generate --show`
- Resto: copiar do template

### Passo 3: Esperar Deploy (3 min)
Railway faz tudo automático:
- ✅ composer install
- ✅ migrations
- ✅ npm build (se necessário)
- ✅ app online em https://xxx.railway.app

### Passo 4: Testar (10 min)
```
□ Aceder URL gerada
□ Login (admin@example.com / password)
□ Criar registo diário
□ Ver dashboard + gráficos
□ Verificar logs em Railway (F12 console, Railway→Logs)
```

### Passo 5: Domínio (5 min + 30 min espera)
1. Railway → Project Settings → Domains
2. Add: leiria.mmcrespo.pt
3. Copiar CNAME
4. Registador domínio → DNS → adicionar CNAME
5. Aguardar propagação

### Passo 6: Sentry (5 min)
1. https://sentry.io → New Project (free tier)
2. Copiar DSN
3. Railway → Add variable `SENTRY_LARAVEL_DSN`
4. Redeploy (automático)

---

## ⚠️ POSSÍVEIS PROBLEMAS

| Problema | Solução |
|----------|---------|
| "DATABASE_URL not found" | Railway cria automático. Se não: Railway→PostgreSQL→copy connection string |
| "Migrations fail" | Ver logs: Railway→Logs. Redeployar. |
| "Procfile not found" | Já existe. Se não aparecer: `git push origin deploy/postgresql-clean` |
| "Domínio não resolve após 30 min" | Limpar cache DNS: `ipconfig /flushdns`. Esperar mais. |
| "App online mas sensor Hanna não funciona" | Normal. Configurar credenciais depois. Circuit breaker garante que app continua. |

---

## 📊 CHECKLIST FINAL

```
PRÉ-DEPLOY
✅ Branch: deploy/postgresql-clean
✅ Working tree clean
✅ 8 commits pusheados
✅ Documentação completa

PÓS-DEPLOY
⏳ Railway account criado
⏳ PostgreSQL adicionado
⏳ Variables configuradas
⏳ Deploy sucesso
⏳ Login funciona
⏳ Gráficos carregam
⏳ Sentry captura erros
⏳ Domínio propagado
```

---

## 🎉 OBJETIVO FINAL

```
https://leiria.mmcrespo.pt ← APP ONLINE E FUNCIONAL
└─ Utilizadores de Leiria podem começar a usar
└─ Feedback real sobre performance/UX
└─ Task 8 + 11 implementadas pós-launch (se necessário)
```

---

## 📞 LINKS ÚTEIS

- Railway: https://railway.app
- Sentry: https://sentry.io
- Docs Railway: https://docs.railway.app
- Docs Laravel: https://laravel.com/docs

---

**Status:** 🟢 PRONTO. Chega amanhã, segue os passos acima, e em 90 min a app está online. 🚀
