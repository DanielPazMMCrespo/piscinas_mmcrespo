# Guia de Deploy — Piscinas MMCrespo

> Última atualização: 2026-06-15

## Pré-requisitos do servidor

- PHP 8.2+ com extensões: `pdo_pgsql`, `pdo`, `mbstring`, `xml`, `curl`, `zip`, `gd`
- PostgreSQL 15+
- Node.js 20+ (apenas para `npm run build` — não precisa de correr em produção)
- Composer 2.x
- `pg_dump` no PATH do servidor (para backups automáticos)
- Acesso HTTPS (certificado SSL — Let's Encrypt recomendado)

---

## 1. Deploy inicial

```bash
# 1. Clonar repositório
git clone <repo-url> /var/www/piscinas
cd /var/www/piscinas

# 2. Dependências PHP
composer install --no-dev --optimize-autoloader

# 3. Dependências JS + build de assets
npm ci
npm run build

# 4. Configurar ambiente
cp .env.example .env
php artisan key:generate
```

---

## 2. Configurar .env para produção

```dotenv
APP_NAME="Piscinas MMCrespo"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://piscinas.mmcrespo.pt

# Base de dados PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=piscinas_mmcrespo
DB_USERNAME=piscinas_user
DB_PASSWORD=<password-forte>

# Sessões seguras
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

# Cache e filas
CACHE_STORE=database
QUEUE_CONNECTION=sync

# Email (para notificações de não-conformidade)
MAIL_MAILER=smtp
MAIL_HOST=<servidor-smtp>
MAIL_PORT=587
MAIL_USERNAME=<email>
MAIL_PASSWORD=<password>
MAIL_FROM_ADDRESS=piscinas@mmcrespo.pt
MAIL_FROM_NAME="Piscinas MMCrespo"

# Hanna Cloud (sondas BL132) — opcional
HANNA_EMAIL=<email-hanna>
HANNA_PASSWORD=<password-hanna>
```

---

## 3. Base de dados

```bash
# Criar base de dados PostgreSQL
psql -U postgres -c "CREATE DATABASE piscinas_mmcrespo;"
psql -U postgres -c "CREATE USER piscinas_user WITH PASSWORD '<password>';"
psql -U postgres -c "GRANT ALL PRIVILEGES ON DATABASE piscinas_mmcrespo TO piscinas_user;"

# Executar migrações
php artisan migrate --force

# Popular com dados iniciais (instalaçōes, piscinas, utilizadores)
php artisan db:seed --force
```

**⚠️ ANTES do seed:** alterar as passwords em `database/seeders/UserSeeder.php`:
```php
// Mudar 'password' por passwords fortes e únicas para cada conta
```

---

## 4. Otimizações de produção

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:optimize
```

---

## 5. Permissões de ficheiros

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Diretório de backups (criado automaticamente, mas garantir permissões)
mkdir -p storage/backups
chown www-data:www-data storage/backups
chmod 750 storage/backups

# Criar link simbólico para storage público (uploads)
php artisan storage:link
```

---

## 6. Agendador (cron)

Adicionar ao crontab do utilizador `www-data`:

```cron
* * * * * cd /var/www/piscinas && php artisan schedule:run >> /dev/null 2>&1
```

Isto executa:
- **Backup da BD** — diariamente às 03:00 (guarda em `storage/backups/`, mantém 30 backups)
- **Sync Hanna** — a cada 15 minutos (se `HANNA_EMAIL` estiver configurado)

---

## 7. Configuração Nginx (exemplo)

```nginx
server {
    listen 443 ssl http2;
    server_name piscinas.mmcrespo.pt;

    root /var/www/piscinas/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/piscinas.mmcrespo.pt/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/piscinas.mmcrespo.pt/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.ht { deny all; }

    # Uploads privados (storage/app/local) NÃO ficam em public — correto por defeito.
    # Nunca servir storage/app diretamente.
}

server {
    listen 80;
    server_name piscinas.mmcrespo.pt;
    return 301 https://$host$request_uri;
}
```

---

## 8. Backups

**Automáticos** (via cron): `storage/backups/backup_YYYY-MM-DD_HHmmss.dump` — últimos 30 retidos.

**Manual** (a qualquer momento):
```bash
php artisan backup:database
```

**Restaurar** (PostgreSQL):
```bash
pg_restore -h 127.0.0.1 -U piscinas_user -d piscinas_mmcrespo --clean storage/backups/backup_YYYY-MM-DD_HHmmss.dump
```

**Recomendação adicional**: configurar também backup externo (ex.: rsync para NAS ou S3) dos ficheiros `storage/app/local/` (uploads de fotos dos registos).

---

## 9. Atualizações

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## 10. Utilizadores por defeito (alterar antes do deploy)

| Email | Role | Password (MUDAR) |
|---|---|---|
| admin@mmcrespo.pt | admin | `password` |
| tecnico@mmcrespo.pt | tecnico | `password` |
| ns@mmcrespo.pt | nadador_salvador | `password` |

---

## 11. Checklist final antes de ir live

- [ ] `APP_DEBUG=false` no `.env`
- [ ] `APP_ENV=production` no `.env`  
- [ ] Passwords dos seeders alteradas
- [ ] Certificado SSL ativo e a redirecionar HTTP → HTTPS
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `pg_dump` disponível no PATH do servidor
- [ ] Cron do agendador configurado
- [ ] Backup manual testado e restauro verificado
- [ ] Upload de fotos testado (storage link + permissões)
- [ ] Email de notificações testado (`php artisan tinker` → `Mail::raw(...)`)
- [ ] Login com todas as contas testado (admin, técnico, NS)
- [ ] Registo diário de teste submetido e verificado no dashboard
