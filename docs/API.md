# API Documentation

Este documento descreve os endpoints públicos da API Piscinas MMCrespo.

> **Base URL:** `https://seu-dominio.com/api` (dev: `http://localhost:8000/api`)

---

## 📊 Health & Status

### GET /api/health

Verifica se a app está online e as dependências funcionam.

**Resposta (200 OK):**
```json
{
  "status": "ok",
  "timestamp": "2026-06-18T15:30:45Z",
  "uptime_seconds": 3600,
  "database": "connected",
  "cache": "connected"
}
```

**Erro (503 Service Unavailable):**
```json
{
  "status": "error",
  "message": "Database connection failed",
  "timestamp": "2026-06-18T15:30:45Z"
}
```

---

## 📈 Metrics

### GET /api/metrics

Métricas operacionais (requer autenticação admin).

**Headers:**
```
Authorization: Bearer {token}
```

**Resposta (200 OK):**
```json
{
  "pools": {
    "total": 5,
    "conforming": 3,
    "non_conforming": 2
  },
  "daily_records": {
    "today": 8,
    "this_week": 42,
    "this_month": 180
  },
  "alerts": {
    "open": 2,
    "in_progress": 1,
    "resolved_today": 5
  },
  "stock": {
    "products": 12,
    "low_items": 3
  },
  "sensor_readings": {
    "last_sync": "2026-06-18T15:20:00Z",
    "devices_active": 3,
    "devices_offline": 1
  }
}
```

---

## 🔐 Authentication

A maioria dos endpoints requer autenticação via **session** (Filament) ou **Bearer token**.

### Session-based (Filament)

1. Login em `/admin`
2. Cookie de sessão (`XSRF-TOKEN`, `laravel_session`) incluído automaticamente
3. Aceder `/api/*` com credenciais válidas

### Token-based (Futuro)

Para integração externa:

```bash
# Futura: gerar token pessoal
POST /api/tokens
Body: { "name": "integration-token" }
Response: { "token": "piscinas_xxx..." }
```

---

## 🏊 Daily Records

### GET /api/daily-records

Lista registos diários (requer role Técnico ou Admin).

**Query Parameters:**
```
?pool_id={id}           # Filtrar por piscina
&start_date={YYYY-MM-DD} # Data início
&end_date={YYYY-MM-DD}   # Data fim
&skip_corrections=true   # Excluir correções (default: false)
&page=1                  # Paginação
&per_page=50
```

**Resposta (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "pool_id": 1,
      "pool": {
        "id": 1,
        "nome": "Competição Leiria",
        "volume_m3": 900
      },
      "registado_por": {
        "id": 1,
        "name": "João Silva",
        "role": "Técnico"
      },
      "ph": 7.4,
      "cloro_total": 0.9,
      "cloro_livre": 0.7,
      "temperatura": 26.5,
      "turbidez_fnu": 0.5,
      "conformidade": "conforme",
      "acao_corretiva": null,
      "registado_em": "2026-06-18T08:00:00Z",
      "criado_em": "2026-06-18T08:05:00Z",
      "atualizado_em": "2026-06-18T08:05:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 150,
    "last_page": 3
  }
}
```

### GET /api/daily-records/{id}

Obter um registo específico.

**Resposta (200 OK):**
```json
{
  "data": {
    "id": 1,
    "pool_id": 1,
    "ph": 7.4,
    "cloro_total": 0.9,
    "acao_corretiva": "Ajustar pH com ácido sulfúrico",
    "correcoes": [
      {
        "id": 2,
        "corrige_registo_id": 1,
        "razao_correcao": "Leitura manual incorrecta",
        "criado_em": "2026-06-18T10:00:00Z"
      }
    ],
    "fotos": [
      {
        "id": 1,
        "tipo": "bomba",
        "url": "/storage/uploads/fotos/bomba_123.jpg"
      }
    ]
  }
}
```

**Erro (404 Not Found):**
```json
{
  "message": "Registo não encontrado"
}
```

---

## 📦 Stock

### GET /api/stock/warehouse

Histórico de transações warehouse (entrada/saída).

**Query Parameters:**
```
?product_id={id}
&type=entrada|saida     # Filtrar tipo
&supplier={nome}
&start_date={YYYY-MM-DD}
&page=1
```

**Resposta (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "product_id": 1,
      "product": {
        "id": 1,
        "nome": "Cloro Gás",
        "unidade": "kg"
      },
      "tipo": "entrada",
      "quantidade": 100,
      "fornecedor": "Fornecedor A",
      "registado_por": {
        "name": "João Silva"
      },
      "registado_em": "2026-06-18T10:00:00Z"
    }
  ],
  "meta": {
    "total": 45,
    "per_page": 20,
    "current_page": 1
  }
}
```

### GET /api/stock/installation

Histórico consumo instalação.

**Query Parameters:**
```
?installation_id={id}
?product_id={id}
&type=entrada|consumo
&start_date={YYYY-MM-DD}
```

