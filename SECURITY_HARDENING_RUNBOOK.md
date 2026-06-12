# Runbook — Hardening Fase 2 + Audit Trail

> Estado: edicoes de ficheiros JA aplicadas pelo assistente. Os comandos de terminal
> abaixo corres TU no Windows (PHP 8.5 / Composer). Corre por ordem.

## 0. Backup primeiro (registo legal)
```powershell
copy database\database.sqlite database\database.backup.sqlite
```

## 1. Instalar o nucleo do audit log (OBRIGATORIO antes de abrir a app)
As edicoes nos models DailyRecord, User e Pool ja referenciam Spatie\Activitylog.
Ate correres este passo, a app lanca "Class not found". Nao abras o painel antes disto.
```powershell
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

## 2. UI do audit log no Filament — DECISAO NECESSARIA
`pxlrbt/filament-activity-log` (o pedido no prompt) na versao atual exige Filament 4/5.
Tu estas em Filament 3.3.52. `composer require pxlrbt/filament-activity-log` sem
constraint vai falhar a resolver ou despromover pacotes. Escolhe uma via:

**Via A (recomendada) — plugin nativo para Filament v3:**
```powershell
composer require rmsramos/activitylog
```
Depois adiciona ao array `->plugins([])` em
`app/Providers/Filament/AdminPanelProvider.php`:
```php
->plugins([
    \Rmsramos\Activitylog\ActivitylogPlugin::make(),
])
```
(O panel ainda nao tem array `->plugins()`; adiciona-o, por ex. logo a seguir a
`->widgets([...])`.)

**Via B — manter pxlrbt, fixando versao compativel com v3:**
```powershell
composer require pxlrbt/filament-activity-log:"^1.0"
```
Se a resolucao falhar, usa a Via A. Confirma o namespace do plugin que o pacote
expoe antes de o adicionar ao `->plugins([])`.

## 3. Formatador de codigo (Pint) — COMMIT SEPARADO
Faz commit das mudancas de seguranca ANTES de correr o Pint. O Pint reformata todos
os PHP e o diff gigante esconde as mudancas de seguranca no mesmo commit.
```powershell
git add -A && git commit -m "Hardening fase 2 + audit trail"
composer require laravel/pint --dev
vendor\bin\pint
```
Nota: sem `pint.json`, o Pint usa o preset `laravel` (nao PSR-12 estrito). Para PSR-12
cria `pint.json` com `{"preset":"psr12"}`. Depois do Pint, revê o diff destes 3
ficheiros que tinham bytes nao-UTF8 (acentos): `app/Filament/Resources/IncidentResource.php`,
`app/Models/RecordPhoto.php`, `app/Providers/Filament/AdminPanelProvider.php`.

## 4. Otimizar autoloader e limpar caches
```powershell
composer dump-autoload -o
php artisan optimize:clear
php artisan filament:clear-cached-components
```

## 5. .env LOCAL — CAUSA QUEBRA DE LOGIN SE IGNORADO
`config/session.php` agora tem `'secure' => env('SESSION_SECURE_COOKIE', true)` e
`'same_site' => 'strict'`. Em dev sobre http://localhost o cookie seguro NAO e enviado
e o login deixa de funcionar. No teu `.env` local (nao no .env.example):
```
SESSION_SECURE_COOKIE=false
```
Em producao (HTTPS) deixa true.

## Verificacao
- Login local funciona (com SESSION_SECURE_COOKIE=false).
- Headers presentes: `curl -I http://localhost/admin/login` mostra X-Content-Type-Options,
  X-Frame-Options, Strict-Transport-Security.
- Editar uma piscina/registo gera linha em `activity_log` e aparece na pagina do plugin.
