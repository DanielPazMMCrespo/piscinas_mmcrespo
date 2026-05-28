# Contexto Completo — Projeto Piscinas MMCrespo

> **Como usar:** Cola este ficheiro inteiro no início de qualquer conversa nova com qualquer IA.
> Última atualização: 2026-05-27

---

## Quem sou e como quero ser tratado

Sou um programador junior, recém-entrado no mundo do trabalho, sem licenciatura em engenharia informática concluída. Trabalho sozinho, 4-5h/dia, com auxílio de IAs. Programo com Claude ou equivalente como colaborador técnico.

**Regras de comunicação:**
- Trata-me como profissional. Não expliques fundamentos que não pedi.
- Vai direto à resposta. Contexto e raciocínio a seguir, nunca antes.
- Sem bullet points desnecessários — usa prosa quando faz sentido.
- Se eu estiver errado, diz diretamente. Não suavizes.
- Se houver uma abordagem melhor, diz uma vez e faz o que pedi.
- Sem em-dashes. Sem voz passiva. Sem linguagem de cobertura.
- Output técnico: código funcional primeiro, explicação depois se necessário.

---

## O Projeto

**Nome:** Piscinas MMCrespo  
**Tipo:** Aplicação web de gestão operacional para piscinas municipais sob contrato da empresa MMCrespo.  
**Contexto legal:** Projeto público, visibilidade mediática se falhar. Cumprimento obrigatório de CN 14/DA (DGS 2009), NP 4542:2017, DR 5/97.

### Instalações e piscinas (5 no total)

| Instalação | Piscinas | Volume |
|---|---|---|
| Leiria | Competição (25x17.4m, 2m prof.) | 900 m³ |
| Leiria | Lazer (25x17.4m, 1.1m prof.) | 600 m³ |
| Leiria | Infantil (17.4x5m, 0.3-1.2m prof.) | 50 m³ |
| Maceira | Maceira (16.6x10m) | 170 m³ |
| Caranguejeira | Caranguejeira (16.6x10m) | 170 m³ |

Temperaturas: Competição 26-27°C, Lazer/Infantil/Maceira/Caranguejeira 28-30°C.

---

## Stack Técnica (decisão final atualizada)

- **Framework:** Laravel 12 LTS (Downgrade do 13 devido a problemas de dependências openspout no PHP 8.5)
- **Admin/UI:** Filament 3.3.x
- **Base de dados:** PostgreSQL local (piscinas_mmcrespo)
- **Roles:** spatie/laravel-permission configurado nos modelos e recursos
- **PDF:** barryvdh/laravel-dompdf (a implementar)
- **AI:** Gemini / Claude API — OCR de fotos + reconhecimento de filtros
- **Offline:** Auto-save de rascunhos em localStorage (PWA completo é Fase 2)
- **Charts:** ApexCharts (integração nativa Filament)

---

## Roles e Permissões

| Role | Permissões |
|---|---|
| **Admin** | Acesso total a Estrutura, Inventário, Sistema e Operação. (Login: admin@mmcrespo.pt / password) |
| **Técnico** | Acesso a Inventário e Operação. Regista stocks e incidentes. (Login: tecnico@mmcrespo.pt / password) |
| **Nadador Salvador (NS)** | Acesso apenas a Operação. Cria registos diários das suas piscinas. (Login: ns@mmcrespo.pt / password) |

---

## Modelo de Dados (Implementado e com Seeders Reais)

- `installations`, `pools`, `users`
- `daily_records`, `record_additions`, `record_photos`
- `filter_checks`, `incidents`, `incident_pools`, `incident_products`
- `products`, `stock_warehouse`, `stock_warehouse_logs`
- `stock_installations`, `stock_installation_logs`

---

## Estado Atual do Projeto

O projeto encontra-se estabilizado, alojado em `C:\dev\piscinas_mmcrespo-main` e versionado em `https://github.com/DanielPazMMCrespo/piscinas_mmcrespo`.

| Plano | Estado | Descrição |
|---|---|---|
| Plano 1 — Fundações | **CONCLUÍDO** | Instalação do Laravel 12, PostgreSQL (SQLite local para dev), 14 Migrações, 14 Models, Seeders (roles, utilizadores, instalações, piscinas, químicos) e testes unitários. |
| Plano 2 — Interface Administrativa (Filament) | **CONCLUÍDO** | Todos os Recursos (Resources) gerados e divididos em 4 Navigation Groups. Permissões aplicadas por role. Formulários estruturados com secções. Ecrãs validados por automação de browser. **Bugs de integridade de dados (user_id desidratado) e controlo de acesso ao painel resolvidos.** |
| Plano 3 — Dashboards e Analytics | **A INICIAR NA PRÓXIMA SESSÃO** | Construção da HomePage (Dashboard vazio atualmente). Incluir avisos de stock baixo, gráficos de cloro/pH e contadores de incidentes em aberto. |
| Plano 4 — Inteligência Artificial | Pendente | OCR das fotos e análise de filtros através da API. |
| Plano 5 — Relatórios PDF (CN 14/DA) | Pendente | Construção dos relatórios formatados de forma regulamentar para download. |

**Próximo passo imediato (para amanhã):** Iniciar o **Plano 3 — Dashboards e Analytics**.

---

## UX Não Negociável
- Ecrã de confirmação obrigatório antes de qualquer submit.
- Botão submit desativa após primeiro toque.
- Validação dura em tempo real (não deixa submeter valores fora do limite sem justificação de correção).

## Validação Regulamentar (não esquecer)
Antes de produção: obter parecer escrito da USP do ACES Pinhal Litoral a aceitar o formato digital do livro de registo sanitário. Manter livro em papel em paralelo no primeiro ano.
