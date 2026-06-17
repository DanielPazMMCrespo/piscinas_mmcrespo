# 🚀 PLANO DE DEPLOYMENT — LEIRIA (2026-06-16)

## 1️⃣ AVALIAÇÃO: Railway vs Alternativas

### Railway (Recomendado)
**Pros:**
- ✅ Suporta Laravel + PostgreSQL nativamente
- ✅ Deploy via GitHub push (muito prático)
- ✅ SSL/HTTPS automático
- ✅ Environment variables gerenciadas
- ✅ Backups automáticos
- ✅ $5-10/mês para esta app
- ✅ Uptime SLA 99.9%

**Cons:**
- Suporte comunitário (não enterprise)

### Alternativas Consideradas
| Opção | Custo | Segurança | Praticidade | Recomendação |
|-------|-------|-----------|-------------|--------------|
| **Railway** | $5-10/mês | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅ MELHOR |
| Heroku | $7-50/mês | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | Alternativa (mais caro) |
| Render | $7-50/mês | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | Alternativa |
| AWS Lightsail | $3-10/mês | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | Manual demais hoje |
| Digital Ocean | $5-20/mês | ⭐⭐⭐⭐ | ⭐⭐⭐ | Manual demais hoje |

**Decisão: ✅ Railway é a melhor opção para deploy hoje (rápido, seguro, prático)**

---

## 2️⃣ CHECKLIST DE PRÉ-REQUISITOS

### ✅ FEITO (Sprint 1 — Segurança)
- [x] APP_DEBUG=false
- [x] SESSION_ENCRYPT=true
- [x] SESSION_SECURE_COOKIE=true
- [x] SQL injection fix
- [x] Credenciais rotacionadas (placeholders)
- [x] Sensor Hanna Cloud funcional
- [x] UserRole constants criada

### ⏳ DEVE SER FEITO HOJE (4 horas)
- [ ] A1: Configurar PostgreSQL localmente (30 min)
- [ ] A2: Testar migração SQLite → PostgreSQL (1 h)
- [ ] A3: Atualizar config/database.php se necessário (15 min)
- [ ] A4: Criar .env.production (15 min)
- [ ] A5: Testar app com PostgreSQL (30 min)
- [ ] A6: Criar Railway account + conectar GitHub (15 min)
- [ ] A7: Deploy e verificar em staging (30 min)
- [ ] A8: Configurar domínio + SSL (15 min)

### 🟡 NICE-TO-HAVE (Pós-deployment)
- [ ] Backups automáticos configurados
- [ ] Monitoring + alertas
- [ ] CDN para assets estáticos
- [ ] Rate limiting aprimorado
- [ ] Log aggregation

---

## 3️⃣ PASSO-A-PASSO: DEPLOYMENT HOJE

### FASE 1: Preparar PostgreSQL (30 min)

```bash
# 1. Criar arquivo .env.production
cp .env .env.production
# Editar:
APP_ENV=production
APP_DEBUG=false
DATABASE_URL=postgresql://user:password@host:5432/piscinas_mmcrespo
```

### FASE 2: Testar Migração Localmente (1 h)

```bash
# 1. Backup SQLite atual
cp database/database.sqlite database/database.sqlite.backup

# 2. Exportar dados (se necessário)
php artisan tinker
# Exportar principais tabelas para JSON

# 3. Criar BD PostgreSQL localmente (via Docker recomendado)
docker run --name piscinas-postgres -e POSTGRES_PASSWORD=secret -d postgres

# 4. Atualizar .env local para testar
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_DATABASE=piscinas_mmcrespo

# 5. Rodar migrations
php artisan migrate --force

# 6. Testar app
php artisan serve
# Abrir http://localhost:8000 e verificar

# 7. Rodar seeders se necessário
php artisan db:seed
```

### FASE 3: Configurar Railway (15 min)

1. Criar conta em railway.app
2. Conectar GitHub (autorizar repositório)
3. Criar novo projeto
4. Adicionar PostgreSQL plugin
5. Configurar environment variables:
```
APP_NAME=Piscinas MMCrespo
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:... (copiar de .env)
APP_URL=https://seu-dominio.com

DATABASE_URL=postgresql://...
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

HANNA_CLOUD_EMAIL=seu-email
HANNA_CLOUD_PASSWORD=seu-password
```

