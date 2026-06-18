# Troubleshooting Guide

Diagnóstico rápido para problemas comuns.

---

## 🔴 App Não Inicia

### Sintoma: "Laravel not found" / "Command not found"

**Causa:** PHP ou Composer não está instalado/em PATH.

**Solução:**

```bash
# Verificar PHP
php -v
# Output esperado: PHP 8.5.6 NTS

# Verificar Composer
composer --version
# Output esperado: Composer 2.x.x

# Se não tiver, instalar:
# Windows: https://getcomposer.org/download/
# macOS: brew install php composer
# Linux: apt-get install php composer
```

### Sintoma: "SQLSTATE[HY000]: General error"

**Causa:** `database.sqlite` não tem permissões de escrita.

**Solução:**

```bash
# Dev: recriar DB
rm database.sqlite
touch database.sqlite
chmod 666 database.sqlite

# Depois:
php artisan migrate

# Se persistir, verificar permissões pasta:
ls -la database/
# Deve ter 'w' para o user
```

### Sintoma: "No application encryption key has been generated"

**Causa:** `APP_KEY` em branco em `.env`.

**Solução:**

```bash
php artisan key:generate
# Verifica: grep APP_KEY .env
```

---

## 🌐 Problemas de Conectividade

### Sintoma: "Connection refused" ao aceder localhost:8000

**Causa:** Laravel não está a correr ou porta ocupada.

**Solução:**

```bash
# Verificar se Laravel está a correr:
ps aux | grep "php artisan serve"

# Se não:
php artisan serve
# Output: Laravel development server started: http://127.0.0.1:8000

# Se porta 8000 ocupada:
php artisan serve --port=8001
# Aceder: http://localhost:8001
```

### Sintoma: "Connection timed out" em produção (Railway)

**Causa:** App pode estar a fazer cold start ou database offline.

**Solução:**

1. **Verificar logs Railway:**
   ```bash
   # Railway CLI
   railway logs
   ```

2. **Verificar PostgreSQL:**
   ```bash
   # Railway Dashboard → Resources → PostgreSQL
   # Clicar → Ver status
   ```

3. **Redeployar:**
   ```bash
   railway redeploy
   ```

---

## 🗄️ Problemas com Database

### Sintoma: "SQLSTATE[HY000]: General error: 1030 Got an error reading communication packages"

**Causa:** PostgreSQL desconectado ou timeout.

**Solução:**

```bash
# Verificar connection string
echo $DATABASE_URL
# Esperado: postgresql://user:pass@host:5432/dbname

# Testar conexão:
psql "$DATABASE_URL"

# Se falhar, verificar:
# 1. Hostname/IP correto
# 2. Firewall permite conexão
# 3. PostgreSQL está a correr
```

### Sintoma: "Relation 'daily_records' does not exist"

**Causa:** Migrations não correram.

**Solução:**

```bash
# Verificar status migrations:
php artisan migrate:status

# Se algumas pendentes:
php artisan migrate

# Forçar rollback + remigrar (⚠️ perde dados):
php artisan migrate:reset
php artisan migrate --seed
```

### Sintoma: "Syntax error or access violation" na migração

**Causa:** PostgreSQL não suporta a syntax SQLite. Comum com `tinyint`.

**Solução:**

1. **Ver erro específico:**
   ```bash
   php artisan migrate --step
   # Para quando falhar
   ```

2. **Corrigir migração:**
   - `unsignedTinyInteger` → `unsignedSmallInteger`
   - `boolean` → `boolean` (OK em PostgreSQL)

3. **Remigrar:**
   ```bash
   php artisan migrate:rollback
   php artisan migrate
   ```

Vê [POSTGRESQL_MIGRATION.md](../POSTGRESQL_MIGRATION.md) para mais detalhes.

---

## 🔐 Problemas de Login

### Sintoma: "These credentials do not match our records"

**Causa:** Email/senha incorrecto ou user não existe.

**Solução:**

1. **Verificar credenciais padrão:**
   - Email: `admin@mmcrespo.pt`
   - Senha: `password`

2. **Recriar users:**
   ```bash
   php artisan db:seed --class=UserSeeder
   ```

