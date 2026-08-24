# Plano de Trabalhos de Paragem Técnica

## Contexto

O vereador da câmara pediu, por escrito:

1. O **plano de trabalho** da paragem das piscinas (documento prévio, nunca entregue).
2. Prova de que os **tanques foram limpos**.
3. Datas da **supercloração**, da **reposição de cloro** e do **arranque do aquecimento**.
4. Um **relatório** final.
5. Prova de **desinfeção para Legionella** conforme caderno de encargos.

A app não tem nada disto. Grep no repositório: zero ocorrências de `legionella`,
`superclora`, `aquecimento`, `limpeza_tanque`, `esvazi`. A única peça próxima é o tipo
`tratamento_choque` em `OperationalAction`, com dois campos de texto livre.

A paragem **está a decorrer agora**. O encerramento **já está registado** como `PoolClosure`.
Nenhum trabalho foi registado.

**Resultado pretendido:** duas folhas em PDF geradas da mesma lista fechada de obrigações —
**Plano** (o que vai ser feito, quando) e **Relatório** (o que foi feito, quando, por quem,
com valores medidos e provas). Mais uma camada de evidência a partir da sonda, para a paragem
que já decorre.

### O caderno de encargos (Anexo A) — lido, e muda coisas

Documentos do cliente em `docs/caderno-encargos/` (fora do git, ver `.gitignore`).
O que decide este plano está no **Anexo A — "Plano de manutenção de equipamentos e
tratamento de águas das Piscinas Municipais de Leiria"**, 7 páginas.

**Obrigações com periodicidade fixa, citadas à letra:**

| Obrigação | Periodicidade |
|---|---|
| "Limpeza e desinfecção semestral dos **tanques de compensação** de todas as instalações (**Dezembro e Agosto**) e sempre que seja necessário para correção de valores bacteriológicos" | Semestral, meses fixos |
| "Lavagem dos filtros de areia conforme plano predefinido com um **mínimo de 3 vezes por semana**" | Semanal |
| "Transbordo de superfície das piscinas (…) **mínimo de 2 vezes por semana**" | Semanal |
| "Controlo Analítico Laboratorial físico-químico e bacteriológico **quinzenal** (…) em Laboratório Acreditado (2 Análises por mês por piscina)" | Quinzenal |
| "controle analítico **Semestral** de Legionella em Laboratório Acreditado" sobre **água quente sanitária** | Semestral |
| "Tratamento de choque conforme necessidades e ocorrências" | Sob evento |
| "Relatório Mensal da operação e manutenção" | Mensal |

**A paragem de Agosto é a obrigação contratual da limpeza dos tanques de compensação.**
É esta a resposta directa a "os tanques foram limpos?".

**As datas não são nossas.** O contrato diz: "Ligar/desligar equipamentos em função do regime
de funcionamento das piscinas e **períodos de paragem definidos pela CM Leiria**". Logo
`previsto_para` **tem de ser editável por linha** — não pode ser `inicio + offset` calculado.
Era esta a condição que decidiu a arquitectura contra a abordagem minimalista.

**Legionella — âmbito reduzido por decisão explícita do utilizador.**
O contrato exige plano de prevenção anti-legionella e controlo semestral acreditado sobre
**AQS (água quente sanitária)**, com pontos de colheita fixos por instalação:

| Instalação | Pontos AQS | Análises/ano |
|---|---|---|
| Complexo de Leiria | 5 | 10 |
| Maceira | 4 | 8 |
| Caranguejeira | 4 | 8 |

O utilizador foi avisado e **decidiu manter o âmbito "só a piscina"**. Consequência assumida:
a Legionella fica **uma linha da checklist com anexo do boletim**, sem os pontos de colheita
nem a periodicidade semestral. Há um documento inteiro de AQS (anexo A7) ainda **não lido** —
é por aí que se alarga o âmbito se o vereador pedir esse detalhe.

**Discrepância de equipamento, resolvida.** O Anexo A especifica, para as 5 piscinas,
"Controlador ProMinent – Dulcomarin II. Sonda de leitura de: **Cloro**, PH, Temperatura,
Potencial Redox, sensor de fluxo". O utilizador confirmou que os controladores **foram trocados
para Hanna BL132**, que não reporta cloro. O caderno está desactualizado neste ponto.
Confirmado no feed real: `raw_parameters` traz `ph`, `orp`, `temp`, `airTemp`, `acidBase`, `cl`
— onde `cl` é o débito da bomba doseadora (`caudal_cloro`), não uma concentração.

Enquadramento legal: CN 14/DA (DGS 2009, em `docs/caderno-encargos/`), Lei n.º 52/2018 e
Despacho n.º 1547/2022 (Legionella), DR 5/97.

**Obrigações contratuais que a app não registra hoje** — achados fora do âmbito deste plano,
a abrir em separado: análises quinzenais de laboratório acreditado com a lista de parâmetros
do contrato (turvação, cloretos, condutividade, oxidabilidade, coliformes, E. coli, enterococos
fecais, staphylococcus, pseudomonas aeruginosa); leitura dos contadores de **gás e
electricidade** a par do de água ("ficheiro excel articulado"); registo da formação anual de
2 a 4 horas à equipa.



## Decisões fechadas com o utilizador

| Item | Decisão |
|---|---|
| Âmbito | Só a piscina: tanque, tanque de compensação, filtros, circuito, supercloração, reposição de cloro, arranque de aquecimento |
| Legionella | **Um item da lista**, com zonas abrangidas e anexo do boletim de laboratório. Não se constrói módulo de águas quentes sanitárias nem AVAC |
| Anexos | Fotos **e** boletins PDF **e** valores medidos |
| `RelatorioPdf` | Não fica mais complexa. A única alteração é **remover** 26 linhas duplicadas |
| Prazo | Esta semana, sem pressão |

