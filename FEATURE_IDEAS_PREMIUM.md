# 🎨 FEATURE IDEAS — PREMIUM UX/FEATURES

**Data:** 2026-06-19  
**Fonte:** Ideias Daniel  
**Status:** Em avaliação para roadmap futuro

---

## 📋 IDEIAS LISTADAS

### 1️⃣ Microinterações & Haptic Feedback
**Descrição:** Cada ação tem "peso físico". Arrastar slider de pH gera vibrações no telemóvel. Perigo = vibração aguda/forte. Ideal = clique suave.

**Análise:**
- ✅ Viável: Web API (Vibration API, standard)
- ✅ Esforço: 2h
- ✅ Impacto UX: ALTO (feedback imediato em campo)
- ✅ Compatibilidade: Todos os smartphones modernos
- 🎯 **RECOMENDADO** — implementar em v2

**Implementação:**
```javascript
// Sensação "ideal" (pH/cloro conformes)
navigator.vibrate([10]); // clique suave

// Sensação "perigo" (fora dos limites)
navigator.vibrate([50, 50, 100]); // agudo + forte
```

---

### 2️⃣ Thumb-Zone Ergonomia
**Descrição:** Uma mão livre (típico em casa de máquinas). Bottom Sheets para navegação, botões de ação no fundo. Zero botões no canto superior esquerdo.

**Análise:**
- ✅ Parcialmente implementado (mobile-first já existe)
- ✅ Esforço: 4h (refactoring formulário + ações)
- ✅ Impacto: CRÍTICO (usabilidade em campo)
- ✅ Compatibilidade: 100%
- 🎯 **MUITO RECOMENDADO** — prioritário pós-launch

**O que fazer:**
- Bottom sheets para "Adicionar químico", "Tirar foto", "Corrigir registo"
- Botão flutuante (FAB) no canto inferior direito = "Submeter registo"
- Teste: consegues completar registo com 1 mão

---

### 3️⃣ Offline First Máximo
**Descrição:** Nunca mostra "sem internet". Parâmetros inseridos no piso -2 são instantâneos. Animação indica silenciosamente que dados estão em fila local.

**Análise:**
- ⚠️ Complexo: requer service workers + IndexedDB + background sync
- ⚠️ Esforço: 12-16h
- ✅ Impacto: CRÍTICO (campo é underground, offline é realidade)
- ⚠️ Risco: cuidado com race conditions (múltiplos devices, mesma piscina)
- 🟡 **VIÁVEL MAS PESADO** — candidato para Sprint 2 (pós-launch)

**Tecnologia:**
- Service Worker (PWA offline)
- IndexedDB (cache local)
- Background Sync API (sync quando retorna internet)
- Indicador visual: badge "↑ A sincronizar..." (neutro, não intrusivo)

---

### 4️⃣ Calculadora Generativa
**Descrição:** Ao inserir pH 7.8, painel expande: "Temos de baixar o pH. Adicione 450ml de Redutor X. Restam 5L no armazém da carrinha."

**Análise:**
- ⚠️ Requer API IA (Gemini/Claude/OpenAI)
- ⚠️ Custo operacional: ~$0.01-0.05 por cálculo
- ⚠️ Esforço: 6-8h (integração + caching)
- ✅ Impacto: ALTO (diferenciador competitivo)
- ❌ **NÃO RECOMENDADO AGORA** — custo-benefício baixo. Esperar até ter volume de utilizadores

**Fallback (sem API):**
Usar tabelas locais pré-calculadas:
```javascript
// Em vez de IA, lookup em BDD local
dosagem = calcularDosagem(valor_atual, valor_alvo, volume_piscina)
```
Isto dá 80% do valor com 5% do custo. **Implementar isto em v1.1**.

---

### 5️⃣ Modo Quiosque (Display Público)
**Descrição:** Link público desenhado para ecrãs de receção de hotéis. Tipografia enorme, cores suaves. "Qualidade da Água: Perfeita • Última verificação há 45 min".

