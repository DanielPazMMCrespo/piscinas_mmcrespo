#!/usr/bin/env python3

"""
Advanced Load Test Script — Piscinas MMCrespo
Uses requests + concurrent.futures for more control
Generates HTML report with results

Usage:
  python3 scripts/load-test-advanced.py [URL] [REQUESTS] [CONCURRENCY]
  python3 scripts/load-test-advanced.py https://seu-projeto.railway.app 100 5
"""

import sys
import time
import json
import statistics
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple
from concurrent.futures import ThreadPoolExecutor, as_completed
import urllib.request
import urllib.error
from urllib.parse import urljoin

# ANSI color codes
class Colors:
    RESET = '\033[0m'
    BLUE = '\033[0;34m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'

class LoadTestRunner:
    def __init__(self, base_url: str, requests: int, concurrency: int):
        self.base_url = base_url.rstrip('/')
        self.requests = requests
        self.concurrency = concurrency
        self.timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        self.log_dir = Path("storage/logs/load-tests")
        self.log_dir.mkdir(parents=True, exist_ok=True)

        # Endpoints to test
        self.endpoints = [
            ("/", "GET", "Homepage"),
            ("/admin", "GET", "Dashboard"),
            ("/admin/daily-records", "GET", "Daily Records List"),
            ("/admin/incidents", "GET", "Incidents List"),
        ]

        self.results = {}

    def print_header(self):
        print(f"{Colors.BLUE}{'='*60}{Colors.RESET}")
        print(f"{Colors.GREEN}🔥 Load Test — Piscinas MMCrespo{Colors.RESET}")
        print(f"{Colors.BLUE}{'='*60}{Colors.RESET}")
        print()
        print(f"{Colors.YELLOW}Configuration:{Colors.RESET}")
        print(f"  Base URL: {self.base_url}")
        print(f"  Total Requests: {self.requests}")
        print(f"  Concurrent Workers: {self.concurrency}")
        print(f"  Timestamp: {self.timestamp}")
        print()

    def fetch_url(self, url: str, timeout: int = 30) -> Tuple[float, int, bool]:
        """
        Fetch a URL and return (response_time_ms, status_code, success)
        """
        start = time.time()
        try:
            req = urllib.request.Request(url, method='GET')
            req.add_header('User-Agent', 'Piscinas-MMCrespo-LoadTest/1.0')
            with urllib.request.urlopen(req, timeout=timeout) as response:
                response.read()  # Consume response body
                elapsed = (time.time() - start) * 1000
                return elapsed, response.status, response.status == 200
        except urllib.error.HTTPError as e:
            elapsed = (time.time() - start) * 1000
            return elapsed, e.code, False
        except Exception as e:
            elapsed = (time.time() - start) * 1000
            return elapsed, 0, False

    def test_endpoint(self, endpoint: str) -> List[Dict]:
        """
        Load test a single endpoint with concurrent requests
        """
        url = urljoin(self.base_url, endpoint)
        results = []

        with ThreadPoolExecutor(max_workers=self.concurrency) as executor:
            futures = [
                executor.submit(self.fetch_url, url)
                for _ in range(self.requests)
            ]

            for future in as_completed(futures):
                elapsed, status, success = future.result()
                results.append({
                    'elapsed_ms': elapsed,
                    'status': status,
                    'success': success
                })

        return results

    def analyze_results(self, results: List[Dict]) -> Dict:
        """
        Analyze test results and compute statistics
        """
        times = [r['elapsed_ms'] for r in results]
        successes = [r for r in results if r['success']]

        return {
            'total_requests': len(results),
            'successful': len(successes),
            'failed': len(results) - len(successes),
            'success_rate': (len(successes) / len(results) * 100) if results else 0,
            'requests_per_sec': len(results) / (sum(times) / 1000 + 0.001),
            'min_ms': min(times) if times else 0,
            'max_ms': max(times) if times else 0,
            'mean_ms': statistics.mean(times) if times else 0,
            'median_ms': statistics.median(times) if times else 0,
            'stdev_ms': statistics.stdev(times) if len(times) > 1 else 0,
            'p95_ms': sorted(times)[int(len(times) * 0.95)] if times else 0,
            'p99_ms': sorted(times)[int(len(times) * 0.99)] if times else 0,
        }

    def run(self):
        """
        Execute all load tests
        """
        self.print_header()

        print(f"{Colors.BLUE}Starting load tests...{Colors.RESET}")
        print()

        for endpoint, method, description in self.endpoints:
            self.run_endpoint_test(endpoint, description)

        self.print_summary()

    def run_endpoint_test(self, endpoint: str, description: str):
        """
        Run load test for a single endpoint
        """
        print(f"{Colors.BLUE}{'-'*60}{Colors.RESET}")
        print(f"{Colors.YELLOW}Testing: {description}{Colors.RESET}")
        print(f"  Endpoint: GET {self.base_url}{endpoint}")
        print()

        results = self.test_endpoint(endpoint)
        analysis = self.analyze_results(results)
        self.results[endpoint] = {
            'description': description,
            'raw': results,
            'analysis': analysis
        }

        self.print_results(analysis, endpoint)

    def print_results(self, analysis: Dict, endpoint: str):
        """
        Print formatted results for an endpoint
        """
        a = analysis

        print(f"{Colors.GREEN}✓ Test completed{Colors.RESET}")
        print()
        print(f"{Colors.BLUE}Results:{Colors.RESET}")
        print(f"  Requests/sec:        {a['requests_per_sec']:.2f}")
        print(f"  Success Rate:        {a['success_rate']:.1f}% ({a['successful']}/{a['total_requests']})")
        print(f"  Failed:              {a['failed']}")
        print()
        print(f"  Latency (ms):")
        print(f"    Min:               {a['min_ms']:.2f}")
        print(f"    Mean:              {a['mean_ms']:.2f}")
        print(f"    Median:            {a['median_ms']:.2f}")
        print(f"    Stdev:             {a['stdev_ms']:.2f}")
        print(f"    Max:               {a['max_ms']:.2f}")
        print()
        print(f"  Percentiles (ms):")
        print(f"    P95:               {a['p95_ms']:.2f}")
        print(f"    P99:               {a['p99_ms']:.2f}")

        # Performance assessment
        print()
        self.assess_performance(a)

    def assess_performance(self, analysis: Dict):
        """
        Assess performance against thresholds
        """
        req_per_sec = analysis['requests_per_sec']
        mean_latency = analysis['mean_ms']

        if req_per_sec >= 50:
            print(f"{Colors.GREEN}✓ Requests/sec: Excellent{Colors.RESET}")
        elif req_per_sec >= 10:
            print(f"{Colors.YELLOW}⚠️  Requests/sec: Acceptable{Colors.RESET}")
        else:
            print(f"{Colors.RED}❌ Requests/sec: Needs optimization{Colors.RESET}")

        if mean_latency < 500:
            print(f"{Colors.GREEN}✓ Latency: Excellent{Colors.RESET}")
        elif mean_latency < 1000:
            print(f"{Colors.YELLOW}⚠️  Latency: Acceptable{Colors.RESET}")
        else:
            print(f"{Colors.RED}❌ Latency: Needs optimization{Colors.RESET}")

    def print_summary(self):
        """
        Print overall summary
        """
        print()
        print(f"{Colors.BLUE}{'='*60}{Colors.RESET}")
        print(f"{Colors.GREEN}✓ Load Test Complete{Colors.RESET}")
        print(f"{Colors.BLUE}{'='*60}{Colors.RESET}")
        print()

        print(f"{Colors.YELLOW}Summary:{Colors.RESET}")
        total_req = sum(r['analysis']['total_requests'] for r in self.results.values())
        total_success = sum(r['analysis']['successful'] for r in self.results.values())

        print(f"  Total Requests: {total_req}")
        print(f"  Total Successful: {total_success}")
        print(f"  Overall Success Rate: {(total_success/total_req*100 if total_req > 0 else 0):.1f}%")
        print()

        print(f"{Colors.YELLOW}Endpoint Summary:{Colors.RESET}")
        for endpoint, data in self.results.items():
            a = data['analysis']
            print(f"  {data['description']:30} {a['requests_per_sec']:7.2f} req/s  {a['mean_ms']:7.2f}ms avg")

        print()
        print(f"{Colors.BLUE}Performance Thresholds:{Colors.RESET}")
        print(f"  ✓ Requests/sec >= 50   : Excellent")
        print(f"  ⚠️  Requests/sec 10-49  : Acceptable")
        print(f"  ❌ Requests/sec < 10    : Needs optimization")
        print()
        print(f"  ✓ Latency < 500ms      : Excellent")
        print(f"  ⚠️  Latency 500-1000ms  : Acceptable")
        print(f"  ❌ Latency > 1000ms     : Needs optimization")
        print()

        print(f"{Colors.BLUE}Next Steps:{Colors.RESET}")
        print(f"  1. Check database performance (index analysis)")
        print(f"  2. Review slow query log in storage/logs")
        print(f"  3. Enable HTTP caching headers in responses")
        print(f"  4. Consider Redis for session/cache store")
        print(f"  5. Profile with: php artisan tinker --execute")
        print()

if __name__ == "__main__":
    url = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8000"
    requests = int(sys.argv[2]) if len(sys.argv) > 2 else 100
    concurrency = int(sys.argv[3]) if len(sys.argv) > 3 else 5

    runner = LoadTestRunner(url, requests, concurrency)
    runner.run()