**Resposta (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "installation_id": 1,
      "product": {
        "nome": "Cloro Gás",
        "unidade": "kg"
      },
      "tipo": "consumo",
      "quantidade": 2.5,
      "registado_por": {
        "name": "João Silva"
      },
      "registado_em": "2026-06-18T08:30:00Z"
    }
  ]
}
```

---

## 🚨 Alerts & Incidents

### GET /api/alerts

Alertas operacionais (Kanban).

**Resposta (200 OK):**
```json
{
  "data": {
    "para_tratar": [
      {
        "id": 1,
        "tipo": "sem_registo_hoje",
        "pool_id": 1,
        "pool_nome": "Competição Leiria",
        "descricao": "Sem registo há 5h",
        "severidade": "alta",
        "criado_em": "2026-06-18T10:00:00Z"
      }
    ],
    "em_tratamento": [
      {
        "id": 2,
        "tipo": "parametro_fora_limites",
        "pool_id": 2,
        "parametro": "pH",
        "valor": 8.2,
        "limite_max": 8.0,
        "descricao": "pH acima do limite"
      }
    ],
    "resolvido_hoje": [
      {
        "id": 3,
        "tipo": "stock_baixo",
        "produto": "Cloro Gás",
        "resolvido_em": "2026-06-18T14:00:00Z"
      }
    ]
  }
}
```

### GET /api/incidents

Incidentes (abertos ou resolvidos).

**Query Parameters:**
```
?status=aberto|resolvido    # Default: aberto
&pool_id={id}
&start_date={YYYY-MM-DD}
```

**Resposta (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "titulo": "Bomba não inicia",
      "descricao": "Bomba principal Competição não liga",
      "pool_id": 1,
      "status": "aberto",
      "criado_em": "2026-06-18T08:00:00Z",
      "criado_por": { "name": "João Silva" },
      "resolvido_em": null,
      "resolucao": null
    },
    {
      "id": 2,
      "titulo": "Sensor offline",
      "status": "resolvido",
      "resolvido_em": "2026-06-18T10:30:00Z",
      "resolvido_por": { "name": "Admin User" },
      "resolucao": "Sensor reiniciado via Hanna Cloud"
    }
  ]
}
```

---

## 📊 Hanna Sensors

### GET /api/sensors

Leituras de sensores (últimas 24h).

**Resposta (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "device_id": "BL132-001",
      "device_name": "Sonda Competição",
      "pool_id": 1,
      "pool_nome": "Competição Leiria",
      "leitura_ph": 7.4,
      "leitura_orp_mv": 620,
      "leitura_temp": 26.5,
      "lido_em": "2026-06-18T15:20:00Z",
      "idade_minutos": 10,
      "status": "online"
    },
    {
      "id": 2,
      "device_id": "BL132-002",
      "status": "offline",
      "ultima_leitura": "2026-06-18T14:00:00Z",
      "idade_minutos": 80,
      "message": "Sensor não responde há 80 minutos"
    }
  ]
}
```

---

## 🔄 Sync Operations

### POST /api/sync/hanna

Forçar sincronização de sensores Hanna (requer admin).

**Resposta (200 OK):**
```json
{
  "status": "synced",
  "devices_synced": 3,
  "devices_offline": 1,
  "timestamp": "2026-06-18T15:25:00Z"
}
```

---

## ❌ Error Responses

### 400 Bad Request
```json
{
  "message": "Validation failed",
  "errors": {
    "pool_id": ["Pool não encontrada"]
  }
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "message": "Unauthorized. Requer role Técnico ou Admin"
}
```

### 404 Not Found
```json
{
  "message": "Recurso não encontrado"
}
```

### 429 Too Many Requests
```json
{
  "message": "Rate limit exceeded. Tente novamente em 60s"
}
```

### 500 Internal Server Error
```json
{
  "message": "Erro interno do servidor",
  "error_id": "uuid-xxx"
}
```

---

## 📄 Pagination

Endpoints que retornam listas usam paginação:

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8,
    "from": 1,
    "to": 20
  },
  "links": {
    "first": "/api/daily-records?page=1",
    "last": "/api/daily-records?page=8",
    "next": "/api/daily-records?page=2",
    "prev": null
  }
}
```

---

## 🔐 Rate Limiting

- **Por IP:** 60 requests/minuto
- **Por utilizador autenticado:** 300 requests/minuto

Resposta rate-limited:
```
HTTP/1.1 429 Too Many Requests
Retry-After: 45
```

---

## 📝 Notas

1. **Timezone:** Todos os timestamps em **UTC (Z)**. Client faz conversão local.
2. **Encoding:** UTF-8
3. **CORS:** Não configurado por padrão (app é monolítica). Adicionar se precisar frontend externo.
4. **Versioning:** Futura — adicionar `/api/v1/` quando breaking changes ocorram.

---

**Última atualização:** 2026-06-18
