# Onboarding — Piscinas MMCrespo

> Contexto de entrada para quem pega no projeto pela primeira vez.
> Última atualização: 2026-09-08 (`origin/main` = `origin/test` = `f912727`).
> O mapa detalhado e o histórico de sessões estão no [`CLAUDE.md`](../CLAUDE.md) da raiz.

---

## 1. Nome

Piscinas MMCrespo.

## 2. Linguagem

PHP 8.2+ (`composer.json` exige `^8.2`; o dev local corre 8.5.6 NTS em `C:\php\php.exe`).
JavaScript no browser. Blade para o HTML. Sem SPA.

## 3. Utilizadores principais

Quatro papéis, em `App\Constants\UserRole`:

| Papel | Uso real |
|---|---|
| `tecnico` | Usa a app todos os dias, no telemóvel, à beira da piscina. É o utilizador que conta. |
| `nadador_salvador` | Só vê as piscinas atribuídas e um subconjunto do formulário. Permissões finas em `App\Constants\NSPermission`. |
| `gestor` | Leitura e relatórios. Páginas ligáveis/desligáveis uma a uma (`App\Constants\PaginaGestor`). |
| `admin` | Tudo. |

Existe também `inativo`, que é o bloqueio de conta.

## 4. Propósito

Cumprir a lei sem papel.

As piscinas municipais são obrigadas a manter um **livro de registo sanitário**
(CN 14/DA da DGS 2009, NP 4542:2017, DR 5/97). Era feito à mão, em folhas.
Esta app substitui essas folhas e produz o PDF oficial que a DGS pede numa auditoria.

## 5. Problemas que resolve

- Registo diário de cloro, pH, temperatura e turbidez sem papel.
- Dizer **no momento** se um valor está fora do limite legal (semáforo em tempo real).
- Nunca perder o registo original de uma correção (padrão *append-only*).
- Juntar as leituras da sonda automática (Hanna) com as leituras feitas à mão.
- Saber quando uma leitura **não conta** (lavagem de filtro, bomba parada, sonda avariada).
- Controlar o stock de químicos em duas camadas: armazém central para instalação.
- Provar o trabalho da paragem técnica com fotos, vídeo e SHA-256.

## 6. Bibliotecas e frameworks

| Camada | Escolha |
|---|---|
| Framework | Laravel 12 LTS |
| Admin/UI | Filament 3.3 (o painel **é** a app) |
| Papéis | `spatie/laravel-permission` |
| Auditoria | `spatie/laravel-activitylog` + `rmsramos/activitylog` |
| PDF | `barryvdh/laravel-dompdf` |
| E-mail | `resend/resend-php` |
| Erros | `sentry/sentry-laravel` |
| Gráficos | Chart.js 4 (+ adapter luxon, annotation, zoom, hammerjs) |
| Fotos/vídeo | Cloudflare R2 via `league/flysystem-aws-s3-v3` |
| Notificações | `laravel-notification-channels/webpush` + DatabaseNotification |
| Testes | Pest 3 |
| Qualidade | Laravel Pint + larastan/phpstan |

Zero pedidos a CDN. Fontes (`@fontsource/inter`, `lato`, `montserrat`), GSAP e GLightbox
vêm todos do bundle Vite.

## 7. Equipa

Um programador. O `git log` mostra duas contas humanas (`Daniel Paz`,
`DanielPazMMCrespo`) e uma de agente (`Claude`). Não há divisão de responsabilidades
escrita em nenhum sítio do repositório.

## 8. Prazo

Não existe prazo no repositório. Primeiro commit 2026-05-28. Desenvolvimento contínuo.

## 9. Estrutura

Laravel normal. Quase tudo o que importa vive em dois sítios:

```
app/Filament/          Resources, Pages e Widgets - a app toda
app/Services/          as regras de negocio, uma por ficheiro
app/Constants/         papeis, permissoes, rotulos de auditoria
app/Support/           Auditoria (ponto unico de escrita no trilho)
database/migrations/   105 ficheiros
tests/                 121 ficheiros (90 Feature, 31 Unit)
docs/paginas/          documentacao das paginas standalone
```