**Análise:**
- ✅ Muito viável: é só uma view + CSS
- ✅ Esforço: 3h
- ✅ Impacto COMERCIAL: CRÍTICO (marketing + brand)
- ✅ Compatibilidade: 100%
- 🎯 **MUITO RECOMENDADO** — implementar em v1.1 (imediatamente pós-launch)

**Implementação:**
```
GET /kiosk/{installation_id}
└─ View simples + responsivo
   ├─ Pool name em 48pt
   ├─ Status (Perfeita / Aviso / Crítico) com cor + ícone
   ├─ Última verificação (humanizada: "há 45 min")
   ├─ Métricas principais (pH, cloro, temp) em grande
   └─ Auto-refresh a cada 30s
```

---

### 6️⃣ Relatórios Automáticos por Email
**Descrição:** Fim do mês, relatório automático (formatação editorial) entregue na caixa de email da administração. Assinado digitalmente.

**Análise:**
- ✅ Viável: já temos geração de PDF
- ✅ Esforço: 3h (agendador + email template)
- ✅ Impacto COMERCIAL: ALTO (fidelização)
- ✅ Compatibilidade: 100%
- 🎯 **RECOMENDADO** — Sprint 1 pós-launch (Task 8 background jobs)

**Implementação:**
```
Cron job (mensal, dia 1 às 08:00):
1. Query registos do mês anterior
2. Gera PDF "Relatório de Março" (design editorial)
3. Assina com certificado X.509
4. Envia por email a admin@mmcrespo.pt
5. Arquivo em storage (histórico)
```

---

## 🎯 PRIORIZAÇÃO HONESTA

| ID | Feature | Viabilidade | Esforço | Impacto | Prioridade |
|---|---------|------------|---------|---------|-----------|
| 1 | Haptic Feedback | ✅ Fácil | 2h | Alto | **v1.1** |
| 2 | Thumb-Zone | ✅ Médio | 4h | Crítico | **v1.1** |
| 3 | Offline First | ⚠️ Pesado | 12-16h | Crítico | Sprint 2 |
| 4 | Calculadora IA | ⚠️ Caro | 6-8h | Médio | ❌ Skip (por agora) |
| 5 | Quiosque Display | ✅ Fácil | 3h | Crítico (brand) | **v1.1** |
| 6 | Relatórios Email | ✅ Médio | 3h | Alto | **Sprint 1 pós-launch** |

---

## 📊 RECOMENDAÇÃO FINAL

### ✅ IMPLEMENTAR EM v1.1 (imediatamente pós-launch, 1-2 semanas)
1. **Haptic Feedback** (2h) — sente-se "viva"
2. **Thumb-Zone** (4h) — usabilidade em campo
3. **Quiosque Display** (3h) — posiciona marca

**Total: 9h = ~2 sprints curtos**

### 🟡 IMPLEMENTAR EM SPRINT 2 (3-4 semanas)
- **Offline First Máximo** (12-16h) — crítico para campo
- **Relatórios Email** (3h) — já vem com Task 8 (background jobs)

### ❌ SKIP (por enquanto)
- **Calculadora Generativa IA** — custo-benefício ruim. Se conseguires tabelas pré-calculadas locais, isso é 80% do valor sem custo. Rever quando tiveres volume.

---

## 🔐 NOTA TÉCNICA

**Haptic + Vibration API:** Funciona em todos os browsers modernos, mas alguns dispositivos Android desligam por defeito. Precisa de permissão. Fallback: animação visual.

**Offline First:** Complexo mas **essencial** para pools underground. Já há bibliotecas prontas (Workbox, PouchDB). Não é "rocket science", é só tempo.

**Quiosque:** Pode ser um route público sem autenticação. Cuidado com exposição: considera um UUID/token secreto (`/kiosk/abc123def456`).

---

## 💾 DECISÃO

**Guarda estas ideias.**

Amanhã quando lançar em Railway, esta será a v1.0. Depois:
- **Semana 1-2:** v1.1 com Haptic + Thumb-Zone + Quiosque (9h, impacto 10/10)
- **Semana 3-4:** Sprint 2 com Offline + Relatórios Email

Isto posiciona a app não como "software de manutenção", mas como **"assistente inteligente em tempo real"**.