## Decisão de arquitetura: tabela dedicada

Três planos concorrentes foram postos a competir. Ganha o **modelo dedicado**, e a razão é
factual, não estética:

- `OperationalAction` **não tem estado `previsto`**. Regista o que aconteceu. O entregável (a),
  o *plano*, é inexprimível — não se imprime "estes 13 trabalhos vão ser feitos" a partir de
  uma tabela de coisas já feitas.
- **Não tem lista fechada.** A prova pedida é "nada ficou por fazer", o que exige enumerar as
  obrigações **incluindo as sem registo**. Numa query sobre `operational_actions`, a ausência
  de linha é indistinguível da ausência de obrigação.
- **Não tem `obrigatorio`**, logo não há afirmação defensável de "0 em falta".
- `foto` é **uma** string. Boletim PDF + fotos não cabem sem alterar schema de qualquer forma.

O plano minimalista nomeou 5 condições sob as quais deixaria de se recomendar. Três já se
verificam: faz falta estado além de feito/não-feito, faz falta verificação por terceiro, e a
lista muda quando o caderno de encargos chegar.

### O que se aceita do plano minimalista

- Template da lista **em código**, não em BD (as duas análises convergiram nisto).
- Junção pelo **lado da leitura**, zero acoplamento na escrita.
- Segunda janela de artefacto para **tanque vazio**, não só supercloração.
- Deteção **propõe**, nunca escreve.

## Descobertas do código que condicionam o desenho

### Schema real de `sensor_readings` (verificado)

`ph`, `orp` (**mV**), `temperatura_agua`, `temperatura_ar`, `caudal_ph`, `caudal_cloro`
(mL/h de dosagem), `raw_parameters`, `lida_em`. Índices: `['pool_id','lida_em']`, único
`['hanna_device_id','lida_em']`.

**Não existe coluna de cloro em mg/L.** O docblock da migração di-lo: ORP é proxy do poder
desinfetante, não é cloro livre. O relatório **nunca** pode imprimir ppm vindo da sonda.

| Evento | Sinal | Confiança |
|---|---|---|
| Supercloração | Pico de `orp` sustentado + `caudal_cloro` elevado | Média — proxy |
| Reposição de cloro | `orp` volta à banda `orp_min`–`orp_max` e estabiliza | Média |
| Arranque de aquecimento | `temperatura_agua` sobe de forma sustentada | **Alta** |
| Tanque vazio | Lacuna de leituras sem `SensorOutage` que a explique | Média |

Notas: os casts `decimal` devolvem **strings** (`RelatorioPdf::cumpresRegraLavagemFiltro()` já
faz `(float)` à mão). `temperatura_ar` está na mesma linha e ninguém a usa — é o discriminador
grátis entre "ligaram a caldeira" e "onda de calor". `HannaCloudService::parseHistoryEntry()`
devolve uma flag `no_flow` que **nunca é persistida**.

### Risco confirmado: supercloração gera não-conformidades falsas

`docs/paginas/encerramentos.md:23` — com `agua_em_tratamento` ligado (o regime de manutenção),
os alertas de violação legal **mantêm-se ativos**. Pior: as heurísticas
`WaterQualityThresholds::ANOMALY_ORP_MAX` e `FILTER_WASH_*` em
`RelatorioPdf::construirSeccoes()` (linhas 723-772) vão atribuir esses dias a
**"Lavagem de filtro"** — uma afirmação falsa num documento legal.

### Restrições duras do dompdf (verificadas)

- **`enable_remote` é `false`** e o projeto não publicou `config/dompdf.php`. O bucket R2 é
  privado com URLs assinados desde `278a76f`. O dompdf **não consegue buscar anexo por URL**.
  **Não ligar `enable_remote`** — abre SSRF a partir do servidor, contra todo o trabalho de CSP
  das sessões 17 e 23.
- **Fotos:** ler com `Storage::disk(DailyRecord::getStorageDisk())->get($path)` em PHP e
  embeber como `data:image/...;base64`. No serviço, **nunca na blade** — o dompdf re-lê a blade
  a cada passagem de render.
- **HEIC está nos tipos aceites** (`OperationalActionResource.php:478`) e o dompdf **não o
  renderiza**. Detetar por mime e cair para linha de referência.
- **Um PDF não entra dentro de outro.** `setasign/fpdi` livre não importa acima de PDF 1.4 e os
  boletins vêm em 1.7. Solução: índice de anexos com laboratório, datas, resultado e hash
  SHA-256; o boletim segue em separado.

### Armadilha de UI

`HistoricoEncerramentosWidget::table()` chama `PoolClosureResource::table($table)` e a seguir
**substitui** `->actions([...])`. Uma action acrescentada só no Resource **não** aparece no
rodapé de Encerramentos. E o "Editar" desse widget é visível só a ADMIN|GESTOR — o "Plano"
tem de incluir TECNICO, que é quem executa.

---

# Implementação

## 1. Migração e modelo

**`database/migrations/2026_08_24_000001_create_pool_closure_tasks_table.php`**
Idempotente com `if (Schema::hasTable('pool_closure_tasks')) return;`, igual a
`2026_08_03_000001_create_pool_closures_table.php`.