Não há front-end público. A raiz `/` redireciona para `/admin`.

## 10. Arquitetura

**Regra de ouro: uma regra vive num sítio só.** Se duplicares, as duas cópias divergem
e o livro sanitário passa a mentir. Já aconteceu.

Serviços que são fonte única (não reimplementar):

| Serviço | Responde a |
|---|---|
| `SourceSelectionService` | "o valor atual vem da sonda ou do registo manual?" |
| `LeituraArtefactoService` | "esta leitura conta ou é artefacto?" |
| `PoolClosureService` | "esta piscina estava aberta neste dia?" |
| `AlertasService` | "está fora dos limites?" |
| `PlanoParagemService` | máquina de estados dos trabalhos de paragem |
| `DosageCalculatorService` | dose sugerida (devolve `null` em vez de inventar) |
| `App\Support\Auditoria` | escrita no trilho de auditoria |

Dois padrões de escrita, escolhidos por obrigação legal:

- **Append-only** em `DailyRecord`, `FilterCheck`, `Incident`. Uma edição cria linha nova
  com `e_correcao=true` + `corrige_registo_id`. Gráficos e relatórios filtram sempre com
  `whereDoesntHave('correcoes')`.
- **Auditado, não append-only** em `PoolClosureTask` (usa `LogsActivity`).

Autorização por Policy. Papéis por constante, nunca por string solta.

## 11. Principais desafios

Por gravidade, e todos abertos:

1. **A Paragem Técnica inteira não está documentada.** `PoolClosureTask`,
   `PlanoParagemService`, `PlanoParagemPdfService`, `TrabalhosRelationManager` (~700 linhas).
   Quem pegar nisto não tem mapa.
2. **O PWA `/m` não tem `auth` e não grava nada.** E o `start_url` do manifest aponta
   para lá — quem instalar a app cai no protótipo.
3. **`/api/pdf/export`** devolve texto falso com `Content-Type: application/pdf`, sem login.
4. **Dois geradores de livro sanitário** (`RelatorioPdf` e `DgsPdfReportService`).
   O segundo ignora correções e encerramentos.
5. **Nove worktrees ativas** neste repositório, várias com trabalho não commitado.
   Já houve uma reestruturação de 15 ficheiros fora do controlo de versões durante dias.
6. **Ficheiros-lixo de heredoc do PowerShell** aparecem na raiz quase todas as sessões,
   sempre com 0 bytes. Verificar `git status` antes de qualquer commit.

A lista completa de dívida está no `CLAUDE.md`, secção "Dívida técnica em aberto".

## 12. Etapa atual

Fim. A app está em produção e em uso real. Planos 1 a 7 fechados.
O que falta é dívida técnica, não features.

## 13. Testes

Pest 3, SQLite em memória. 121 ficheiros.

```bash
composer test                          # config:clear + toda a suite
php artisan test --filter=NomeDoTeste  # um teste
vendor/bin/pest tests/Feature/Foo.php  # um ficheiro
```

Três regras da casa, todas pagas com bugs em produção:

- **Um teste novo só conta depois de o vermos falhar sem a correção.** Um
  `assertFormFieldIsHidden` já passou por acaso em duas hipóteses diferentes.
- **Correr a suite toda**, nunca só o ficheiro novo. Memos `static` envenenam
  ficheiros seguintes.
- **Testes de browser fazem-se em staging**, com a sessão real do Chrome.
  Não se monta ambiente local para validar features.

## 14. Metodologia

Não há metodologia formal. O trabalho é feito por sessões, e cada sessão deixa um
resumo escrito no `CLAUDE.md` (vai na 26). É esse ficheiro que faz o papel de
histórico de decisões.

## 15. Responsável

Daniel Paz (daniel.paz@mmcrespo.pt). Único decisor.

## 16. Documentação

Toda em Markdown, dentro do repositório:

