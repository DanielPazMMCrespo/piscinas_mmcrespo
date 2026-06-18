#!/bin/bash

# Load Test Script — Piscinas MMCrespo
# Validates performance using Apache Bench (ab) or wrk
# Usage: bash scripts/load-test.sh [URL] [REQUESTS] [CONCURRENCY]
# Example: bash scripts/load-test.sh https://seu-projeto.railway.app 100 5

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Defaults
URL="${1:-http://localhost:8000}"
REQUESTS="${2:-100}"
CONCURRENCY="${3:-5}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="storage/logs/load-tests"

# Ensure log directory exists
mkdir -p "$LOG_DIR"

echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}🔥 Load Test — Piscinas MMCrespo${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}Configuration:${NC}"
echo "  Base URL: $URL"
echo "  Requests: $REQUESTS"
echo "  Concurrency: $CONCURRENCY"
echo "  Timestamp: $TIMESTAMP"
echo ""

# Check if ab is installed
if ! command -v ab &> /dev/null; then
    echo -e "${RED}❌ Apache Bench (ab) not found!${NC}"
    echo "   Install: apt-get install apache2-utils (Linux) or brew install httpd (macOS)"
    exit 1
fi

# Function to run test and extract metrics
run_load_test() {
    local endpoint=$1
    local method=$2
    local description=$3
    local output_file="$LOG_DIR/${TIMESTAMP}_$(basename $endpoint | tr '/' '_').txt"

    echo -e "${BLUE}─────────────────────────────────────────────────────────${NC}"
    echo -e "${YELLOW}Testing: $description${NC}"
    echo "  Endpoint: $method $URL$endpoint"
    echo ""

    # Run Apache Bench
    if [ "$method" = "POST" ]; then
        # POST requests require data, use GET as fallback for this test
        echo -e "${YELLOW}(Running as GET for load simulation)${NC}"
        ab -n "$REQUESTS" -c "$CONCURRENCY" -t 60 "$URL$endpoint" > "$output_file" 2>&1
    else
        ab -n "$REQUESTS" -c "$CONCURRENCY" -t 60 "$URL$endpoint" > "$output_file" 2>&1
    fi

    # Extract key metrics
    echo ""
    if grep -q "Requests per second" "$output_file"; then
        echo -e "${GREEN}✓ Test completed${NC}"
        echo ""
        echo -e "${BLUE}Results:${NC}"

        # Extract and format metrics
        local req_per_sec=$(grep "Requests per second" "$output_file" | awk '{print $4}')
        local time_per_req=$(grep "Time per request" "$output_file" | head -1 | awk '{print $4}')
        local time_per_req_mean=$(grep "Time per request" "$output_file" | tail -1 | awk '{print $4}')
        local failed=$(grep "Failed requests" "$output_file" | awk '{print $3}')
        local p50=$(grep "50%" "$output_file" | awk '{print $2}' || echo "N/A")
        local p95=$(grep "95%" "$output_file" | awk '{print $2}' || echo "N/A")
        local p99=$(grep "99%" "$output_file" | awk '{print $2}' || echo "N/A")

        echo "  Requests/sec:        $req_per_sec"
        echo "  Time per request:    ${time_per_req} ms"
        echo "  Time/req (avg):      ${time_per_req_mean} ms"
        echo "  Failed requests:     $failed"

        if [ -n "$p50" ] && [ "$p50" != "N/A" ]; then
            echo "  Latency P50:         ${p50} ms"
            echo "  Latency P95:         ${p95} ms"
            echo "  Latency P99:         ${p99} ms"
        fi

        # Performance assessment
        echo ""
        if (( $(echo "$req_per_sec < 10" | bc -l) )); then
            echo -e "${RED}⚠️  Performance Alert: Requests/sec < 10${NC}"
            echo "    Consider: caching, database optimization, or load balancing"
        elif (( $(echo "$req_per_sec >= 50" | bc -l) )); then
            echo -e "${GREEN}✓ Performance: Excellent${NC}"
        else
            echo -e "${YELLOW}⚠️  Performance: Acceptable${NC}"
        fi

        if (( $(echo "$time_per_req_mean > 1000" | bc -l) )); then
            echo -e "${RED}⚠️  Latency Alert: Time per request > 1s${NC}"
            echo "    Cache may be slow or queries need optimization"
        fi

        echo ""
    else
        echo -e "${RED}❌ Test failed or server unreachable${NC}"
        echo "    Check if the server is running at: $URL"
        tail -20 "$output_file"
    fi

    echo "  Full log: $output_file"
}

# Run tests for each endpoint
echo -e "${BLUE}Starting load tests...${NC}"
echo ""

run_load_test "/" "GET" "Homepage"
run_load_test "/admin" "GET" "Dashboard"
run_load_test "/admin/daily-records" "GET" "Daily Records List"
run_load_test "/admin/incidents" "GET" "Incidents List"

# Summary
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✓ Load Test Complete${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}Summary:${NC}"
echo "  All logs saved to: $LOG_DIR"
echo "  View results: cat $LOG_DIR/*.txt"
echo ""
echo -e "${BLUE}Performance Thresholds:${NC}"
echo "  ✓ Requests/sec >= 50   : Excellent"
echo "  ⚠️  Requests/sec 10-49  : Acceptable"
echo "  ❌ Requests/sec < 10    : Needs optimization"
echo ""
echo "  ✓ Latency < 500ms      : Excellent"
echo "  ⚠️  Latency 500-1000ms  : Acceptable"
echo "  ❌ Latency > 1000ms     : Needs optimization"
echo ""
echo -e "${BLUE}Next Steps:${NC}"
echo "  1. Review logs: $LOG_DIR"
echo "  2. Check failed requests and errors"
echo "  3. Monitor database query times"
echo "  4. Consider enabling HTTP caching headers"
echo "  5. Review Laravel config/cache settings"
echo ""
