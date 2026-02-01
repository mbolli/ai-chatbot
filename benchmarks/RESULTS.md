# Benchmark Results: Next.js vs PHP/Swoole

**Date:** January 30, 2026

## Production URLs

| App | URL | Hosting |
|-----|-----|---------|
| **PHP/Swoole** | https://chat.zweiundeins.gmbh | $20/year VPS (2 vCPU, 4GB RAM) |
| **Next.js** | https://demo.chat-sdk.dev/ | Vercel (serverless) |

> **Context:** The [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) has **86 contributors** and **600+ commits** of optimization. The PHP port is a straightforward implementation with minimal optimization.

---

## ✅ Completed Benchmarks

### 1. Lighthouse (Desktop, Chrome 144)

Tested from Windows desktop via Chrome DevTools.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 92 | 🏆 PHP |
| **First Contentful Paint** | 0.7s | **0.3s** | Next.js |
| **Largest Contentful Paint** | **0.7s** | 1.6s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 130ms | 🏆 PHP |
| **Time to Interactive** | **0.7s** | 1.6s | 🏆 PHP |
| **Cumulative Layout Shift** | 0.015 | **0** | Next.js |
| **Speed Index** | 0.7s | **0.4s** | Next.js |

**Analysis:**
- PHP wins on interactivity metrics (LCP, TBT, TTI) — server-rendered HTML is immediately usable
- Next.js wins on initial paint (FCP, SI) — edge CDN delivers shell faster, but hydration delays interactivity

### 2. Resource Transfer Size (from Lighthouse)

| Resource | PHP/Swoole | Next.js | Ratio |
|----------|------------|---------|-------|
| **Total** | **230 KB** | 1.26 MB | 5.5x |
| **JavaScript** | **28 KB** | 1.09 MB | **39x** |
| **CSS** | 40 KB | 27 KB | — |
| **HTTP Requests** | **17** | 39 | 2.3x |

### 3. TTFB (curl, 5 runs)

| Site | Warm | Cold Start |
|------|------|------------|
| **PHP** | ~149ms | N/A (persistent process) |
| **Next.js** | ~170ms | **1.85s** (serverless cold start) |

### 4. Codebase Size

**Production (no dev dependencies):**

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **Dependencies (installed)** | **69** | 799 | **11.6x** |
| **Disk size (vendor/node_modules)** | **25 MB** | 793 MB | **31.7x** |

**With dev dependencies:**

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **Dependencies (installed)** | **140** | 921 | 6.6x |
| **Disk size (vendor/node_modules)** | **76 MB** | 986 MB | 13x |
| **Direct dependencies** | 32 | 97 | 3x |

### 5. Load Test (k6, PHP only)

> **Note:** Next.js (`demo.chat-sdk.dev`) returned HTTP 429 (rate limited) during load testing, so only PHP results are available.

**PHP/Swoole with 1 Swoole worker:**

| Metric | Localhost | Production VPS |
|--------|-----------|----------------|
| **Throughput** | **~200 req/s** | **~44 req/s** |
| **Response Time (avg)** | **7.25ms** | 225ms |
| **Response Time (p95)** | **9.26ms** | 385ms |
| **Error Rate** | 0% | 0% |

**Hardware comparison:**

| | Dev Machine | Production VPS |
|--|-------------|----------------|
| **CPU** | AMD Ryzen 7 Pro 7840U (8c/16t) | 2x AMD EPYC 7763 vCPU |
| **RAM** | 64GB DDR5 | 3.8GB |
| **Cost** | ~$1,500 laptop | **$20/year** |

**Production timing breakdown (curl):**

| Step | Time |
|------|------|
| DNS Lookup | ~110ms |
| TCP + TLS | ~53ms |
| **Server Processing** | **~65ms** |
| Total | ~230ms |

> A single Swoole worker handles **44 req/s** on a $20/year VPS thanks to coroutine concurrency.

---

## ❌ Not Tested

| Test | Reason |
|------|--------|
| **Next.js load testing (k6)** | Vercel rate limit (HTTP 429) |
| **AI streaming TTFT** | Requires API key + auth on both systems |
| **Memory profiling** | No system access to Vercel serverless |
| **SSE overhead** | Needs coordinated server-side instrumentation |
| **Authenticated endpoints** | Different auth systems, not comparable |
| **Mobile (4G slow/3G)** | TODO: Add throttled Lighthouse tests |

> A fair comparison of these metrics would require self-hosted instances of both applications on identical hardware.

---

## Reproduce These Results

### Lighthouse (Desktop)
```bash
# Run from Chrome DevTools > Lighthouse tab, or:
npx lighthouse https://chat.zweiundeins.gmbh/ --output=json
npx lighthouse https://demo.chat-sdk.dev/ --output=json
```

### Lighthouse (Mobile with Slow 4G)
```bash
# Simulate slow 4G network conditions (1.6 Mbps, 150ms RTT)
npx lighthouse https://chat.zweiundeins.gmbh/ --preset=desktop --throttling.cpuSlowdownMultiplier=4 --throttling.throughputKbps=1600 --throttling.rttMs=150 --output=json
npx lighthouse https://demo.chat-sdk.dev/ --preset=desktop --throttling.cpuSlowdownMultiplier=4 --throttling.throughputKbps=1600 --throttling.rttMs=150 --output=json

# Or use mobile preset (includes 4G throttling by default)
npx lighthouse https://chat.zweiundeins.gmbh/ --preset=perf --output=json
```

### TTFB
```bash
# 5 runs each
for i in (seq 5); curl -s -o /dev/null -w "%{time_starttransfer}s\n" https://chat.zweiundeins.gmbh/; end
for i in (seq 5); curl -s -o /dev/null -w "%{time_starttransfer}s\n" -L https://demo.chat-sdk.dev/; end
```

### Codebase Size (Production)
```bash
# Production builds (no dev dependencies) - more accurate comparison
# PHP
composer install --no-dev --optimize-autoloader
du -sh vendor                    # PHP: ~50M (production)
composer show | wc -l            # PHP: ~90 packages

# Next.js  
pnpm install --prod
du -sh ai-chatbot/node_modules   # Next.js: ~500M (production, estimate)

# Note: Original benchmarks used dev dependencies which inflates both numbers
```

### k6 Load Test
```bash
echo "
import http from 'k6/http';
import { check } from 'k6';

export const options = { vus: 10, duration: '15s' };

export default function () {
  const res = http.get('https://chat.zweiundeins.gmbh/');
  check(res, { 'status is 200': (r) => r.status === 200 });
}
" | k6 run -
```

### Timing Breakdown
```bash
curl -s -o /dev/null -w "DNS: %{time_namelookup}s\nTCP: %{time_connect}s\nTLS: %{time_appconnect}s\nTTFB: %{time_starttransfer}s\nTotal: %{time_total}s\n" https://chat.zweiundeins.gmbh/
```

---

## Summary

| Category | Winner | Key Metric |
|----------|--------|------------|
| **Performance Score** | 🏆 PHP | 100 vs 92 |
| **Time to Interactive** | 🏆 PHP | 0.7s vs 1.6s |
| **JavaScript Size** | 🏆 PHP | 28 KB vs 1.09 MB (39x less) |
| **Dependencies** | 🏆 PHP | 140 vs 921 (6.6x fewer) |
| **Cold Start** | 🏆 PHP | 0ms vs 1.85s |
| **Hosting Cost** | 🏆 PHP | $20/year vs usage-based |
| **Initial Paint (FCP)** | Next.js | 0.7s vs 0.3s |