3. **Resetar senha user específico:**
   ```bash
   php artisan tinker
   > User::where('email', 'admin@mmcrespo.pt')->update(['password' => Hash::make('newpass')])
   ```

### Sintoma: "Session expired" após poucos minutos

**Causa:** `SESSION_LIFETIME` muito baixo (default 120 min).

**Solução:**

```bash
# Em .env:
SESSION_LIFETIME=1440  # 24 horas

# Depois:
php artisan cache:clear
```

### Sintoma: "CORS error" ao aceder de subdomain

**Causa:** Session cookie não compartilhado entre subdomains.

**Solução:**

```bash
# Em .env (produção):
SESSION_DOMAIN=.mmcrespo.pt  # Nota o ponto!
```

---

## 📊 Problemas com Dados

### Sintoma: "Registo diário traz pH = 0,00 (null)"

**Causa:** Campos null não foram guardados. É comportamento esperado (não forcing "0").

**Solução:** Verificar se valor foi realmente guardado:

```bash
php artisan tinker
> DailyRecord::find(123)->ph
# Se null, é porque não foi preenchido (OK)
```

### Sintoma: "Gráfico não atualiza após criar registo"

**Causa:** Cache do widget não invalidou.

**Solução:**

```bash
php artisan cache:clear
# Página deve refrescar dados
```

### Sintoma: "Stock negativo (bug)"

**Causa:** Não está a usar `lockForUpdate()` em transação.

**Solução:** Bug a reportar. Enquanto isso:

```bash
# Corrigir manualmente:
php artisan tinker
> StockWarehouse::find(1)->update(['quantidade' => 100])
```

---

## 🔌 Problemas com Sensores Hanna

### Sintoma: "Sensor online mas sem leituras"

**Causa:** Hanna Cloud API não responde ou credenciais inválidas.

**Solução:**

1. **Verificar credenciais em `.env`:**
   ```bash
   echo $HANNA_CLOUD_EMAIL
   echo $HANNA_CLOUD_PASSWORD
   # Devem estar preenchidas
   ```

2. **Testar sincronização manual:**
   ```bash
   php artisan hanna:sync --discover
   # Deve listar sensores encontrados
   ```

3. **Ver logs:**
   ```bash
   tail storage/logs/laravel.log | grep -i hanna
   ```

4. **Verificar cron job:**
   ```bash
   # Em produção, Railway executa:
   # app/Console/Kernel.php → schedule('hanna:sync')
   # Verificar se está a correr:
   php artisan schedule:list
   ```

### Sintoma: "Device not found" ao correr `hanna:sync`

**Causa:** Nenhum sensor configurado em Hanna Cloud.

**Solução:**

1. **Verificar Hanna Cloud:** https://www.hannainst.com/
2. **Adicionar sensor** (ex: BL132)
3. **Aguardar sync** (cron corre a cada 15 min)
4. **Testar:** `php artisan hanna:sync --discover`

---

## 📈 Problemas de Performance

### Sintoma: "Dashboard carrega muito devagar (>5s)"

**Causa:** Queries N+1 ou cache desativado.

**Solução:**

1. **Ver queries:**
   ```bash
   # Em .env (dev):
   DB_QUERY_LOG=true
   
   # Depois, em tinker:
   php artisan tinker
   > DB::enableQueryLog()
   > DailyRecord::with('pool', 'registadoPor')->get()
   > dd(DB::getQueryLog())
   # Procurar queries repetidas por row
   ```

2. **Adicionar eager loading:**
   ```php
   // Em Widget ou Resource:
   DailyRecord::with('pool', 'registadoPor', 'adicoes.produto')
               ->latest('registado_em')
               ->get()
   ```

3. **Ativar cache:**
   ```bash
   # Em .env:
   CACHE_STORE=database  # Ou redis
   ```

4. **Adicionar índices:**
   ```bash
   php artisan tinker
   > Schema::table('daily_records', function (Blueprint $table) {
   >   $table->index(['pool_id', 'registado_em']);
   > });
   ```

### Sintoma: "Relatório PDF demora >30s"

**Causa:** Querendo muitos registos sem paginação.

**Solução:**