| Coluna | Tipo | Porquê |
|---|---|---|
| `pool_closure_id` | `foreignId->constrained('pool_closures')->cascadeOnDelete()` | O trabalho não existe fora da paragem |
| `tipo` | `string(40)` | Chave da constante |
| `ordem` | `unsignedSmallInteger` | A ordem é a do caderno de encargos, não a do `id` |
| `obrigatorio` | `boolean default true` | Sem isto o rodapé não pode afirmar "0 obrigações em falta" |
| `estado` | `string(20) default 'previsto'` | Não é derivável — ver abaixo |
| `origem` | `string(20) default 'declarada'` | Proveniência: `declarada` / `reconstruida` / `inferida` |
| `previsto_para` | `date nullable` | É isto que faz do plano um plano |
| `executado_em` | `timestamp nullable` | Precisa de **hora**: supercloração às 22:00 + reposição às 08:00 é a prova do tempo de contacto |
| `executado_por` | `foreignId nullable->constrained('users')->nullOnDelete()` | Linha de assinatura. `nullOnDelete` — apagar um utilizador nunca apaga prova legal |
| `motivo_nao_execucao` | `text nullable` | Obrigatório quando `estado` é `nao_executado` ou `nao_aplicavel` |
| `dados` | `json nullable` (cast `array`) | Valores medidos diferem por tipo e a lista é provisória. Acrescentar um campo não pode exigir migração |
| `fotos` | `json nullable` (cast `array`) | Paths no disco. Igual a `incidents.fotos` |
| `documentos` | `json nullable` (cast `array`) | **Separado das fotos**: o dompdf imprime imagens mas não embebe PDFs, e "item obrigatório sem documento" passa a regra verificável |
| `observacoes` | `text nullable` | |

Índice: apenas `index(['pool_closure_id', 'ordem'])`. É *a* query. Volume real: 5 piscinas ×
~2 paragens/ano × ~13 itens ≈ **130 linhas/ano**. Mais índices seriam ruído.

**Não** pôr `unique(['pool_closure_id','tipo'])` — uma paragem pode ter legitimamente duas
superclorações (a primeira falhou o teste). A proteção contra duplicação vive no serviço.
**Não** denormalizar `pool_id` — seria segunda fonte para "que piscina é esta".
**Não** acrescentar `atribuido_a` em v1 — se a equipa não atribuir, fica coluna eternamente
nula. É migração de uma linha quando for preciso.

### Os cinco estados, e a proveniência à parte

Estado e proveniência são **eixos diferentes**. Misturá-los foi o erro que os dois planos
concorrentes cometeram em direções opostas.

`estado`: `previsto` · `em_curso` · `executado` · `nao_executado` · `nao_aplicavel`
`origem`: `declarada` (registo humano) · `reconstruida` (humano confirmou proposta da sonda) ·
`inferida` (só sonda, sem confirmação)

Binário obriga a mentir no meio. Um documento para a câmara precisa de dizer "não aplicável —
esta piscina não tem tanque de compensação" com razão, e isso não é nem feito nem por fazer.

**Invariante**, com teste próprio:
`estado === EXECUTADO ⟺ executado_em !== null && executado_por !== null`
`estado IN [nao_executado, nao_aplicavel] ⟹ filled(motivo_nao_execucao)`

O serviço é o **único escritor** de `estado`. O RelationManager nunca faz `->update()` direto.

**`app/Models/PoolClosureTask.php`** — `final class`, `declare(strict_types=1)`,
`HasFactory` + `LogsActivity` (marcar uma obrigação legal como cumprida **tem** de estar no
trilho de auditoria).

```php
public function encerramento(): BelongsTo;   // PoolClosure
public function executadoPor(): BelongsTo;   // User
public function tipoLabel(): string;
public function dadosFormatados(): string;   // FONTE ÚNICA JSON -> texto
public function scopeObrigatoriosEmFalta(Builder $q): Builder;
```

`boot()`: `saved`/`deleted` chamam `invalidateGraphCache($this->encerramento->pool_id)` +
`invalidateAllAlerts()`. **Não** `invalidateClosures()` — o mapa de encerramentos não muda.
Necessário porque uma supercloração executada altera janelas de artefacto (secção 6).

**Não é append-only.** Um item de checklist é máquina de estados por natureza; o histórico
vive no activitylog. Documentar isto no cabeçalho para o próximo leitor não hesitar.

Em `PoolClosure`: `public function trabalhos(): HasMany` →
`hasMany(PoolClosureTask::class)->orderBy('ordem')`.

## 2. Constantes e template

**`app/Constants/TrabalhoParagem.php`** — forma idêntica a `MotivoEncerramento` (`final class`,
construtor privado, `all()`, `isValid()`, `labels()`, `label()`), mais `obrigatorios(): array`
e `template(): array`.

Lista ancorada no Anexo A. Cada item obrigatório cita a cláusula que o exige.

