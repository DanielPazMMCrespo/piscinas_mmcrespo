# PoolClosureResource — Encerramentos e Paragem Técnica

Duas coisas diferentes vivem nesta pasta:

1. **O histórico de encerramentos** (`PoolClosure`) — tabela de consulta, em Logs → Encerramentos.
2. **O plano de trabalhos da paragem técnica** (`PoolClosureTask`) — o `TrabalhosRelationManager`,
   723 linhas, que é onde está quase toda a lógica.

**Encerrar e reabrir não se faz aqui.** Faz-se em Operação → Encerramentos, que passa pelo
`PoolClosureService`. O `canCreate()` deste resource devolve `false` de propósito: criar uma
linha à mão saltava a validação de sobreposição de períodos. Aqui só se consulta, se corrige
o motivo/observações, se gere o plano de trabalhos e — só admin — se apaga.

---

## O plano de trabalhos

### O template legal (13 trabalhos)

`App\Constants\TrabalhoParagem::template()` é o catálogo, **em código e não em base de dados**:
é um contrato legal, não configuração. Ordem fixa:

| # | Trabalho | Obrigatório |
|---|---|---|
| 1 | Esvaziamento do tanque | |
| 2 | Limpeza e desinfeção do tanque | **sim** |
| 3 | Limpeza do tanque de compensação | **sim** |
| 4 | Limpeza de caleiras e grelhas | |
| 5 | Manutenção / massa filtrante | |
| 6 | Limpeza do circuito hidráulico | |
| 7 | Desinfeção e controlo de Legionella | **sim** |
| 8 | Enchimento do tanque | |
| 9 | Supercloração / choque de arranque | **sim** |
| 10 | Reposição dos níveis de cloro | **sim** |
| 11 | Arranque do sistema de aquecimento | |
| 12 | Verificação de parâmetros pré-reabertura | **sim** |
| 13 | Outro trabalho | |

Seis são obrigatórios (`TrabalhoParagem::obrigatorios()`). Um trabalho acrescentado à mão
com `[Acrescentar Trabalho]` pode ser marcado obrigatório por decisão técnica.

### Estado e origem são ortogonais

Não confundir as duas colunas. **Estado** diz se está feito; **origem** diz como se soube.

- **Estado**: `previsto` → `em_curso` → `executado` / `nao_executado` / `nao_aplicavel`.
- **Origem**: `declarada` (alguém registou), `reconstruida` (reconciliado com evidência do
  período), `inferida` (proposto).

Um trabalho pode estar `executado` com origem `reconstruida` — é o caso de se ter feito o
trabalho e só depois se ter registado, a partir de uma ação operacional ou de um padrão de sonda.

### Não é append-only

Ao contrário do `DailyRecord`, do `FilterCheck` e do `Incident`, o `PoolClosureTask` **edita
a própria linha**. A prova de quem mudou o quê vem do `LogsActivity` (`logFillable()`,
`logOnlyDirty()`), não de linhas de correção. Não tentar aplicar aqui o padrão append-only.

---

## Invariantes legais — todas no `PlanoParagemService`

`PlanoParagemService` é o **único escritor** do estado das tarefas. Nunca fazer
`$tarefa->update(['estado' => ...])` no RelationManager: as regras abaixo deixariam de correr.

| Regra | Onde |
|---|---|
| O plano cria-se **uma vez** e **à mão**. Nunca automático ao encerrar. | `criarPlano()` lança `DomainException` se já existir plano |
| **Legionella não fecha sem o boletim.** Marcar `executado` sem documento anexado é recusado. | `marcarExecutado()` |
| `nao_executado` exige **motivo** escrito. | `marcarNaoExecutado()` |
| `nao_aplicavel` exige **fundamentação técnica** escrita. | `marcarNaoAplicavel()` |
| **Não se reabre a piscina com trabalhos obrigatórios em falta.** | `PoolClosureService::reabrir()` + scope `PoolClosureTask::obrigatoriosEmFalta()` |

