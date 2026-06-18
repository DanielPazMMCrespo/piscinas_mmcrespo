# ADR 0002: Append-Only Pattern para Registos

**Data:** 2026-06-10  
**Decisor:** Daniel Paz  
**Status:** ACCEPTED  

---

## 🎯 Contexto

Piscinas MMCrespo coleta dados críticos de conformidade (pH, cloro, etc) regulados por **CN 14/DA (DGS 2009)**.

### Requisito Regulatório

> "Qualquer correção ou ajuste a um registo de conformidade deve ser **auditável** e **rastreável**."

### Problema

Quando um técnico "edita" um registo (ex: pH digitado errado), a tentação é sobrescrever. Mas isto:

1. **Perde o histórico** — não sabemos qual era o valor original
2. **Compromete auditoria** — não cumpre CN 14/DA
3. **Impede análise** — gráficos ficam inconsistentes se dados mudam retroativamente

### Exemplo Negativo

```
❌ Registo Original:
  pH = 6.5 (FORA DO LIMITE) → Flag como não-conforme

❌ Técnico edita:
  pH = 7.4 (CONFORME)
  → Banco só tem nova versão
  → Auditoria perdida, gráfico mudou
```

---

## 🏗️ Decisão

**Implementar Append-Only Pattern:**

Quando um técnico precisa corrigir um registo, **criar um novo registo** com:
- `e_correcao = true` (flag de correção)
- `corrige_registo_id = {id original}`
- `razao_correcao = "Leitura manual incorrecta"` (explicação)

O registo original fica intacto para auditoria. Análises usam `whereDoesntHave('correcoes')` para ignorar registos substituídos.

### Schema

```sql
ALTER TABLE daily_records ADD COLUMN e_correcao BOOLEAN DEFAULT false;
ALTER TABLE daily_records ADD COLUMN corrige_registo_id BIGINT UNSIGNED;
ALTER TABLE daily_records ADD COLUMN razao_correcao TEXT;
ALTER TABLE daily_records ADD FOREIGN KEY (corrige_registo_id) REFERENCES daily_records(id);
```

### Modelo

```php
class DailyRecord extends Model
{
    public function correcoes()
    {
        return $this->hasMany(self::class, 'corrige_registo_id');
    }

    public function corrigeRegisto()
    {
        return $this->belongsTo(self::class, 'corrige_registo_id');
    }
}
```

---

## 📋 Consequências

### ✅ Positivas

1. **Auditoria completa** — Todos os valores históricos preservados
2. **Compliance regulatório** — CN 14/DA satisfeito
3. **Análises confiáveis** — Gráficos mostram histórico real
4. **Rastreabilidade** — Podemos mostrar "corrigido em X data por Y utilizador"
5. **Sem perda de dados** — Original sempre recuperável

### ⚠️ Negativas

1. **Tabela cresce** — Cada correção = nova linha (mitigado por `whereDoesntHave`)
2. **UI mais complexa** — Formulário precisa de "Razão de correção"
3. **Queries mais lentas** — Precisa de `whereDoesntHave('correcoes')` ou índices
4. **Mental model** — Técnicos precisam entender que "edit" = novo registo

### 🔧 Mitigações

| Risco | Mitigation |
|-------|-----------|
| **Tabela grande** | Índice em `e_correcao` + `whereDoesntHave` |
| **Queries lentas** | Índice parcial: `WHERE e_correcao = false` |
| **UI confusa** | Mostrar badge "Corrigido" em registo original + link novo |
| **Utilizador não entende** | Treino: "Editing" = novo registo com razão |

---

## 🚀 Implementação

### 1. Migração

```php
Schema::table('daily_records', function (Blueprint $table) {
    if (!Schema::hasColumn('daily_records', 'e_correcao')) {
        $table->boolean('e_correcao')->default(false)->index();
        $table->unsignedBigInteger('corrige_registo_id')->nullable();
        $table->text('razao_correcao')->nullable();
        
        $table->foreign('corrige_registo_id')
              ->references('id')
              ->on('daily_records')
              ->onDelete('restrict');  // Nunca apagar!
    }
});
```

### 2. Formulário (Filament)

```php
// Em DailyRecordResource (edit):
Section::make('Correção (se aplicável)')
    ->collapsed()
    ->schema([
        Checkbox::make('e_correcao')
            ->label('Esta é uma correção a um registo anterior'),
        
        Select::make('corrige_registo_id')
            ->label('Registo a corrigir')
            ->relationship('corrigeRegisto', 'id')
            ->visible(fn ($get) => $get('e_correcao'))
            ->required(fn ($get) => $get('e_correcao')),
        
        Textarea::make('razao_correcao')
            ->label('Motivo da correção')
            ->visible(fn ($get) => $get('e_correcao'))
            ->required(fn ($get) => $get('e_correcao')),
    ])
```