### FASE 4: Deploy (30 min)

1. Railway auto-detecta Procfile (Laravel pronto)
2. `composer install` corre automaticamente
3. Migrations rodam no deploy
4. App fica online em staging.railway.app

### FASE 5: Domínio + SSL (15 min)

1. Railway gera SSL automático
2. Conectar domínio (leiria.mmcrespo.pt):
   - Adicionar CNAME em DNS
   - Railway configura automaticamente
3. Verificar HTTPS ✅

---

## 4️⃣ O QUE FAZER AGORA (ORDEM DE PRIORIDADE)

### 🔴 CRÍTICO (Fazer antes de deploy)
1. **Testar PostgreSQL localmente** (1-2h)
   - Rodar migrations
   - Verificar dados migraram
   - Testar app funciona

2. **Criar .env.production** (15 min)
   - Credenciais seguras
   - URLs corretas
   - Secrets gerados

3. **Criar account Railway** (5 min)
4. **Deploy teste** (30 min)

### 🟡 IMPORTANTE (Dentro de 48h)
- [ ] Configurar backups PostgreSQL
- [ ] Monitorar logs em produção
- [ ] Testar sensor Hanna em produção
- [ ] Verificar performance

### ✅ NICE-TO-HAVE (Próxima semana)
- [ ] Completar Task 7 (declare strict_types)
- [ ] Adicionar mais testes
- [ ] Otimizar queries lentas

---

## 5️⃣ RISCOS E MITIGAÇÃO

| Risco | Probabilidade | Impacto | Mitigação |
|-------|--------------|--------|-----------|
| Dados não migrarem | Baixa | Alto | Testar em dev primeiro |
| Performance degrada | Média | Médio | Índices PostgreSQL OK |
| Sensor não conecta | Baixa | Médio | Testar credenciais Hanna |
| SSL não funciona | Muito Baixa | Médio | Railway gerencia automaticamente |
| Down time | Muito Baixa | Alto | Backups automáticos Railway |

---

## 6️⃣ CHECKLIST FINAL PRÉ-DEPLOYMENT

```
DATABASE & MIGRATIONS
□ PostgreSQL testado localmente
□ Migrations correm sem erros
□ Dados íntegros pós-migração
□ Backups funcionam

SEGURANÇA
□ APP_DEBUG=false em produção
□ Credenciais não expostas
□ HTTPS/SSL configurado
□ SESSION_SECURE_COOKIE=true

APLICAÇÃO
□ App roda com PostgreSQL
□ Sensor Hanna funciona
□ Uploads funcionam
□ PDF generation funciona
□ Autenticação funciona

RAILWAY
□ Projeto criado
□ Database plugin adicionado
□ Environment variables configuradas
□ Deploy automático testado

DOMÍNIO
□ DNS apontando para Railway
□ SSL válido
□ App acessível via domínio
```

---

## 7️⃣ TEMPO ESTIMADO

| Fase | Tempo | Total |
|------|-------|-------|
| PostgreSQL local | 1-2h | 1-2h |
| Testes + fixes | 30 min | 1.5-2.5h |
| Railway setup | 30 min | 2-3h |
| Deploy + domínio | 30 min | 2.5-3.5h |
| **TOTAL** | | **2.5-3.5 horas** |

**Status: ✅ POSSÍVEL FAZER HOJE se começar já**

---

## 8️⃣ PRÓXIMAS AÇÕES

### AGORA (próximas 2 horas):
1. ✅ Ler este plano
2. ✅ Decidir: PostgreSQL Docker local ou usar Railway nativo?
3. ✅ Começar migração de dados

### DEPOIS DO DEPLOY:
1. Monitorar logs
2. Testar funcionalidades
3. Coletar feedback Leiria
4. Fazer adjustments conforme necessário

---

**Recomendação Final: Railway é a escolha certa. Rápido, seguro, prático. Deploy possível em 2-3h.**
