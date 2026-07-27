# Railway — Filas (database queue) em produção

Driver `database` sobre PostgreSQL. O serviço **web** continua a correr nginx + php-fpm
via `docker-entrypoint.sh`; um serviço **worker** dedicado (mesma imagem) corre o
`queue:work` sem nginx.

## 1. Pré-requisito: tabela `jobs` existe

A tabela é criada por `migrate --force` no `docker-entrypoint.sh` (migrações
`*_create_jobs_table` e `2026_06_15_000002_restore_jobs_table`, idempotentes).
`failed_jobs` tem coluna `uuid` → compatível com `QUEUE_FAILED_DRIVER=database-uuids`.

Confirmar antes de ativar a fila (shell do serviço web):

```bash
php artisan migrate --force
php artisan tinker --execute="echo Schema::hasTable('jobs') ? 'jobs OK' : 'FALTA jobs';"
```

## 2. Variáveis — serviço **web**

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://piscinasmmcrespo.up.railway.app
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=pgsql
DB_QUEUE_TABLE=jobs
DB_QUEUE=daily-records,default
DB_QUEUE_RETRY_AFTER=120
QUEUE_FAILED_DRIVER=database-uuids
SESSION_SECURE_COOKIE=true
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

Sensíveis (confirmar que já existem — nunca commitar):
`DATABASE_URL`, `APP_KEY`, `ADMIN_PASSWORD_DANIEL`, `ADMIN_PASSWORD_MARCIO`.

> `DB_QUEUE_RETRY_AFTER=120` > job `timeout=60` (`ProcessDailyRecordAfterCreate`) —
> evita reentrega de um job ainda em execução.

## 3. Serviço **worker** (novo)

- **Source**: o mesmo repo/imagem do serviço web (Railway → New Service → mesmo repo).
- **Variables**: partilhar **exatamente** as mesmas do web (incl. `DATABASE_URL`,
  `APP_KEY`, `QUEUE_*`, `DB_QUEUE_*`). Usar "Reference Variable" do serviço web/DB.
- **Custom Start Command**:

```
php artisan queue:work database --queue=daily-records,default --sleep=2 --tries=3 --timeout=60 --max-time=3600
```

O `docker-entrypoint.sh` deteta o comando-override (qualquer argumento) e entra em
**modo worker**: faz setup mínimo (storage + `config:cache`), **não** corre
nginx/php-fpm nem migrate/seed (o web é o dono do schema), e faz `exec` do comando.

> Não definir health check HTTP no worker (não escuta porta). `--max-time=3600`
> recicla o processo de hora a hora (liberta memória); o Railway reinicia-o.

## 4. Operação / health

```bash
php artisan queue:failed                 # listar jobs falhados
php artisan queue:retry all              # reenfileirar todos os falhados
php artisan queue:prune-failed --hours=168  # limpar falhados > 7 dias
```

Cron sugerido (Railway cron service, mesma imagem, start command):
`php artisan queue:prune-failed --hours=168` — semanal.

## 5. Ordem de ativação (evitar jobs órfãos)

1. Garantir migrações aplicadas (passo 1).
2. Criar o serviço worker e confirmar que arranca (`[entrypoint] ... worker mode`).
3. Só então mudar/garantir `QUEUE_CONNECTION=database` no web e redeploy.