1. **Paginar:**
   ```php
   // Em RelatorioPdfResource:
   DailyRecord::where('pool_id', $poolId)
              ->whereBetween('registado_em', [$start, $end])
              ->paginate(100)  // Usar paginate, não get()
   ```

2. **Otimizar query:**
   - Usar `select(['id', 'pool_id', 'ph', ...])` (não `*`)
   - Eager load `with('pool')`

### Sintoma: "App memory usage cresce infinitamente"

**Causa:** Memory leak em loop ou observador mal escrito.

**Solução:**

1. **Ver memory usage:**
   ```bash
   # Depois de alguns testes:
   top -p $(pgrep php)
   ```

2. **Ver código recente** para `unset()` faltando:
   ```php
   // ❌ Má:
   foreach ($records as $record) {
     // ... processa
     // Memory aumenta!
   }
   
   // ✅ Boa:
   foreach ($records->lazy() as $record) {
     // ... processa
     // Liberta memória de $record após cada iteração
   }
   ```

---

## 🔍 Problemas de Logging

### Sintoma: "storage/logs/ muito grande (>1GB)"

**Causa:** `LOG_LEVEL=debug` com muita verbosidade.

**Solução:**

```bash
# Limpar logs:
rm storage/logs/*.log

# Em .env (prod):
LOG_LEVEL=error  # Não debug

# Se precisar debug em prod (cuidado):
LOG_LEVEL=debug
# E adicionar rotação (já em config/logging.php):
'daily' => [
  'driver' => 'daily',
  'path' => storage_path('logs/laravel.log'),
  'level' => 'debug',
  'days' => 14,  # Apaga após 14 dias
]
```

### Sintoma: "Não vejo logs esperados"

**Causa:** Log channel não configurado.

**Solução:**

```bash
# Verificar config/logging.php
cat config/logging.php | grep -A 10 "LOG_CHANNEL"

# Em .env:
LOG_CHANNEL=stack  # Deve ser isto (escreve em storage/logs/)

# Depois:
php artisan cache:clear
```

---

## 🚀 Problemas em Produção (Railway)

### Sintoma: "Procfile missing" erro Railway

**Causa:** Ficheiro `Procfile` não foi commitado.

**Solução:**

```bash
# Verificar se existe:
ls Procfile

# Se não:
echo "web: vendor/bin/heroku-router && php artisan serve --host=0.0.0.0 --port=\$PORT" > Procfile

# Ou simples (melhor):
echo "web: php -S 0.0.0.0:\$PORT -t public" > Procfile

# Commit:
git add Procfile
git commit -m "add: Procfile"
git push
```

### Sintoma: "App crashes após deploy"

**Causa:** Migrations falharam ou env vars faltam.

**Solução:**

1. **Ver logs:**
   ```bash
   railway logs
   ```

2. **SSH para Railway (se precisar):**
   ```bash
   railway shell
   # Depois, dentro:
   php artisan migrate:status
   php artisan migrate
   ```

3. **Redeployar:**
   ```bash
   railway redeploy
   ```

### Sintoma: "Domínio não funciona (tempo limite)"

**Causa:** CNAME ainda não se propagou ou mal configurado.

**Solução:**

1. **Verificar CNAME em registador de DNS:**
   ```bash
   nslookup leiria.mmcrespo.pt
   # Deve resolver para algo como: xxx.railway.app
   ```

2. **Aguardar propagação** (pode demorar 5-30 min, até 2h em casos raros).

3. **Limpar cache local:**
   ```bash
   # Windows:
   ipconfig /flushdns
   
   # macOS:
   dscacheutil -flushcache
   
   # Linux:
   sudo systemctl restart systemd-resolved
   ```

---

## 📞 Quando Pedir Ajuda

Se o problema persiste, forneça:

1. **Output de erro** (copy-paste completo)
2. **Último registo em logs:**
   ```bash
   tail -50 storage/logs/laravel.log
   ```
3. **Versão do code:**
   ```bash
   git log -1 --oneline
   ```
4. **Environment:**
   ```bash
   php -v
   node -v
   composer -v
   ```
5. **Passos reprodução** (exato)

---

**Última atualização:** 2026-06-18
