#!/bin/bash

# Verifica headers OWASP em produção ou staging
# Uso: ./scripts/verify-security-headers.sh https://seu-dominio.com

set -e

if [ -z "$1" ]; then
    echo "Uso: $0 <URL>"
    echo "Exemplo: $0 https://piscinas.mmcrespo.pt"
    exit 1
fi

URL="$1"
ENDPOINT="${URL}/admin"

echo "=== Verificando Security Headers ==="
echo "URL: $ENDPOINT"
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Headers obrigatórios
declare -A REQUIRED_HEADERS=(
    ["Content-Security-Policy"]="frame-ancestors 'none'"
    ["Strict-Transport-Security"]="max-age="
    ["X-Frame-Options"]="DENY"
    ["X-Content-Type-Options"]="nosniff"
    ["Referrer-Policy"]="strict-origin-when-cross-origin"
    ["Permissions-Policy"]="camera=()"
)

# Fetch headers
HEADERS=$(curl -s -I "$ENDPOINT")

PASS=0
FAIL=0

for header in "${!REQUIRED_HEADERS[@]}"; do
    if echo "$HEADERS" | grep -q "^$header:"; then
        VALUE=$(echo "$HEADERS" | grep "^$header:" | cut -d' ' -f2-)

        # Verificar valor esperado
        EXPECTED="${REQUIRED_HEADERS[$header]}"
        if echo "$VALUE" | grep -q "$EXPECTED"; then
            echo -e "${GREEN}✓${NC} $header"
            echo "  Valor: $VALUE"
            ((PASS++))
        else
            echo -e "${YELLOW}⚠${NC} $header (valor inesperado)"
            echo "  Esperado: $EXPECTED"
            echo "  Recebido: $VALUE"
            ((FAIL++))
        fi
    else
        echo -e "${RED}✗${NC} $header FALTA"
        ((FAIL++))
    fi
    echo ""
done

echo "=== Resumo ==="
echo -e "Passou: ${GREEN}$PASS${NC}"
echo -e "Falhou: ${RED}$FAIL${NC}"

if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}Status: 10/10 - Segurança OK${NC}"
    exit 0
else
    echo -e "${RED}Status: FALHAS DETECTADAS${NC}"
    exit 1
fi