### 3. Gráficos & Análises

```php
// Todos os gráficos usam:
DailyRecord::whereDoesntHave('correcoes')
            ->where('pool_id', $poolId)
            ->get()
```

### 4. UI Feedback

```php
// Em DailyRecordResource (table):
Columns\TextColumn::make('id')
    ->formatStateUsing(function ($state, DailyRecord $record) {
        if ($record->e_correcao) {
            return "{$state} (CORREÇÃO)";
        }
        if ($record->correcoes()->exists()) {
            return "{$state} [CORRIGIDO]";
        }
        return $state;
    })
```

---

## 📊 Exemplos Práticos

### Cenário 1: Erro de Leitura

```
Time: 10:00 (seg)
Técnico regista: pH = 6.5 (FORA DO LIMITE)
  → Sistema cria alerta

Time: 10:05
Técnico percebe: "Ooops, li mal o sensor"
  → Cria NOVO registo com e_correcao=true
  → corrige_registo_id = 123
  → razao_correcao = "Leitura manual incorrecta"
  → pH = 7.4

Resultado:
  ✅ Registo original (id=123) fica, marcado [CORRIGIDO]
  ✅ Novo registo (id=456) é a "versão real"
  ✅ Gráfico usa whereDoesntHave('correcoes') → mostra apenas registo "456" com pH 7.4
  ✅ Auditoria mostra: registo 123 → 456
```

### Cenário 2: Ação Corretiva Incompleta

```
Time: 10:00
Registo criado: cloro_livre = 0.1 (BAIXO)
  → Sistema gera alerta + notificação admin
  → Ação corretiva = "Adicionar 2kg cloro"

Time: 14:00
Técnico segue up: confirmou que cloro foi adicionado
  → Cria NOVO registo com e_correcao=true
  → razao_correcao = "Ação corretiva confirmada. Cloro adicionado."
  → cloro_livre = 0.9 (CONFORME)

Resultado:
  ✅ Histórico completo: problema → ação → confirmação
  ✅ Kanban vê correção, auto-resolve alerta
```

---

## ❌ Alternativas Consideradas

### Alternativa 1: Soft Delete (rejected)
```php
// ❌ Muda data_apagado em lugar de criar novo registo
$record->update(['data_apagado' => now()]);
```
- Problema: Não explica PORQUE foi apagado
- Problema: Análises precisam de lógica adicional

### Alternativa 2: In-place Update (rejected)
```php
// ❌ Sobrescrever original
$record->update(['ph' => 7.4]);
```
- ❌ Perde histórico completamente
- ❌ Não cumpre regulação CN 14/DA

### Alternativa 3: Versioning (considered)
```php
// ✓ Funciona, mas overkill:
// daily_records_versions tabela + trigger
```
- Problema: Mais complexo, sem ganho real
- Chosen: Append-only é mais simples + seguro

---

## ✅ Validação

### Pre-Production Checklist

- [x] Migração idempotente (funciona SQLite + PostgreSQL)
- [x] Foreign key constraint ativa
- [x] Índices criados (e_correcao, corrige_registo_id)
- [x] UI mostra badges "CORRIGIDO"
- [x] Gráficos usam whereDoesntHave
- [x] Testes unitários para padrão
- [x] Documentação ao utilizador

### Production Validation

```bash
# Verificar integridade:
psql $DATABASE_URL -c "SELECT COUNT(*) as total FROM daily_records;"
psql $DATABASE_URL -c "SELECT COUNT(*) as corrections FROM daily_records WHERE e_correcao = true;"

# Validar foreign keys:
psql $DATABASE_URL -c "SELECT COUNT(*) FROM daily_records WHERE corrige_registo_id IS NOT NULL;"
```

---

## 📚 Referências

- [Event Sourcing Pattern](https://martinfowler.com/eaaDev/EventSourcing.html)
- [Audit Trail Best Practices](https://wiki.postgresql.org/wiki/Audit_trigger_function)
- [CN 14/DA Compliance](https://www.dgs.pt/)

---

## 🔄 Impacto nos Requeirements

| Requisito | Impacto |
|-----------|--------|
| **CN 14/DA compliance** | ✅ Melhorado (auditoria completa) |
| **Performance** | ⚠️ Mínimo (índices mitigam) |
| **Escalabilidade** | ✅ Sem impacto |
| **Backup** | ✅ Sem mudança |

---

**Decidido por:** Daniel Paz  
**Data:** 2026-06-10  
**Effective:** 2026-06-10 (implementado em sessão 7)