| # | const | Label | Obrig. | Base no contrato |
|---|---|---|---|---|
| 1 | `ESVAZIAMENTO_TANQUE` | Esvaziamento / drenagem do tanque | não | pré-requisito da limpeza |
| 2 | `LIMPEZA_TANQUE_COMPENSACAO` | Limpeza e desinfeção do tanque de compensação | **sim** | "limpeza e desinfecção semestral dos tanques de compensação (Dezembro e Agosto)" |
| 3 | `LIMPEZA_TANQUE` | Limpeza e desinfeção do tanque da piscina | **sim** | "sempre que seja necessário para correção de valores bacteriológicos" |
| 4 | `LAVAGEM_FILTROS` | Lavagem dos filtros de areia | **sim** | "mínimo de 3 vezes por semana" |
| 5 | `MANUTENCAO_FILTROS` | Manutenção / substituição de massa filtrante | não | manutenção de equipamento |
| 6 | `TRATAMENTO_CHOQUE` | Tratamento de choque / supercloração | **sim** | "tratamento de choque conforme necessidades e ocorrências" |
| 7 | `DESINFECAO_LEGIONELLA` | Desinfeção e controlo de Legionella | **sim** | "controle analítico Semestral de Legionella em Laboratório Acreditado" (âmbito reduzido — ver acima) |
| 8 | `ENCHIMENTO_TANQUE` | Enchimento do tanque | não | pré-requisito do arranque |
| 9 | `REPOSICAO_CLORO` | Reposição dos níveis de cloro e pH | **sim** | "ajuste das dosagens de tratamento face aos resultados das análises" |
| 10 | `CALIBRACAO_SONDAS` | Calibração de sondas e set points do controlador | **sim** | "calibração de sondas e definição dos set point dos controladores" |
| 11 | `ARRANQUE_EQUIPAMENTOS` | Arranque de equipamentos e aquecimento | não | "ligar/desligar equipamentos (…) períodos de paragem definidos pela CM Leiria" |
| 12 | `ANALISE_ACREDITADA` | Análise laboratorial acreditada pré-reabertura | **sim** | "controlo analítico laboratorial físico-químico e bacteriológico quinzenal (…) Laboratório Acreditado" |
| 13 | `VERIFICACAO_PARAMETROS` | Verificação de parâmetros pré-reabertura | **sim** | CN 14/DA |
| 14 | `OUTRO` | Outro trabalho | não | escape hatch |

`ANALISE_ACREDITADA` e `DESINFECAO_LEGIONELLA` exigem `documentos` — o boletim do laboratório
acreditado é a única prova aceitável. `LIMPEZA_TANQUE_COMPENSACAO` é o item que responde à
pergunta do vereador sobre os tanques.

**O template vive em código, não em BD:**

1. **É contrato, não configuração.** Um CRUD do template permite apagar a linha "Legionella"
   e emitir um relatório a dizer "tudo executado". É exatamente o que um auditor procura.
   Em código, remover uma obrigação exige um commit revisível.
2. **O snapshot sai de graça.** O template só é lido ao criar o plano; as linhas ficam
   gravadas. Alterar a constante depois não mexe em planos já criados — que é o comportamento
   legalmente correto. Com template em BD, editar a lista ou parte planos antigos ou obriga a
   inventar versionamento.
3. **"Fácil de corrigir" favorece o código.** Editar um array e dar push é um commit. Em BD
   exige migração + Resource + seeder + policy. Quatro ficheiros para ganhar zero.
4. **A escape hatch já existe:** as linhas são registos normais. Acrescentar um trabalho fora
   do template é a ação "Acrescentar trabalho". A lista fechada é o chão, não o teto.

Contra honesto: mudar um label exige deploy. Aceitável — a app faz deploy a cada push.

## 3. Serviços

**`app/Services/PlanoParagemService.php`** — único escritor de estado.

```php
/** @throws \DomainException Se a paragem já tiver plano. */
public function criarPlano(PoolClosure $e, User $u): Collection;
public function acrescentarTrabalho(PoolClosure $e, array $dados, User $u): PoolClosureTask;
/** @throws \DomainException Boletim em falta num item que o exige. */
public function marcarExecutado(PoolClosureTask $t, array $dados, User $u): PoolClosureTask;
/** @throws \DomainException Justificação vazia. */
public function marcarNaoExecutado(PoolClosureTask $t, string $motivo, User $u): PoolClosureTask;
public function marcarNaoAplicavel(PoolClosureTask $t, string $justificacao, User $u): PoolClosureTask;
/** @return array{total:int, executados:int, obrigatorios_em_falta:int, pendentes:Collection} */
public function resumo(PoolClosure $e): array;
/** Anexo A — ver secção 5. @return Collection<int, OperationalAction> */
public function evidenciaOperacional(PoolClosure $e): Collection;
```

`criarPlano()` em `DB::transaction()`, itera `TrabalhoParagem::template()`.
**`previsto_para` fica `null` na criação** — inventar datas num documento legal é pior do que
não ter datas. Edição inline por linha no ecrã; o PDF imprime "a definir".
`executado_por` vem **sempre** do utilizador autenticado, nunca do formulário.

**Criação do plano é ação explícita, nunca automática dentro de `encerrar()`.** Esse método é
chamado pela bulk action de fim de época, que fecha 5 piscinas de uma vez — passaria a criar
65 obrigações legais sempre que alguém fecha o que quer que seja, incluindo uma avaria de um
dia. Condicionar por `motivo === MANUTENCAO` é a mesma armadilha: a equipa usa `manutencao`
para avarias curtas. O risco de alguém esquecer mitiga-se com **UI** — coluna de progresso
(`3 de 13`) em `PoolClosureResource::table()`, que aparece nos dois sítios de graça.

**`app/Services/EvidenciaParagemService.php`** — leitura pura, **nunca escreve**.

```php
/** @return array<string, array<int, array{momento: Carbon, fim: ?Carbon, detalhe: string,
 *    fonte: string, confianca: string, criterio: string, dados: array}>> */
public function candidatos(PoolClosure $e): array;
public function candidatosPara(PoolClosure $e, string $tipo): array;
```