A última é a mais importante e vive noutro ficheiro. Sem ela, uma tarefa legal obrigatória
ficava `em_curso` para sempre e a piscina reabria com o livro incompleto. "Em falta" significa
estado diferente de `executado` **e** de `nao_aplicavel` — justificar conta como resolver.

O `PoolClosureService` sabe do `PoolClosureTask`, mas o `PlanoParagemService` **não** reabre nem
encerra nada. A dependência é num sentido só.

---

## Sugerir Evidência (`reconstruirEvidencia`)

Botão "Sugerir Evidência", visível só quando o trabalho está `previsto`. Junta duas fontes:

1. **Ações operacionais compatíveis** no período — o mapa está em
   `TrabalhoParagem::acoesOperacionaisCompativeis()`.
2. **Padrões detetados pela sonda** — `EvidenciaParagemService`.

### `EvidenciaParagemService` é leitura pura

**Nunca escreve na base de dados.** Lê `sensor_readings` e devolve candidatos com `momento`,
`fim`, `detalhe`, `fonte`, `confianca` e `criterio`. Deteta quatro coisas:

| Deteção | Critério | Confiança |
|---|---|---|
| Supercloração | ORP acima do limiar (o menor entre `orp_max`/p95+60 e mediana+100 dos 14 dias anteriores), com histerese de saída de 40 mV e corte em lacunas > 2 h | alta com dosagem ou pico ≥ 850 mV, senão média |
| Arranque de aquecimento | subida ≥ 2,0 °C em 6 h, com ≥ 4 dos 5 passos horários sem recuar | alta se o ar subiu menos que 80% do que a água |
| Reposição de cloro | 8 leituras seguidas (2 h) dentro da banda de ORP depois de uma excursão | média |
| Tanque vazio / paragem | lacuna ≥ 6 h nas leituras **sem `SensorOutage` declarada** | baixa |

**Devolvem sempre `[]`, de propósito:** limpeza de tanques, de compensação, de caleiras, de
circuito, manutenção de filtros, enchimento, verificação de parâmetros, outro — e **Legionella**.
Trabalho mecânico prova-se com testemunho; Legionella prova-se com o boletim de laboratório
acreditado. Ligar aqui uma deteção daria a ideia de que se pode fechar sem colheita.

### O travão que aqui existe

Sem evidência nenhuma, o **botão de submeter do modal fica escondido**
(`modalSubmitAction(... ? $action : $action->hidden())`) e o "Cancelar" passa a "Fechar".
Antes disto, o botão gravava o trabalho como `executado` com origem `reconstruida` — a dizer,
no relatório legal, que fora reconstruído a partir de evidência que nunca existiu.

---

## Provas por trabalho

Três colunas JSON no `PoolClosureTask`, todas opcionais menos onde a lei manda:

| Coluna | Limite | Formatos |
|---|---|---|
| `fotos` | 10 ficheiros, 20 MB cada | JPEG, PNG, WebP |
| `videos` | **1 clipe**, 60 MB | mp4, m4v, mov |
| `documentos` | 10 ficheiros, 20 MB (só no trabalho de Legionella) | PDF |

Tudo no disco `r2`, `visibility('private')`, pasta `paragens/` (vídeos em `paragens/videos/`).

**HEIC não está no `acceptedFileTypes` das fotos de propósito.** Sem HEIC no accept, o iOS
converte a foto para JPEG na seleção. O dompdf não descodifica HEIC — aceitá-lo empurrava
todas as fotos de iPhone para a lista de "não impressas" do relatório legal.

**O vídeo tem duas variantes de MIME na lista** (`video/mp4` **e** `application/mp4`): o
`fileinfo` do PHP classifica muitos MP4 legítimos de telemóvel como `application/mp4`,
dependendo da marca no cabeçalho `ftyp`.

⚠️ **`config/livewire.php` → `temporary_file_upload.rules` é a porta de todos os uploads e
recusa sem mensagem visível.** Ao acrescentar aqui um tipo novo de anexo, acrescentar primeiro
a extensão a essa lista. Já aconteceu: o clipe desaparecia, a tarefa ficava `executado`, e o
livro sanitário ficava sem a prova.

