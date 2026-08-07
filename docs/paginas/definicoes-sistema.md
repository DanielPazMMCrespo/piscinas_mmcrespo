# Definições do Sistema (`app/Filament/Pages/DefinicoesSistema.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Só Admin acede.

## Propósito
Configuração global do sistema, guardada em `AppSetting` (key/value). 4 secções colapsáveis:
1. **Limites Regulamentares (CN 14/DA)** — pH min/max, cloro livre min/max, cloro combinado max, turbidez max, tolerância amarelo. Tem aviso legal inline sobre o impacto de alterar.
2. **Tempos e Prazos** — validade de leitura da sonda, timeout, validade de convite, aviso de torneira aberta, horários do resumo de conformidade (máx. 4).
3. **Automação Operacional** — fator de compensação de dosagem, horários de resumo de turno (máx. 4), registos mínimos para tendência, violações mínimas para auto-incidente.
4. **Janela de Silêncio** — período em que a aplicação não envia push nem e-mail (padrão 22:00→08:00 e o domingo inteiro). Ver `docs/paginas/notificacoes.md` para o que corta, o que fica de fora e porque é que os avisos de episódio único têm de verificar a janela antes de se marcarem como enviados.
5. **Templates de Email** — assunto/corpo do email de convite.

## Lógica não óbvia
- `mount()` faz `form->fill()` com um array, e nesse caminho o Filament **não** aplica os `->default()` dos componentes: os campos da janela de silêncio são pré-preenchidos à mão no `mount()` com os mesmos padrões de `JanelaSilencio`, senão apareciam desligados até alguém gravar.
- `mount()` carrega todos os `AppSetting` e preenche o form sem defaults explícitos em PHP — os "Padrão original" só existem como `helperText`; se a setting nunca foi definida, o campo fica vazio/null.
- `save()`: faz upsert manual por cada campo (`update`/`create` de key/value/group/label/type), **incluindo campos não preenchidos** (`$val = $value ?? ''`) — grava strings vazias para settings nunca definidas. Depois chama `SettingsService::flush()` e invalida caches do dashboard.

## `SettingsService` / `AppSetting`
- Cache estática por classe + cache Laravel (`app_settings_all`, 15min). `flush()` limpa ambos.
- Erros de DB (tabela inexistente) são engolidos silenciosamente (devolve array vazio) — útil em migrações/seeding, mas pode mascarar erros reais.
- `AppSetting::booted()` invalida `app_settings_all` em `saved`/`deleted` — dupla garantia com o `flush()` manual da página.

## Coisas a rever
- ~~Bug confirmado (linha 121): `Forms\Components\Select::make(...)` sem import~~ — **corrigido**: agora usa `\Filament\Forms\Components\Select::make(...)` (caminho totalmente qualificado, igual à linha 160). Imports não usados de `KeyValue`/`TagsInput` também removidos.
- `tanques_verificaveis` da Installation e `ordem_bombas`/`ordem_filtros` da Pool não são configuráveis aqui nem noutro sítio óbvio da UI (ver `InstallationResource/CLAUDE.md` e `PoolResource/CLAUDE.md`).
