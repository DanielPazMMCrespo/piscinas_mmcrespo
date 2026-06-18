# Configuração do Sentry — Monitoring e Logging em Produção

## Overview

O Sentry é um serviço de monitoramento de erros e performance em tempo real. Esta configuração captura:

- **Exceções não tratadas** em toda a aplicação
- **Warnings e errors** de log
- **Performance tracking** (slow requests > 1s)
- **Release tracking** (git commit hash para cada versão)
- **Breadcrumbs** para contexto (logs, SQL queries, cache, requisições HTTP)

## Setup Inicial

### 1. Instalar o pacote Sentry

```bash
composer install
```

O ficheiro `composer.json` já inclui `sentry/sentry-laravel: ^4.0`.

### 2. Obter o DSN do Sentry

1. Aceder a [https://sentry.io](https://sentry.io)
2. Criar uma conta (ou fazer login se já existe)
3. Criar um novo projeto com platform "Laravel"
4. Copiar o **DSN** (algo como: `https://<key>@o<org>.ingest.sentry.io/<project>`)

### 3. Configurar variáveis de ambiente

Adicionar ao ficheiro `.env` (ou `.env.production`):

```env
SENTRY_LARAVEL_DSN=https://<key>@o<org>.ingest.sentry.io/<project>
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_CLIENT_REPORTS=true
```

**Nota:** 
- `SENTRY_LARAVEL_DSN` vazio desativa completamente o Sentry (seguro em development)
- `TRACES_SAMPLE_RATE=0.1` = 10% das transações (performance tracking)
- `PROFILES_SAMPLE_RATE=0.1` = 10% das transações com profiling (requere CPU/memory)

## Ficheiros Criados

- **`config/sentry.php`** — Configuração centralizada (DSN, environment, release, sample rates, breadcrumbs)
- **`app/Providers/SentryServiceProvider.php`** — Integração com Laravel (handlers de exception/error/fatal)
- **`bootstrap/providers.php`** — Registo do provider

## O Que É Capturado Automaticamente

### Exceções
Todas as `\Exception` não tratadas são automaticamente enviadas para o Sentry com:
- Stack trace completo
- Valores locais (variáveis em scope)
- Request data (URL, método, headers, POST data)
- User info (se autenticado)
- Tags de environment, release, etc.

### Logs (Laravel Log)
Mensagens de nível `warning`, `error`, `critical` são capturadas automaticamente.

```php
Log::warning('Stock insuficiente', ['product_id' => 123]);
// Automaticamente aparece em Sentry
```

### Performance (Transactions)
Cada requisição HTTP é uma "transaction":
- URL, método, status code
- Tempo total
- Operações de DB, cache, etc. aparecem como "spans"
- Slow requests (> 1s por defeito) são destacadas

Configurável via `traces_sample_rate` (0.1 = 10% amostradas).

### Breadcrumbs
Histórico de eventos relevantes que precedem o erro:

- **SQL queries** — `SELECT * FROM users WHERE...`
- **Log entries** — `Log::info('User logged in')`
- **Cache operations** — `Cache::get('key')`
- **HTTP requests** — `Guzzle requests`
- **Queue jobs** — `Job queued/processed`

## Release Tracking

O `config/sentry.php` extrai automaticamente o commit hash:

```php
'release' => trim(exec('git rev-parse --short HEAD')),
```

Assim, cada erro é associado à versão exata do código. Útil para:
- Saber se um bug já foi fixado
- Correlated releases com erros resolvidos
- Source map linking (se houver minification)

## Exemplo de Uso

### Capturar erro com contexto adicional

```php
try {
    $this->descontarStock($product, $qty);
} catch (\Exception $e) {
    Log::error('Stock debit failed', [
        'product_id' => $product->id,
        'quantity' => $qty,
        'exception' => $e->getMessage(),
    ]);
    // Sentry captura automaticamente
    throw $e;
}
```

### Adicionar tags customizadas (opcional)

```php
\Sentry\captureException($e, [
    'tags' => [
        'pool' => 'Leiria',
        'operation' => 'daily_record_create',
    ],
]);
```

### Definir user context

Automaticamente preenchido se `Auth::check()`, mas pode ser customizado:

```php
\Sentry\setUser([
    'id' => auth()->id(),
    'email' => auth()->user()->email,
    'username' => auth()->user()->name,
]);
```

## Ambientes

- **development** — Sentry pode estar desativado (DSN vazio)
- **staging** — DSN ativo, sample rates altos (1.0 = 100%)
- **production** — DSN ativo, sample rates moderados (0.1-0.5)

O environment é determinado por `APP_ENV` no `.env`.

## Monitoramento na Dashboard Sentry

1. **Issues** — Erros agrupados (mesma stack trace ou contexto similar)
2. **Release Health** — Taxa de erro por versão
3. **Performance** — Transações lentas, operações de DB, etc.
4. **Breadcrumbs** — Contexto antes do erro
5. **Sourcemaps** — Linking código original (se aplicável)

## Troubleshooting

### Sentry não está capturando erros

1. Verificar se `SENTRY_LARAVEL_DSN` está definido (não vazio)
2. Testar com: `php artisan tinker` → `throw new \Exception('test');`
3. Verificar se o ambiente é `production` (development pode estar filtrado)

### Performance tracking não está ativo

Verificar se `SENTRY_TRACES_SAMPLE_RATE > 0`.

### Muitos erros falsos

Aumentar `SENTRY_TRACES_SAMPLE_RATE` para > 0.5, ou ajustar filtering na Sentry web.

## Referências

- [Sentry Laravel Docs](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Release Tracking](https://docs.sentry.io/product/releases/)
- [Performance Monitoring](https://docs.sentry.io/product/performance/)