### Ver as provas depois

Botão **"Evidências"** (`verEvidencias`), visível quando há fotos ou vídeo. Abre
`resources/views/filament/paragem/evidencias.blade.php` com `<video controls>` e as fotos.
Existe porque, depois de o trabalho ficar `executado`, o botão "Executar" desaparece — e sem
este as provas só se viam no PDF.

O `media-src` do CSP tem de incluir o `r2.dev`, senão o vídeo cai no `default-src 'self'`
e não toca.

---

## Os dois PDF

`PlanoParagemPdfService` gera dois documentos, ambos A4 portrait, os dois pela página de
edição do encerramento (`EditPoolClosure`, header actions):

| Documento | Botão | Quando |
|---|---|---|
| **Plano de Trabalhos** (`pdf.paragem.plano`) | `[Plano (PDF)]` | prévio: o que se vai fazer |
| **Relatório de Paragem** (`pdf.paragem.relatorio`) | `[Relatório (PDF)]` | final: o que se fez e com que prova |

Cada geração e cada download escrevem no trilho de auditoria, canal `relatorio`.

### Regra de ouro do relatório

**Um ficheiro que o dompdf não consegue imprimir nunca é omitido em silêncio de um documento
legal — sai referenciado, com o motivo.** `processarFotos()` embute no máximo 30 fotos, cada
uma até 2 MB, em base64; tudo o que sobra vai para `fotosNaoEmbutidas` com um destes motivos:
ficheiro não encontrado, excede o limite de impressão, formato não suportado.

- **Vídeos** (`processarVideos()`): o dompdf não reproduz vídeo, logo saem como anexo
  referenciado — nome, tamanho e ligação absoluta ao ficheiro arquivado. **Sem hash**, por
  decisão do responsável técnico: não é exigido pela CN 14/DA, e evita descarregar até 60 MB
  do R2 a cada geração (só se lê o tamanho).
- **Boletins** (`processarDocumentos()`): **com SHA-256**, calculado sobre o conteúdo, mais
  laboratório, número de boletim e resultado lidos do JSON `dados`.
- **Eventos de sonda** saem agrupados **por tipo, não por ocorrência**: a deteção dispara
  dezenas de vezes para o mesmo facto (14 rampas de aquecimento, 9 estabilizações de ORP) e a
  tabela repetida deixa de ser lida. A síntese do aquecimento **não cita temperaturas** de
  propósito — a amplitude por rampa inclui insolação e estabilização da sonda, logo o número
  não é atribuível ao sistema de aquecimento.
- **Gráfico de sonda**: SVG gerado à mão (`gerarGraficoSvg()`), médias horárias de ORP e
  temperatura, mais uma percentagem de cobertura calculada contra uma leitura esperada a cada
  15 minutos. Só sai se houver leituras.

⚠️ **Nenhuma dependência do construtor pode ter `= null`.** O container do Laravel devolve o
valor por defeito para um parâmetro de classe com default e sem binding explícito, ou seja,
nunca as injeta. Foi assim que a secção de eventos de sonda deixou de sair no relatório sem
ninguém dar por isso.

---

## Autorização

- `PoolClosureResource::canAccess()` — admin, gestor ou técnico, **e** `podeVerPagina(PaginaGestor::ENCERRAMENTOS)`.
- `PoolClosureResource::canCreate()` — sempre `false`.
- `PoolClosureTaskPolicy`: ver, criar e atualizar → admin, gestor, técnico. **Apagar → só admin**
  (apagar uma tarefa que justifica cumprimento legal não é uma correção, é fazer desaparecer prova).
- O nadador-salvador vê (`viewAny`/`view` aceitam todos os papéis) mas não mexe.

---

## Cache

`PoolClosureTask::boot()` invalida, em cada `saved` e `deleted`, todos os alertas
(`invalidateAllAlerts()`) e o gráfico da piscina do encerramento. Não é preciso invalidar à mão.

---

## Testes

