#!/bin/bash

# 🚀 SCRIPT: Migração SQLite → PostgreSQL
# Uso: bash scripts/migrate-to-postgres.sh

set -e

echo "═══════════════════════════════════════════════════════════════"
echo "🗄️  MIGRAÇÃO: SQLite → PostgreSQL"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# 1. Backup SQLite
echo "1️⃣ Fazendo backup do SQLite..."
cp database/database.sqlite database/database.sqlite.backup.$(date +%s)
echo "   ✅ Backup criado: database/database.sqlite.backup.*"
echo ""

# 2. Verificar variáveis de ambiente PostgreSQL
echo "2️⃣ Verificando configuração PostgreSQL..."
if [ -z "$DB_HOST" ]; then
    echo "   ⚠️  DB_HOST não definido. Usando localhost"
    DB_HOST="localhost"
fi
if [ -z "$DB_PORT" ]; then
    DB_PORT="5432"
fi
if [ -z "$DB_DATABASE" ]; then
    echo "   ❌ DB_DATABASE não definido em .env"
    exit 1
fi

echo "   Configuração:"
echo "   - Host: $DB_HOST:$DB_PORT"
echo "   - Database: $DB_DATABASE"
echo "   - User: $DB_USERNAME"
echo ""

# 3. Criar database se não existir
echo "3️⃣ Criando database PostgreSQL..."
PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -U $DB_USERNAME -tc \
    "SELECT 1 FROM pg_database WHERE datname = '$DB_DATABASE'" | grep -q 1 || \
    PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -U $DB_USERNAME -c \
    "CREATE DATABASE $DB_DATABASE;"
echo "   ✅ Database pronto"
echo ""

# 4. Rodar migrations (incluindo otimizações PostgreSQL)
echo "4️⃣ Rodando migrations..."
php artisan migrate --force
echo "   ✅ Migrations completas (com otimizações PostgreSQL)"
echo ""

# 5. Rodar seeders (opcional)
echo "5️⃣ Deseja rodar seeders? (s/n)"
read -r response
if [[ "$response" == "s" || "$response" == "S" ]]; then
    php artisan db:seed
    echo "   ✅ Seeders executados"
else
    echo "   ⏭️ Seeders pulados"
fi
echo ""

# 6. Testes básicos
echo "6️⃣ Testando conectividade..."
php artisan tinker <<'EOFPHP'
try {
    $users = \App\Models\User::count();
    echo "   ✅ Database conectado\n";
    echo "   ✅ Users na BD: $users\n";
} catch (Exception $e) {
    echo "   ❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
EOFPHP
echo ""

# 7. Cache clear
echo "7️⃣ Limpando cache..."
php artisan config:clear
php artisan cache:clear
echo "   ✅ Cache limpo"
echo ""

echo "═══════════════════════════════════════════════════════════════"
echo "✅ MIGRAÇÃO COMPLETA!"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "📝 Próximos passos:"
echo "   1. Testar app localmente: php artisan serve"
echo "   2. Verificar dados em http://localhost:8000"
echo "   3. Se OK, fazer deploy para Railway"
echo ""
