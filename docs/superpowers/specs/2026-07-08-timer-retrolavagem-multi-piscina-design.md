# Timer de retrolavagem + registo multi-piscina (Leiria)

## 1. Timer de retrolavagem

### Onde aparece
No step "Filtros" do wizard de criação (`DailyRecordFormBuilder.php`), ligado aos campos:
- `filtro_foto_retrolavagem` → timer default **5 min**
- `filtro_foto_enxaguamento` → timer default **2 min**
- `filtro_foto_posicao_normal` → sem timer

O timer arranca automaticamente assim que a respetiva foto é carregada (evento `change` do FileUpload via Livewire/Alpine).

### Comportamento
1. **Abre em modal ecrã cheio** (estilo iPhone): anel de progresso circular, contagem decrescente `MM:SS`, botões Cancelar/Pausar, e "Alterar duração" (stepper +/- em minutos, sem limite superior/inferior além de min 1 min).
2. **Ao tocar fora do modal** (ou botão fechar): o modal colapsa numa **pill fixa no topo do ecrã** (sticky, por cima do conteúdo do formulário) — anel de progresso mini + título + tempo restante + chevron. O formulário continua acessível e com scroll por baixo.
3. Tocar na pill reabre o modal em ecrã cheio.
4. **Ao terminar**: pill fica vermelha, `Notification` sonora (Web Audio API, um beep curto) + `navigator.vibrate` se disponível (mobile). Fica visível até o utilizador tocar para dispensar.
5. **Concorrência**: no máximo 2 timers simultâneos (retrolavagem + enxaguamento). Se ambos ativos, a pill empilha as duas linhas (uma por cima da outra), sem colapsar numa só.
6. **Persistência**: guardado em `localStorage`, chave `mmc_timer_{pool_id}_{campo}`, valor = timestamp absoluto de fim (não segundos restantes) + duração original. Sobrevive a reload/bloqueio de ecrã. Limpo ao terminar/cancelar ou ao criar o registo com sucesso.

### Implementação técnica
- Alpine.js component (`resources/js/timer-retrolavagem.js`), sem dependência de backend — é só UX de campo, não precisa de estado no servidor.
- Acoplado ao FileUpload existente via `afterStateUpdated` (Livewire) que despacha um evento browser (`$dispatch`) capturado pelo componente Alpine.
- CSS/HTML novo em `resources/views/filament/forms/components/` ou inline no Blade do form — a decidir na fase de plano conforme convenção existente do projeto.

## 2. Registo multi-piscina (Leiria)

### Contexto
Leiria tem 3 piscinas (Competição, Lazer, Infantil) fisicamente próximas, cada uma com equipamento próprio (filtros/bombas/contador não partilhados). Maceira e Caranguejeira têm 1 piscina cada — não afetadas.

### Fluxo (sequencial guiado)
1. **Seleção**: ao escolher `pool_id` de uma piscina cuja instalação tem mais de uma piscina ativa, aparece um bloco de checkboxes **"Também registar nesta visita"** com as restantes piscinas da mesma instalação. Default: nenhuma marcada (opt-in). Não aparece para instalações de 1 piscina.
2. **Fila**: `[piscina selecionada] + [piscinas marcadas]`, ordenadas como aparecem no Select (mesma ordem de `Pool::query()->orderBy('name')` já usada no resto da app).
3. **Navegação**: o botão principal do wizard passa a **"Guardar e seguir para {próxima piscina}"** enquanto há próximo item na fila; na última piscina volta a ser **"Criar"** (comportamento atual).
4. **Ao "Guardar e seguir"**: corre exatamente o `create()` atual (lock, transação, append-only, Job de stock/notificação) para a piscina corrente. Sucesso → mostra piscina anterior como "✓ guardada" e recarrega o wizard limpo na próxima piscina da fila, **sem** disparar a notificação persistente "O que pretende fazer a seguir?" (só aparece na última).
5. **O que transita entre piscinas da mesma fila**: `registado_em` (data/hora) e `user_id` (responsável) — é a mesma visita.
6. **O que limpa a cada piscina**: água (pH, cloro, temperatura, turbidez, fotos de análises), equipamento (bomba, contador, filtros, fotos), químicos. Os smart-defaults de bomba/água/tanque recarregam do último registo **da piscina nova** (reaproveita `afterStateUpdated` já existente em `pool_id`).
7. **Timers por piscina**: chave `localStorage` já inclui `pool_id` (ver secção 1) — trocar de piscina na fila não interfere com timers de outra piscina que porventura ainda estejam a contar (caso raro, mas não deve cruzar dados).

### Casos-limite
- Interromper a meio da fila (fechar app/browser): piscinas já gravadas ficam persistidas normalmente; a piscina em curso perde-se, igual ao comportamento atual de qualquer registo não submetido.
- Nadador-Salvador: fluxo igual, mas o formulário de cada piscina mostra só as secções que já lhe são permitidas hoje (Informação Geral + Análises NS + Observações).
- Cada piscina continua a gerar exatamente 1 `DailyRecord` — sem alterações ao modelo, migrações, ou `whereDoesntHave('correcoes')` dos gráficos/PDF.

### Fora de âmbito (não fazer agora)
- Não mexe no formulário de edição (`EditDailyRecord`) — só no de criação.
- Não introduz nenhuma tabela nova nem relação entre os registos das 3 piscinas — são independentes na BD, a fila é puramente de UI/sessão Livewire.
- Não aplica a instalações de piscina única (Maceira, Caranguejeira) — nelas o formulário mantém-se exatamente como está.
