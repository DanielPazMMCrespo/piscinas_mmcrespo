# Gestão de Incidentes & Ocorrências (`app/Filament/Resources/IncidentResource`)

Recurso central para reporte, acompanhamento em tempo real, chat interno e resolução de anomalias operacionais (avarias de equipamento, fugas de água, contaminações biológicas ou incidentes de qualidade da água) em instalações e piscinas.

---

## 1. Propósito e Perfis de Utilização

1. **Nadador-Salvador (NS):**
   - Utiliza smartphone pessoal no posto de vigia para reportar anomalias urgentes (ex: *contaminação fecal/vómito na água, água turva, cheiro a cloro*).
   - Vê exclusivamente os incidentes reportados por si.
   - Seleção inteligente: a instalação e a piscina vêm pré-selecionadas com base nas piscinas atribuídas ou no contexto do Dashboard.
   - Chips táteis de 1 toque preenchem a descrição técnica de imediato, evitando digitação manual em ecrãs táteis.
   - Disparo direto para a câmara do telemóvel (`capture="environment"`), sendo as fotos 100% opcionais.

2. **Técnico de Manutenção / Gestor / Administrador:**
   - Acompanha todas as ocorrências de todas as instalações em tempo real.
   - Recebe alertas automáticos via Sino do Filament e notificações WebPush (com padrão de vibração em telemóvel).
   - Resolve ocorrências diretamente a partir da listagem ou da página de detalhe com **ações rápidas de resolução** (ex: *Parâmetros repostos, Equipamento reparado, Fuga estancada*).
   - Comunica com o reportante através do widget de chat integrado, estilo iMessage.

---

## 2. Ergonomia & Padrões Apple HIG / Tesla Field UX

- **Seletor Táctil por Pílulas (`ToggleButtons`):**
  - O antigo dropdown de seleção de tipo foi substituído por botões táteis grandes com ícones e cores temáticas:
    - 🔧 **Avaria de Equipamento** (âmbar)
    - 💧 **Fuga de Água** (azul)
    - 🧪 **Qualidade da Água** (vermelho)
    - ⚠️ **Outro** (cinzento)
- **Presets de Preenchimento Rápido (1 Toque):**
  - Botões de atalho contextual preenchem instantaneamente a descrição da ocorrência:
    - *Qualidade da Água:* `[💩 Fezes na água]`, `[🤢 Vómito na água]`, `[🌫️ Água turva]`.
    - *Avaria:* `[⚡ Bomba parada / disjuntor]`, `[🔊 Ruído anormal]`.
    - *Fuga:* `[🌊 Inundação / Fuga]`.
- **Listagem com Abas Rápidas no Topo (`ListIncidents`):**
  - **`Abertos`** (aba padrão ativa, com badge dinâmico da contagem de incidentes pendentes a vermelho).
  - **`Hoje`** (ocorrências reportadas no dia de hoje).
  - **`Resolvidos`** (arquivo histórico com badge verde).
  - **`Todos`** (visão geral sem filtros de estado).
- **Modal de Resolução Expressa (3 Segundos):**
  - Ao clicar no botão primário `[Resolver]`, o técnico dispõe de 4 presets comuns (`[✅ Parâmetros repostos]`, `[🔧 Equipamento reparado]`, `[💧 Fuga estancada]`, `[🧹 Aspirado e limpo]`) que preenchem a nota de resolução num toque.
- **Chat Estilo Apple Mensagens / iOS (`IncidentChatWidget`):**
  - Balões de conversa modernos com cantos arredondados assimétricos (`rounded-2xl`).
  - Mensagens de sistema centradas em pílulas discretas.
  - Auto-scroll suave para o final da conversa.
  - Envio rápido com tecla Enter no desktop e botão com ícone de avião de papel.
  - Reabertura automática de incidentes resolvidos caso um Técnico ou Admin envie nova mensagem na conversa.

---

## 3. Segurança, Permissões e Integridade

- **Defesa em Profundidade:**
  - `IncidentPolicy` restringe a edição e eliminação estritamente a administradores (`UserRole::ADMIN`).
  - O hook de criação do modelo `Incident::autorizarPiscinaDoAutor()` valida no servidor que nadadores-salvadores nunca submetem incidentes para instalações ou piscinas não autorizadas.
- **Audit Trail & Notificações:**
  - Todas as alterações de estado geram mensagens de auditoria automáticas na timeline do incidente (`IncidentMessage::TIPO_SISTEMA`).
  - Notificações são direcionadas de forma inteligente (`Incident::participantes()`) para evitar ruído e fadiga de alarmes.
