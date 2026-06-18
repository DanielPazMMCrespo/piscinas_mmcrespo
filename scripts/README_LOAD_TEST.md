# Load Test Scripts

Dois scripts para validar performance da aplicação antes e após deployment.

## 📝 Scripts

### 1. `load-test.sh` (Apache Bench)
**Bash script com Apache Bench (ab)**
- Testa 4 endpoints principais
- Extrai métricas: Requests/sec, latência, failed requests
- Gera logs detalhados em `storage/logs/load-tests/`
- **Requires:** Apache Bench (`ab` command)

```bash
# Uso básico
bash load-test.sh http://localhost:8000 100 5

# Uso em produção
bash load-test.sh https://seu-projeto.railway.app 100 5
```

### 2. `load-test-advanced.py` (Python)
**Python script com estatísticas detalhadas**
- Testa endpoints com ThreadPoolExecutor
- Estatísticas completas: min, max, mean, stdev, P95, P99
- Avaliação automática de performance
- **Requires:** Python 3.6+ (sem dependências externas)

```bash
# Uso básico
python3 load-test-advanced.py http://localhost:8000 100 5
```

## 🚀 Uso Rápido

**Desenvolvimento (localhost):**
```bash
bash load-test.sh
```

**Produção (Railway):**
```bash
bash load-test.sh https://seu-projeto.railway.app 100 10
```

**Alternativa com Python:**
```bash
python3 load-test-advanced.py https://seu-projeto.railway.app 100 10
```

## 📊 Parâmetros

| Posição | Nome | Default | Descrição |
|---------|------|---------|-----------|
| 1 | URL | http://localhost:8000 | Base URL da aplicação |
| 2 | REQUESTS | 100 | Total de requisições por endpoint |
| 3 | CONCURRENCY | 5 | Workers paralelos |

## ✅ Endpoints Testados

- `GET /` → Homepage
- `GET /admin` → Dashboard
- `GET /admin/daily-records` → Lista de registos
- `GET /admin/incidents` → Lista de incidentes

## 📈 Metas de Performance

| Métrica | Target | Aceitável | Crítico |
|---------|--------|-----------|---------|
| Requests/sec | ≥ 50 | 10-49 | < 10 |
| Latência média | < 500ms | 500-1000ms | > 1000ms |
| P95 Latency | < 1000ms | 1-2s | > 2s |
| Sucesso | 100% | ≥ 99% | < 99% |

## 📁 Logs

Salvos em: `storage/logs/load-tests/`

```bash
# Ver últimos testes
ls -ltr storage/logs/load-tests/ | tail -5

# Analisar resultado
cat storage/logs/load-tests/YYYYMMDD_HHMMSS_*.txt
```

## 🔧 Instalação (Apache Bench)

**Linux:**
```bash
sudo apt-get install apache2-utils
```

**macOS:**
```bash
brew install httpd
```

**Windows (WSL):**
```bash
wsl sudo apt-get install apache2-utils
```

Se não conseguir instalar `ab`, use o script Python (sem dependências).

## 📚 Referência Completa

Ver `../LOAD_TEST_GUIDE.md` para guia detalhado com interpretação de resultados, otimizações, e troubleshooting.

## 🎯 Checklist Pré-Deployment

- [ ] Rodar `bash load-test.sh http://localhost:8000 100 5`
- [ ] Confirmar Requests/sec ≥ 50
- [ ] Confirmar latência média < 500ms
- [ ] Revistar logs em `storage/logs/load-tests/`
- [ ] Rodar `php artisan optimize` antes de deploy
- [ ] Testar em staging se disponível

## 💡 Dicas

1. **Primeira execução:** sempre mais lenta (PHP JIT compilation)
2. **Localhost vs Remote:** produção será 2-3x mais rápida com cache HTTP + CDN
3. **Estatísticas:** correr 2-3 testes e comparar média para evitar outliers
4. **Otimizações:** verificar `storage/logs/laravel.log` para queries lentas