- **[`CLAUDE.md`](../CLAUDE.md)** na raiz — mapa geral, histórico das sessões,
  22 regras de código pagas com bugs reais. É o ficheiro mais importante do projeto.
- **`CLAUDE.md` por pasta de Resource** (15 ficheiros) — carrega automaticamente
  ao trabalhar nessa pasta.
- **`docs/paginas/*.md`** (9 ficheiros) — o equivalente para as páginas standalone.
- **Blocos `[AI_CONTEXT]`** no cabeçalho dos ficheiros críticos, com as regras
  estritas de modificação.

## 17. Funcionalidades principais

- **Registo Diário** com semáforo de conformidade e smart defaults.
- **Incidentes** com timeline de mensagens, fotos e escalação automática.
- **Ações Operacionais** (lavagem de filtro, torneira, bomba, avaria de sonda).
- **Encerramentos** de piscina + plano de paragem técnica com os 13 trabalhos legais.
- **Stock** em duas camadas, com `lockForUpdate()` em toda a movimentação.
- **Relatório PDF** — o livro sanitário oficial.
- **Análise de Parâmetros** com gráficos, heatmap e score de conformidade.
- **Sondas Hanna** sincronizadas a cada 15 min, com circuit breaker.
- **Notificações** por push, sino e e-mail.
- **Trilho de auditoria único**, com retenção de 730 dias.

## 18. Partes interessadas

- **MMCrespo** — a empresa que tem o contrato de manutenção.
- **Câmaras de Leiria, Maceira e Caranguejeira** — donas das piscinas.
- **DGS** — faz auditorias externas e pede o livro sanitário.
- **Equipa no terreno** — técnicos e nadadores-salvadores.

## 19. Monitorização

- **Sentry** para erros.
- **`/api/health`** diz se a base de dados e a cache respondem. Verificar antes de
  abrir o painel depois de um deploy.
- **Activitylog** guarda tudo durante 730 dias.
- **15 tarefas agendadas** em `routes/console.php`: sync da sonda, backup diário às
  03:00, regras de auto-incidente, resumos por turno, housekeeping de alertas.
- **`HannaCircuitBreaker`** — 5 falhas em 5 min abrem o circuito; em circuito aberto
  devolve a última leitura em cache.

Não há worker dedicado no Railway. A fila é drenada por um
`queue:work --stop-when-empty` que corre a cada minuto.

## 20. Cliente final

O técnico de manutenção, no telemóvel, à beira da piscina, com uma mão livre.
É por isso que o desenho é mobile-first e os alvos de toque têm 48 px.

---

## As piscinas

| Instalação | Piscina | Volume | Temp. normal |
|---|---|---|---|
| Leiria | Competição (25 x 17.4 m) | 900 m³ | 26-27 °C |
| Leiria | Lazer (25 x 17.4 m) | 600 m³ | 28-30 °C |
| Leiria | Infantil (17.4 x 5 m) | 50 m³ | 28-30 °C |
| Maceira | Maceira (16.6 x 10 m) | 170 m³ | 28-30 °C |
| Caranguejeira | Caranguejeira (16.6 x 10 m) | 170 m³ | 28-30 °C |

As cinco têm sonda Hanna BL132 instalada.

## Ambientes

| Ambiente | URL |
|---|---|
| Produção | https://piscinasmmcrespo.up.railway.app |
| Staging | https://piscinasmmcrespo-testes.up.railway.app |

## Primeiros passos

```bash
composer install && npm install
php artisan migrate --seed
composer run dev
```

## Antes de tocar em código

1. `git status` **e** `git worktree list`. O trabalho pode estar noutra worktree.
2. Ler o `CLAUDE.md` da raiz, sobretudo "Regras de Código & Decisões Técnicas".
3. Ler o `CLAUDE.md` local da pasta onde vais trabalhar.
4. **Nunca `git add -A`.** Usar `git commit --only <lista>`; o index é partilhado
   com outras sessões no mesmo repositório.
5. O `main` local está preso numa worktree e desatualizado. O bom é o `origin/main`.