`criterio` é uma string com os números que dispararam ("ORP ≥ 880 mV durante 5 h; pico +181 mV
sobre base de 14 dias"). Vai impressa ao lado do evento — o leitor confere contra o gráfico da
mesma página.

**Detetores:**

- **Supercloração** — limiar duplo, ambos têm de valer: absoluto
  `orp >= max($piscina->orp_max ?? 800, $baseP95 + 60)` e relativo `pico >= mediana + 100 mV`.
  Só absoluto marcaria permanentemente as piscinas que operam a 800 mV; só relativo marcaria
  oscilação normal numa piscina de banda baixa. Duração mínima 2 leituras (30 min). Fronteiras
  por histerese: fim = primeira leitura abaixo do limiar de saída **sustentada 4 leituras**,
  senão um único vale fecha o evento a meio. Lacuna > 2 h **parte a corrida em duas** — sem
  isto uma falha de sync funde dois eventos e a duração impressa fica errada.
- **Aquecimento** — reamostrar para **médias horárias** em PHP (mata o ruído de 15 min e é
  imune a amostras em falta). Janela de 6 h: dispara com `temp(t+6h) - temp(t) >= 2.0 °C` **e**
  ≥80% dos passos horários `>= -0.1 °C` (aceita horas planas, rejeita serra) **e** sem queda
  de volta nas 6 h seguintes. 6 h porque abaixo disso o sol da tarde é indistinguível; 2,0 °C
  porque está muito acima do ruído (±0,2). **Não usar `temperatura_ar` como discriminador
  de onda de calor** — o feed real devolve -44,5 °C, é um sensor não ligado. Ideia cortada.
- **Reposição de cloro** — 8 leituras consecutivas (2 h) dentro da banda, **só se houver
  excursão prévia na mesma janela**. Senão isto é operação normal e todos os relatórios
  reclamariam uma reposição que nunca aconteceu.
- **Tanque vazio / paragem** — lacuna ≥ 6 h **sem** `SensorOutage` e **sem** janela de "bomba
  parada" que a explique. Rótulo "provável", nunca "confirmado". `caudal_*` **nunca** como
  sinal primário: é o débito da bomba doseadora, não o caudal de recirculação — zero significa
  "não está a dosear", o que acontece tanto com a instalação parada como com a água no setpoint.
- **Limpeza de tanque, caleiras, filtros, circuito, Legionella** → devolve `[]`, sempre.
  A UI diz literalmente: *"Sem evidência automática possível — este trabalho tem de ser
  confirmado por quem o executou."* **É a afirmação mais importante deste plano.** Nenhum
  sensor evidencia uma escovagem. A Legionella só fecha com o boletim acreditado.

**Performance:** uma query com **Query Builder**, não Eloquent (7100 models com casts `decimal`
é o que limita o modo "todos" do `RelatorioPdf` a 7 dias). Agregação em PHP — os detetores são
histerese e run-length, que em SQL exigem `LAG`/`SUM() OVER`; quatro detetores em SQL de janela
é a definição de spaghetti. **Zero `selectRaw`/`DATE()`/`date_trunc`** — bucketing horário com
`format('Y-m-d H')`, servido pelo índice `['pool_id','lida_em']` nos dois motores.
`raw_parameters` lê-se em PHP, **nunca** com `->>` em SQL (cicatriz da sessão 15).

**A regra inegociável:** o detetor nunca cria uma `PoolClosureTask` executada. Propõe. O
técnico confirma pelo mesmo `marcarExecutado()`, que grava `origem = 'reconstruida'` e prefixa
`observacoes` com *"Reconstruído a partir de leituras do controlador em &lt;data&gt;."*
Uma data inferida por máquina, apresentada como facto presenciado por um técnico, num documento
que ele assina, é falsificação de registo regulamentar.

## 4. UI — RelationManager, sem página nova

**`app/Filament/Resources/PoolClosureResource/RelationManagers/TrabalhosRelationManager.php`**
Registado em `PoolClosureResource::getRelations()` (método que ainda não existe). O
`EditPoolClosure` já renderiza RelationManagers por omissão: **zero páginas novas**.

Uma página standalone custava **sete pontos de toque**: classe Page + blade + const em
`PaginaGestor` + entrada em `all()` + entrada em `labels()` + entrada em
`PaginasGlobalSearchProvider::SINONIMOS` + `docs/paginas/*.md`, tudo a manter em sincronia.
O RelationManager é um ficheiro e **herda o `canAccess()` do pai** — zero constantes novas.

Contra honesto: o repo tem **zero** RelationManagers hoje. É padrão novo nesta base de código.
Mas é Filament de fábrica, não abstração inventada.

**Entradas (o problema de descoberta):** `PoolClosureResource` tem
`shouldRegisterNavigation() = false` e vive em Logs. Ninguém lá chega sozinho.

1. Ação por linha em `EncerramentoPiscinas` — "Plano de paragem",
   `->url(fn (Pool $p) => PoolClosureResource::getUrl('edit', ['record' => $p->encerramentoEm()]))`,
   visível com encerramento vigente. **É a entrada principal** (Operação, admin/gestor/técnico).
2. Coluna de progresso em `PoolClosureResource::table()` — aparece nos dois sítios de graça.
3. Ação "Plano" acrescentada **explicitamente** ao `->actions([...])` do
   `HistoricoEncerramentosWidget` (ver armadilha acima), com TECNICO incluído.

**Formulário de execução:** como o `tipo` está fixo pelo registo, constrói-se por
`match ($record->tipo)` — **sem** as closures `->visible(fn (Get $get) => ...)` que o
`OperationalActionResource` é obrigado a usar (lá o utilizador ainda está a escolher o tipo).
Fica mais limpo que o padrão existente.

Anexos, espelhando `IncidentResource.php:193-204`:
`->disk(DailyRecord::getStorageDisk())->visibility('private')->directory('paragens')->multiple()->maxFiles(10)->maxSize(20480)`.
Usar `DailyRecord::getStorageDisk()`, **não** `->disk('r2')` literal — devolve `public` em dev.

Campos decimais com `->extraInputAttributes(['inputmode' => 'decimal'])`; o `type="text"` já vem
do `configureUsing` global (regra 3 do CLAUDE.md).

`DESINFECAO_LEGIONELLA` tem `documentos` **obrigatório** e `zonas` como CheckboxList. A regra
repete-se no serviço (`DomainException`), não só no formulário — o formulário é UI, o serviço é
a regra.

**`app/Policies/PoolClosureTaskPolicy.php`** espelhando `PoolClosurePolicy`: `viewAny`/`view`
qualquer role; `create`/`update` ADMIN|GESTOR|TECNICO; `delete` só ADMIN.

## 5. Junção com `OperationalAction` — só na leitura

**O risco:** passam a existir duas tabelas capazes de descrever o mesmo ato. O técnico abre
Ações Operacionais — que usa todos os dias, que tem fluxo mobile e endpoint offline — e regista
`tratamento_choque`. O item `supercloracao` fica `previsto`. O relatório afirma que a obrigação
não foi cumprida enquanto a app guarda a prova de que foi. **Pior do que não ter relatório.**

**Nível 1 — Anexo A.** No relatório, depois da checklist, tabela "Evidência operacional
registada no período": todas as `OperationalAction` da piscina entre `inicio->startOfDay()` e
`(fim ?? now())->endOfDay()`, via `tipoLabel()` e `dadosFormatados()`. Uma query em
`evidenciaOperacional()`. **Zero acoplamento na escrita** — nenhuma FK, nenhum observer, nada
muda no fluxo de criação atual.

**Nível 2 — sugestão por item.** Mapa em `TrabalhoParagem` (heurística de apresentação, não
regra de negócio): `SUPERCLORACAO`→`tratamento_choque`; `MANUTENCAO_FILTROS`→`lavagem_filtro`,
`enxaguamento_filtro`, `manutencao_equipamento`; `LIMPEZA_TANQUE*`→`tanque`, `aspiracao_fundo`;
`LIMPEZA_CALEIRAS`→`limpeza_praias`; `REPOSICAO_CLORO`/`VERIFICACAO_PARAMETROS`→`analise_pontual`;
`ENCHIMENTO_TANQUE`→`contador`, `torneira`.

Numa linha `previsto`, badge "2 ações compatíveis no período" → modal → botão que
**pré-preenche** `executado_em`, `executado_por` e `observacoes`. **O técnico confirma. A app
nunca decide.**

**Rejeitado:** FK `operational_actions.pool_closure_task_id` (obrigava todos os caminhos de
criação, incluindo o `OfflineSyncController`, a saber de paragens) e observer que auto-marca
executado (produz afirmações legais a partir de heurística).

**Risco residual, assumido:** a checklist diz "cumprido" porque alguém clicou; a
`OperationalAction` diz que um ato físico aconteceu. Podem discordar. O relatório imprime
**ambos** e deixa o leitor comparar — não finge reconciliá-los. Isso fica escrito na nota legal.

## 6. `LeituraArtefactoService` — dois métodos novos

```php
private function janelasSupercloracao(int $poolId, Carbon $de, Carbon $ate): array;
private function janelasTanqueVazio(int $poolId, Carbon $de, Carbon $ate): array;
```

Fundidos no `array_merge()` de `janelas()`, ao lado de `janelasLavagem`, `janelasBombaParada`,
`janelasSondaIndisponivel`. Copiar a estrutura de par-de-eventos de `janelasBombaParada()` —
não inventar forma nova.

Fonte: `PoolClosureTask` com `tipo IN [SUPERCLORACAO, DESINFECAO_LEGIONELLA]`,
**`estado = EXECUTADO`**, `whereHas('encerramento', fn ($q) => $q->where('pool_id', $poolId))`.
Janela: `executado_em` → `+ (dados.horas_contacto ?? 24 h) + 12 h de estabilização`. Horas, não
minutos — o ORP demora horas a voltar. Motivos: `'Supercloração'`, `'Desinfeção Legionella'`,
`'Tanque vazio'`.

**Só quando `estado = EXECUTADO`.** Uma supercloração apenas *prevista* é um plano, não um
facto — se um item previsto anulasse leituras, qualquer pessoa apagava não-conformidades
planeando uma supercloração. Tem teste próprio.

**Rejeitado:** anular todas as leituras dentro de um `PoolClosure`. Criava dependência de
`LeituraArtefactoService` em `PoolClosureService` e silenciava problemas genuínos em
encerramentos longos com a piscina cheia.

Um método corrige seis consumidores — PDF, dashboard, esquema, alertas, gráficos, heatmap —
porque a fonte única já existe. **É o melhor argumento a favor de toda a arquitetura atual.**

Fechar o ciclo: o relatório imprime, ao lado da supercloração, "Leituras do controlador entre X
e Y assinaladas como não válidas para conformidade". O livro sanitário e o relatório de paragem
contam a mesma história.

## 7. PDFs

**`app/Support/PdfRenderer.php`** (`final class`, padrão de `App\Support\Auditoria`):

```php
public static function render(string $view, array $dados, string $orientacao = 'landscape'): \Dompdf\Dompdf;
public static function stream(string $view, array $dados, string $nome, string $orientacao = 'landscape'): StreamedResponse;
```

Encapsula `getDomPDF/render/page_text` com os magic numbers (`-130`, `-26`, `7.0`) num sítio só.
**Devolve `Dompdf`, não bytes** — um caller faz stream, o comando mensal escreve para R2.

Substitui a duplicação literal em `RelatorioPdf.php:423-448` e
`GerarRelatorioMensalCommand.php:62-86`. Remover também os `use Barryvdh\DomPDF\Facade\Pdf;`
(`RelatorioPdf.php:17` — `exportarCsv()` não usa; `GerarRelatorioMensalCommand.php:13`).
Isto **remove ~26 linhas** do `RelatorioPdf` e não acrescenta nenhuma. Não tocar em
`DgsPdfReportService` — é a dívida nº 3, decisão separada.

**Duas blades com partials**, em `resources/views/pdf/paragem/`:
`plano.blade.php`, `relatorio.blade.php`, `_estilos.blade.php`, `_cabecalho.blade.php`,
`_trabalhos.blade.php` (param `$mostrarExecucao`), `_assinaturas.blade.php`.

Os partials matam a divergência (o medo que justificaria uma blade só) sem enfiar 60% do corpo
em `@if($modo)` — que é exatamente o modo de falha do `livro-sanitario.blade.php`, 1023 linhas
com `$colunasVisiveis`/`$seccoesVisiveis`, que já custa a ler. `_estilos` **copia** o `<style>`
do livro sanitário, não faz `@include` — esse é o documento regulado e tem de poder mudar
sozinho.

**A4 portrait** nos dois. São 7 colunas, não as 18 do livro sanitário.

**Secções do relatório:** cabeçalho fixo → identificação (`descricao_periodo`, `dias`,
`motivo_label`, quem encerrou) → tabela de trabalhos → evidência do controlador → anexos →
"o que não é possível provar" → assinaturas → nota legal.

**Regras de honestidade, não negociáveis:**

- **Todos os itens aparecem sempre**, mesma tabela, mesma ordem. Linha ausente lê-se como
  ocultação; linha presente com "Não executado" lê-se como gestão.
- **Estado é texto, nunca só ícone.** Em preto e branco ✓ e ✗ borram-se e um ícone não carrega
  o motivo.
- **Contagem no cabeçalho da secção:** "13 previstos · 9 executados · 2 não executados
  (motivos abaixo) · 2 não aplicáveis". O leitor tem o resultado antes da tabela, por isso a
  tabela não pode ser acusada de o enterrar.
- **Coluna "Origem"** em cada linha. CSS `.inferido` com borda esquerda e fundo cinzento —
  em P&B continua a ser visivelmente outra classe de afirmação.
- **Linha de cobertura**: "N leituras entre X e Y, cadência 15 min, cobertura Z% do período".
  É a frase mais persuasiva do documento e é facto puro.
- **Secção "O que não é possível provar automaticamente"**: limpeza de tanque (a sonda está em
  linha no circuito; tanque vazio é indistinguível de bomba parada e de avaria de sonda);
  Legionella (nenhum sensor a vê, só o boletim); supercloração manual com bomba parada (o
  elétrodo não vê nada — ausência de pico não é prova de não-execução); que equipamento foi
  operado; cloro em mg/L (nunca foi medido — ORP é proxy dependente de pH e temperatura,
  **não convertível**). Sem esta secção o relatório é over-claim e uma pergunta afiada
  destrói-o.
- **Legionella** só pode ser `executado` (com boletim) ou `nao_executado`. **Nunca
  `evidencia_indireta`** — impedir em código.

**Gráfico:** SVG inline no padrão do `livro-sanitario` (closures `yFor`/`xFor`, path `M`/`L`
para gaps, sem `rgba`, sem JS). Dois gráficos empilhados, mesmo eixo X: ORP com banda
`orp_min`–`orp_max` em `<rect>`, e `temperatura_agua` com banda `temp_min`–`temp_max`. pH não é
a história aqui. Marcas verticais no início/pico de cada evento, para tabela e gráfico
concordarem à vista. Série = **média horária** (60 dias × 24 h em 700 px já são ~2 pontos/px);
a legenda di-lo.

**Anexos:** fotos em base64, máximo 12 embebidas, saltar acima de ~1,5 MB (base64 infla 33% e o
dompdf segura o bitmap descodificado). Boletins PDF como índice: nº, tipo, laboratório, data de
colheita, data do boletim, resultado com unidade, nome do ficheiro, hash SHA-256 curto. O hash
é o que torna "entregue em separado" defensável.

**`app/Services/PlanoParagemPdfService.php`** monta e renderiza; as actions ficam finas.
Auditoria com `activity('relatorio')->causedBy(auth()->user())->log(...)`, como
`RelatorioPdf.php:459` — o documento que vai para o vereador tem de estar no trilho.

Botões: duas header actions em `EditPoolClosure::getHeaderActions()`. **Não** header action de
página com seletor próprio — seria um segundo formulário que pode divergir do encerramento
registado, que é exatamente a fratura `RelatorioPdf` vs `DgsPdfReportService` já listada como
dívida nº 3.

## 8. Testes

Estilo do repo: classes PHPUnit com métodos `test_*` e `RefreshDatabase` (ver
`tests/Unit/Services/PoolClosureServiceTest.php`), apesar de o runner ser Pest.

`tests/Unit/Services/PlanoParagemServiceTest.php` — cria plano completo do template, `ordem`
contígua; não cria duas vezes (`DomainException`); invariante de `estado`; Legionella sem
boletim não pode ser executada; `nao_aplicavel` exige justificação; `resumo()` conta obrigatórios
em falta; apagar encerramento apaga os trabalhos (guarda contra `cascadeOnDelete` em falta —
a classe de bug que o projeto já apanhou em `Installation::boot()`).

`tests/Unit/Constants/TrabalhoParagemTest.php` — template só usa tipos válidos; `all()` ⊆
`array_keys(labels())` (guarda contra o modo de falha do `PaginaGestor`); **Legionella é
obrigatória no template** — o item que o vereador perguntou não pode ser silenciosamente
despromovido por uma edição futura.

`tests/Unit/Services/EvidenciaParagemServiceTest.php` — deteta pico de ORP sintético com
`inicio`/`pico`/`fim` corretos; série plana devolve `[]` (um detetor que dispara sempre é pior
que nenhum); deteta rampa de temperatura de 3 dias; ondulação diurna de ±0,4 °C **não** é
detetada; lacuna coberta por `SensorOutage` reportada como explicada; **limpeza de tanque e
Legionella devolvem `[]` quaisquer que sejam as leituras** (é o teste que protege a posição
legal); `candidatos()` não escreve nada na BD.

`tests/Unit/Services/LeituraArtefactoServiceTest.php` (estender) — supercloração executada
invalida leituras da janela; **supercloração apenas prevista não invalida nenhuma**.

`tests/Feature/PlanoParagemPdfTest.php` — PDF gera com bytes mágicos `%PDF`; a **blade**
renderizada (não o output do dompdf, que não é pesquisável) marca obrigatórios em falta; um
`tratamento_choque` dentro da janela aparece no Anexo A e um do dia anterior a `inicio` não.

`tests/Feature/PlanoParagemAccessTest.php` — nadador-salvador 403; gestor com `ENCERRAMENTOS`
desligado 403 (guarda o `canAccess()` herdado, que é toda a justificação da secção 4); técnico
marca executado mas não apaga.

## 9. Sequência

| Fase | Conteúdo | Entrega |
|---|---|---|
| **0** | Correr as 3 verificações abaixo | Confirma limiares |
| **1** | `PdfRenderer` + refactor dos 2 consumidores existentes. **Commit isolado, suite verde antes de avançar** | Remove dívida mesmo que tudo o resto seja cancelado |
| **2** | Migração + modelo + `TrabalhoParagem` + factory + testes unitários | Base |
| **3** | `PlanoParagemService` + policy + testes | Regras de negócio |
| **4** | RelationManager + 3 entradas de navegação + coluna de progresso | Registo real utilizável |
| **5** | Blades + `PlanoParagemPdfService` + header actions + testes | **Responde ao vereador** |
| **6** | `janelasSupercloracao()` + `janelasTanqueVazio()` + 2 testes | **Antes de a supercloração ser usada a sério** — senão entram falsas não-conformidades no livro sanitário |
| **7** | `EvidenciaParagemService` + ação de reconstrução | Forense |

A fase 1 é isolável e entrega valor sozinha.

## Verificação

**Antes de escrever código, correr:**

```bash
php artisan tinker --execute="echo App\Models\SensorReading::whereNotNull('caudal_cloro')->count();"
```

Se der zero, o sinal de corroboração da dosagem desaparece e ficam só ORP e temperatura.

```bash
php artisan tinker --execute="dump(App\Models\SensorReading::latest('lida_em')->first()->raw_parameters);"
```

Se lá estiver `noFlow` ou alarmes, a deteção de paragem passa de inferência a facto.

```bash
php artisan tinker --execute="dump(App\Models\Pool::get(['name','orp_min','orp_max','temp_min','temp_max'])->toArray());"
```

Se `orp_min`/`orp_max` estiverem `null`, os fallbacks (650/800) passam a ser os únicos limiares
e a deteção fica bastante mais fraca. Vale a pena preenchê-los antes.

**Depois de implementar:**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse && composer test
```

Teste funcional em produção (regra do CLAUDE.md — não montar ambiente local): abrir
Operação → Encerramentos, ação "Plano de paragem" na piscina encerrada, gerar o plano, marcar
dois itens como executados com foto, marcar um como não aplicável com razão, e exportar os dois
PDFs. Confirmar que o PDF do relatório mostra a contagem no cabeçalho, a secção "o que não é
possível provar", e que a foto aparece embebida (não uma caixa partida).

## Riscos assumidos, ditos sem rodeios

1. **Dois sítios para registar o mesmo ato.** As Ações Operacionais são onde está a memória
   muscular do técnico, com pesquisa global, fluxo mobile e sync offline. A checklist não tem
   nada disso. A junção pela leitura mitiga, não cura.
2. **O caminho offline não cobre.** `OfflineSyncController` trata `daily-records` e
   `operational-actions`. Um técnico numa casa das máquinas sem rede não consegue marcar um item
   de checklist. Lacuna real, fora do v1.
3. **~16 ficheiros novos.** Se o caderno de encargos exigir outra lista, parte dela é custo
   afundado. A estrutura sobrevive; o conteúdo de `TrabalhoParagem::template()` é que muda.
4. **Zero RelationManagers no repo hoje.** Padrão novo, ainda que seja Filament de fábrica.
5. **A Legionella fica sub-especificada por decisão do utilizador.** O contrato exige
   controlo semestral acreditado sobre AQS com 5/4/4 pontos de colheita; a checklist terá
   uma linha com anexo. Se o vereador pedir esse detalhe, o relatório não o tem. O anexo A7
   (AQS) está no projeto, não lido, e é por aí que se alarga.
6. **`Pool::temp_min`/`temp_max` da Infantil a confirmar.** O Anexo A diz 29–30 °C; o
   `CLAUDE.md` diz 28–30 °C. Verificar na BD antes de a checklist avaliar temperaturas.
