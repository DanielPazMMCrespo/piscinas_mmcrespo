# Definições do Sistema (`app/Filament/Pages/DefinicoesSistema.php`)

Sem pasta própria. Contexto local; o `CLAUDE.md` da raiz tem a arquitetura geral. Só Admin acede.

## Propósito
Configuração global do sistema, guardada em `AppSetting` (key/value). 4 secções colapsáveis:
1. **Limites Regulamentares (CN 14/DA)** — pH min/max, cloro livre min/max, cloro combinado max, turbidez max, tolerância amarelo. Tem aviso legal inline sobre o impacto de alterar.
2. **Tempos e Prazos** — validade de leitura da sonda, timeout, validade de convite, aviso de torneira aberta, horários do resumo de conformidade (máx. 4).
3. **Automação Operacional** — fator de compensação de dosagem, horários de resumo de turno (máx. 4), registos mínimos para tendência, descida de ORP que confirma a tendência do cloro livre (`tendencia_orp_delta_mv`), violações mínimas para auto-incidente.
4. **Templates de Email** — assunto/corpo do email de convite.

## Lógica não óbvia
- `mount()` carrega todos os `AppSetting` e preenche o form sem defaults explícitos em PHP — os "Padrão original" só existem como `helperText`; se a setting nunca foi definida, o campo fica vazio/null.
- `save()`: faz upsert manual por cada campo (`update`/`create` de key/value/group/label/type), **incluindo campos não preenchidos** (`$val = $value ?? ''`) — grava strings vazias para settings nunca definidas. Depois chama `SettingsService::flush()` e invalida caches do dashboard.

## `SettingsService` / `AppSetting`
- Cache estática por classe + cache Laravel (`app_settings_all`, 15min). `flush()` limpa ambos.
- Erros de DB (tabela inexistente) são engolidos silenciosamente (devolve array vazio) — útil em migrações/seeding, mas pode mascarar erros reais.
- `AppSetting::booted()` invalida `app_settings_all` em `saved`/`deleted` — dupla garantia com o `flush()` manual da página.

## Coisas a rever
- ~~Bug confirmado (linha 121): `Forms\Components\Select::make(...)` sem import~~ — **corrigido**: agora usa `\Filament\Forms\Components\Select::make(...)` (caminho totalmente qualificado, igual à linha 160). Imports não usados de `KeyValue`/`TagsInput` também removidos.
- `tanques_verificaveis` da Installation e `ordem_bombas`/`ordem_filtros` da Pool não são configuráveis aqui nem noutro sítio óbvio da UI (ver `InstallationResource/CLAUDE.md` e `PoolResource/CLAUDE.md`).
