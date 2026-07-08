# Redesign do dashboard mobile (Estado das Piscinas + Alertas)

## Contexto

A app é usada quase sempre em telemóvel. O dashboard atual tem dois problemas
em mobile: (1) para ver o estado de todas as piscinas é preciso fazer scroll
pelos cartões detalhados do `PainelPiscinasWidget`, em vez de perceber logo
à entrada se está tudo bem; (2) o Kanban de alertas (`QuadroOperacionalWidget`)
usa colunas lado a lado com drag-and-drop, que não é prático a 375px de largura.

Objetivo: ao abrir o dashboard, ver de imediato quais piscinas estão fora dos
parâmetros (lidos pelo controlador Hanna — todas as piscinas têm sonda), sem
scroll; e substituir o Kanban por uma lista de alertas simples de usar com o
polegar.

Aprovado por protótipo interativo (mockup "Conceito A") — lista vertical
agrupada por instalação, piscinas fora dos limites destacadas a vermelho,
com botão para expandir/recolher todas de uma vez.

## 1. Estado das Piscinas

Substitui a view atual do `PainelPiscinasWidget`
(`resources/views/filament/widgets/painel-piscinas.blade.php`). **A lógica de
cálculo de conformidade em `PainelPiscinasWidget::buildPoolData()` não muda** —
já devolve tudo o que a nova vista precisa (`parametros_conformes`,
`controlador.{ph,orp,temp}` + respetivos `_ok`, `tem_dados_conformes`). Isto é
uma substituição de apresentação, não de dados.

### Estrutura
- Cabeçalho da secção mantém as duas linhas de progresso já existentes
  (`Registos Diários de Hoje X/Y`, `Piscinas Conformes X/Y`) — continuam a dar
  o "de relance" sem custar dados novos.
- Ao lado do cabeçalho, botão **"Expandir tudo"** (troca para "Recolher tudo"
  quando ativo) — alterna todas as piscinas de uma vez, client-side (Alpine),
  sem pedido ao servidor.
- Piscinas agrupadas por instalação, com o nome da instalação como cabeçalho
  discreto (`installation_id` asc — já é a ordem natural de
  `Pool::where('active', true)->orderBy('installation_id')->orderBy('name')`,
  que resulta em Leiria → Maceira → Caranguejeira, tal como pedido). Dentro de
  cada instalação, ordem alfabética do nome (comportamento atual, inalterado).
- Cada piscina é uma linha recolhida por defeito:
  - Ponto de cor (verde = `tem_dados_conformes && todos ok`; vermelho = algum
    `parametros_conformes` a `false`; cinzento = `tem_dados_conformes` falso,
    sem dados frescos).
  - Nome da piscina.
  - Badge textual: "conforme" (cinzento), "N fora" (vermelho, conta quantos de
    pH/Cl./Temp falham), ou "sem dados".
  - Chevron que roda ao expandir.
- Piscinas fora dos limites têm sempre borda esquerda + fundo vermelho claro,
  independentemente de estarem expandidas ou não (facilita encontrar entre
  várias linhas recolhidas).
- Ao expandir (clique na linha, ou via "Expandir tudo"): mostra pH, ORP e
  Temperatura do controlador (`controlador.ph/orp/temp`), cada valor colorido
  a verde/vermelho consoante o respetivo `_ok`; e um link "Registar" que
  reaproveita `url_registar` (já calculado, pré-seleciona a piscina no
  formulário) — mantém a entrada direta para criar um registo que o widget
  atual já oferecia.
- Piscina sem leitura fresca do controlador (`controlador` null ou stale):
  ponto cinzento, badge "sem dados", painel expandido mostra "Sem leitura
  recente do controlador" em vez de métricas.

### Fora de âmbito
- Não altera `PainelPiscinasWidget::buildPoolData()` nem o cálculo de
  conformidade (`parametroOk`, `combinarOk`, thresholds de ORP/pH/temp).
- Não remove o suporte a piscinas sem sonda no código (continua a existir
  para o caso `controlador === null`), mesmo sabendo que hoje todas têm.

## 2. Lista de Alertas

Substitui o Kanban do `QuadroOperacionalWidget`
(`resources/views/filament/widgets/quadro-operacional.blade.php`) e a lógica
de drag-and-drop associada (SortableJS + GSAP de movimento de cartão deixam
de ser necessários neste widget). **`AlertasService::calcular()` não muda** —
continua a ser a fonte dos alertas (piscina sem registo, parâmetro fora dos
limites, incidente aberto, stock baixo, torneira aberta).

### Simplificação de 2 fases (em vez de 3)
- Remove o estado intermédio "Em tratamento". Cada alerta é **ativo** ou
  **resolvido** — decisão já tomada na fase de perguntas.
- `AlertState.status` é uma coluna `string(20)` livre (sem enum na BD), por
  isso não precisa de migração: a query passa a tratar qualquer estado
  diferente de `resolvido` (incluindo `em_curso` de dados antigos) como
  "ativo". `moverAlerta()` mantém-se, só deixa de aceitar `em_curso` como
  destino válido — só `pendente`↔`resolvido`.
- Botão "Iniciar" desaparece; fica só **"Resolver"** por alerta.

### Estrutura
- Lista vertical, sem colunas. Ordenada por gravidade — mesma prioridade que
  hoje ordena a coluna "Para tratar" (violação legal primeiro).
- Cada linha: ícone por tipo de alerta, título, subtítulo (detalhe/valor), e
  botão "Resolver" à direita.
- Alertas resolvidos hoje ficam recolhidos por defeito atrás de uma linha
  "Resolvidos hoje (N)" — toque expande a lista, sem quebrar o ecrã com
  cartões já tratados.
- Um alerta cuja condição desaparece sozinha (ex.: registo em falta passou a
  existir) continua a mover-se automaticamente para resolvido, marcado como
  "(automático)" — comportamento herdado do `AlertasService`/`AlertState`
  atual, inalterado.

### Fora de âmbito
- Não altera `AlertasService::calcular()`, os tipos de alerta, nem a lógica
  de auto-resolução.
- Não introduz confirmação antes de "Resolver" (decisão tomada na auditoria
  de UX anterior: é reversível, não compensa a fricção extra).

## Ficheiros afetados

- `resources/views/filament/widgets/painel-piscinas.blade.php` — reescrita.
- `resources/views/filament/widgets/quadro-operacional.blade.php` — reescrita.
- `app/Filament/Widgets/QuadroOperacionalWidget.php` — simplificar
  `moverAlerta()` para 2 estados; a query de alertas ativos/resolvidos passa a
  usar `status !== 'resolvido'` em vez de comparar aos 3 valores.
- `resources/css/widgets.css` (ou onde já vivem os estilos `mmc-*` destes
  widgets) — novos estilos para a lista expansível e remoção dos estilos só
  usados pelo Kanban (colunas, drag handles) se ficarem órfãos.
- `resources/js/app.js` — remover a inicialização do SortableJS/GSAP do
  Kanban se deixar de ter utilização; adicionar o toggle "Expandir tudo"
  (pode ser um componente Alpine simples, sem necessidade de JS novo em
  ficheiro próprio).
- Sem migrações novas.

## Testes a rever

- Testes existentes que montam `QuadroOperacionalWidget`/`moverAlerta` com
  estado `em_curso` têm de ser atualizados para o novo contrato de 2 estados.
- Sem testes novos de cálculo de conformidade (lógica não muda) — só de
  apresentação/estado se já existirem testes Livewire para estes widgets.
