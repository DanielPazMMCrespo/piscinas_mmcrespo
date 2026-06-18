# Teste do Sentry — Validação Pós-Deploy

Depois de fazer deploy com `SENTRY_LARAVEL_DSN` configurado, verificar se o Sentry está capturando erros.

## Teste 1 — Exceção Direta

```bash
php artisan tinker
```

```php
throw new \Exception('Test exception from Sentry');
```

Resultado esperado: Erro aparece em [https://sentry.io](https://sentry.io) em "Issues" em segundos.

## Teste 2 — Via Log

```php
Log::error('Test error message', ['context' => 'sentry_test']);
```

Resultado esperado: Aparece como breadcrumb ou evento em Sentry.

## Teste 3 — Em Request HTTP

Criar uma rota temporary para teste:

```php
// routes/web.php (only in development)
Route::get('/sentry-test', function () {
    throw new \Exception('Sentry test from HTTP route');
});
```

```bash
curl http://localhost:8000/sentry-test
```

## Teste 4 — Performance Tracking

Fazer um request lento (> 1s):

```php
Route::get('/sentry-slow', function () {
    sleep(2);
    return 'OK';
});
```

```bash
curl http://localhost:8000/sentry-slow
```

Resultado esperado: Transaction aparece em Sentry > Performance > "sentry-slow" com duração > 1s.

## Verificação em Sentry Dashboard

1. Aceder a [https://sentry.io](https://sentry.io)
2. Escolher projeto "Piscinas MMCrespo" (Laravel)
3. Abrir aba "Issues"
4. Procurar o teste ('Test exception', 'Test error message', etc.)
5. Clicar para ver stack trace, breadcrumbs, environment, release, etc.

## Checklist de Validação

- [ ] Erro aparece em Issues
- [ ] Release é o git commit hash correto
- [ ] Environment é "production" (ou o que está em APP_ENV)
- [ ] Breadcrumbs mostram contexto (SQL, logs, etc.)
- [ ] Slow requests aparecem em Performance
- [ ] User info aparece (se autenticado)
- [ ] Tags customizadas funcionam (se adicionadas)

## Troubleshooting

Se não aparecer nada em Sentry:

1. **DSN está vazio?** — Nada é enviado
2. **APP_ENV=development?** — Pode estar filtrado
3. **Firewall?** — Bloqueia https://o<org>.ingest.sentry.io
4. **Rate limiting?** — Muitos erros muito rápido (backoff automático)

Verificar logs Laravel:

```bash
tail -f storage/logs/laravel.log
```

Procurar por "Sentry" ou "exception".
