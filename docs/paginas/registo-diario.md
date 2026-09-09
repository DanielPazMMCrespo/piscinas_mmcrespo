# Registo Diário (`app/Filament/Resources/DailyRecordResource`)

Recurso central para o registo das operações de piscina: parâmetros da água (pH, cloro livre/total, temperatura), contadores, estado das bombas/filtros e adições de químicos com débito de stock.

## Propósito e Perfis

O registo diário serve dois públicos distintos com necessidades de terreno diferentes:
1. **Nadador-Salvador (NS):**
   - Utiliza telemóvel pessoal no posto de vigia.
   - Vê exclusivamente as piscinas atribuídas da sua instalação.
   - Regista apenas parâmetros da água (`ns_ph`, `ns_cloro_livre`, `ns_cloro_total`, `ns_temperatura`) e o campo `banhistas`.
   - Máquinas, filtros, bombas, contadores e químicos estão 100% ocultos do seu perfil.
   - Nenhuma foto é obrigatória (a foto do quadro é opcional e não bloqueia a submissão).
2. **Técnico de Manutenção / Administrador:**
   - Efetua a ronda pela ordem física real da sala de máquinas: Contador/Bomba $\to$ Filtros $\to$ Parâmetros da Água $\to$ Químicos.
   - O campo `banhistas` está oculto (não faz parte da ronda técnica).
   - O contador de água calcula dinamicamente o consumo imediato ($\Delta\text{m}^3$) sem julgamentos arbitrários de cores.
   - A adição de químicos é direta a 2 toques (Produto e Quantidade), com débito automático do stock da instalação.

---

## Ergonomia Mobile & Padrão Apple HIG

- **Teclado Numérico Decimal Imediato (`inputmode="decimal"`):**
  - Todos os campos numéricos abrem diretamente o teclado numérico com separador decimal em iOS e Android.
- **Normalização Silenciosa de Vírgulas:**
  - O utilizador pode digitar com vírgula (`7,35`) ou ponto (`7.35`); os hooks de validação e modelo normalizam a entrada para float sem erros de validação.
- **Divulgação Progressiva (Progressive Disclosure):**
  - Ao preencher as leituras de uma piscina, o cartão colapsa suavemente apresentando um resumo compacto no cabeçalho:
    `✓ Competição — pH 7.20 · Cl.L 1.20 · Cl.T 1.60 · 27.5°C`
    permitindo ao utilizador focar a piscina seguinte sem scroll excessivo.
- **Gravação e Transições Fluídas:**
  - `[Gravar Registos]` grava e redireciona de imediato para a Dashboard (`/admin`) com toast de confirmação.
  - `[Gravar e Novo]` grava e limpa o formulário caso o utilizador pretenda registar outra instalação.
- **Listagem com Abas de 1 Toque (`ListDailyRecords`):**
  - Filtros rápidos em pílulas: `Todos`, `Hoje`, `Não conformes`, `Últimos 7 dias`.

---

## Arquitetura de Dados e Integridade Legal

- **Append-Only:**
  - Registos diários nunca são editados *in-place* nem apagados por utilizadores comuns (apenas Admin pode apagar).
  - Correções criam um novo registo com `e_correcao = true` e `corrige_registo_id` a apontar para o original.
- **Limites Dinâmicos (CN 14/DA):**
  - A conformidade do cloro livre é avaliada contra a banda legal específica do pH medido na mesma amostra (`LimitesLegaisService`).
- **Validação de Erros Graves:**
  - Bloqueio imediato se Cloro Total for inferior a Cloro Livre.
  - Bloqueio imediato se a leitura do contador for inferior à leitura anterior.
  - Se houver violações graves (vermelho), abre slide-over de confirmação antes de gravar; se tudo estiver conforme, grava diretamente a 1 toque.
