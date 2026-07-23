# Login (`app/Filament/Pages/Auth/Login.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Nota: não existe `EditProfile.php` neste projeto (só `Login.php`) — se precisares de editar perfil, confirma onde isso vive antes de assumir que não existe.

## Propósito
Sobrepõe o `Filament\Pages\Auth\Login` para suportar login **por PIN** além de password, com rate-limiting reforçado contra brute-force.

## Lógica de negócio não óbvia
- Deteção automática de tentativa de PIN: senha só com dígitos, comprimento 4–6 (não há campo separado — é o mesmo campo password).
- Rate-limit do PIN usa `request()->server('REMOTE_ADDR')`, **não** `request()->ip()` — decisão de segurança deliberada e comentada no código: como `trustProxies(at: '*')` está ativo (necessário para HTTPS atrás do proxy Railway), `->ip()` confiaria em `X-Forwarded-For`, forjável por um atacante para gerar uma chave de rate-limit diferente a cada pedido e assim contornar o bloqueio.
- Rate limit de PIN: 5 tentativas, bloqueio de 60s por falha (cumulativo). Rate limit geral (password) é o padrão do Filament (`rateLimit(5)`), independente do de PIN.
- Falha de PIN regista `Log::warning` e, se o Sentry SDK estiver instalado, `\Sentry\captureMessage`.
- `remember` (lembrar-me) tem **default `false`** — login persistente desativado por omissão (prática segura para app administrativa/regulamentar).

## Coisas resolvidas
- ✓ **`remember` default alterado para `false`**: mais seguro para app administrativa, requer opt-in explícito para login persistente.