| Ficheiro | Cobre |
|---|---|
| `tests/Unit/.../PlanoParagemServiceTest.php` | as invariantes do serviço |
| `tests/Unit/.../TrabalhoParagemTest.php` | template, estados, origens |
| `tests/Unit/.../PoolClosureTaskTest.php` | scopes e `dadosFormatados()` |
| `tests/Unit/.../EvidenciaParagemServiceTest.php` | as quatro deteções |
| `tests/Unit/.../PoolClosureTaskPolicyTest.php` | autorização |
| `tests/Feature/PoolClosureRelationManagerTest.php` | as ações da tabela |
| `tests/Feature/PoolClosureServiceReabrirTest.php` | o bloqueio da reabertura (3 casos) |
| `tests/Feature/PlanoParagemPdfTest.php`, `RelatorioParagemConteudoTest.php` | conteúdo dos PDF |
| `tests/Feature/ParagemVideoEvidenciaTest.php`, `UploadTamanhoVideoTest.php` | upload de vídeo |
| `tests/Feature/PlanoParagemAccessTest.php` | acesso por papel |

Os testes de upload usam **bytes verdadeiros** de um MP4 (`tests/Fixtures/video-evidencia.mp4`).
Um `UploadedFile::fake()->create()` vai vazio e não prova nada sobre validação de tipo — é o
cabeçalho `ftyp` que o `fileinfo` lê.

---

## Coisas a rever (encontradas ao ler o código)

1. **A evidência escolhida em "Sugerir Evidência" não é guardada.** O formulário exige
   `evidencia_selecionada`, valida-a, e depois só passa `executado_em`, `observacoes` e
   `origem` ao serviço. O trabalho fica `reconstruida` sem dizer **de quê** — o relatório legal
   não consegue citar a ação operacional nem o evento de sonda que o sustenta. É a lacuna mais
   séria que aqui está.
2. **`dadosFormatados()` tem ramos explícitos que largam campos que o formulário recolhe.**
   Em `VERIFICACAO_PARAMETROS` (trabalho **obrigatório**) o formulário grava
   `dados.temperatura_agua` e `dados.acido_cianurico`, e o ramo do `switch` lê
   `dados['temperatura']` e nem toca no ácido cianúrico. Em `ARRANQUE_AQUECIMENTO` o
   `dados.equipamentos` também cai. Como o PDF imprime `dadosFormatados()`, esses valores
   nunca chegam ao documento legal. Os tipos **sem** ramo próprio não têm este problema — caem
   no `default`, que percorre todas as chaves.
3. **`opcoesEvidencia()` corre a deteção toda por linha, várias vezes.**
   `candidatosPara()` chama `candidatos()`, que faz as quatro deteções para os 13 tipos, e o
   RelationManager chama `opcoesEvidencia()` outra vez em `temEvidencia()` (visível, submit,
   cancelar). Numa tabela de 13 linhas são dezenas de varreduras a `sensor_readings`. Nunca
   deu problema porque a tabela é pequena e não pagina, mas é um candidato óbvio a memoização.
4. **`criarPlano()` e `acrescentarTrabalho()` recebem `User $utilizador` e não o usam.**
   A autoria vem do causer do `LogsActivity`. Ou se usa, ou se tira da assinatura.
5. **`previsto_para` nasce sempre `null`.** O `criarPlano()` não distribui datas pelos 13
   trabalhos, logo o Plano de Trabalhos em PDF sai todo com "A definir". Confirmar com a equipa
   se o documento prévio serve assim.
6. **A duração do vídeo não é validada.** Os "10-15 s" são texto no `helperText`; o servidor
   não tem `ffmpeg`. O que trava é o tamanho (60 MB) — um clipe de 2 minutos a 1080p passa.
7. **O `estado` `em_curso` não tem botão.** Existe na máquina de estados, o scope
   `obrigatoriosEmFalta()` conta com ele e a tabela pinta-o de amarelo, mas nenhuma ação o
   define: passa-se de `previsto` direto para um estado final. Ou se acrescenta o botão, ou o
   estado é decorativo.
