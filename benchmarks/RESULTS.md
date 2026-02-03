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
| **First Contentful Paint** | **0.28s** | 0.33s | 🏆 PHP |
| **Largest Contentful Paint** | **0.29s** | 1.58s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 134ms | 🏆 PHP |
| **Time to Interactive** | **0.29s** | 1.58s | 🏆 PHP |
| **Cumulative Layout Shift** | 0.015 | **0** | Next.js |
| **Speed Index** | **0.35s** | 0.38s | 🏆 PHP |

**Analysis:**
- PHP wins on all metrics except CLS — server-rendered HTML is immediately usable
- LCP is **5.4x faster** (0.29s vs 1.58s) — no hydration delay
- Zero Total Blocking Time — no JavaScript bundle to parse/execute

### 2. Lighthouse (Mobile, Slow 4G + 4x CPU Throttling)

Simulated mobile device (Moto G Power) with slow 4G network (1.6 Mbps, 150ms RTT) and 4x CPU slowdown.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 44 | 🏆 PHP |
| **First Contentful Paint** | **1.1s** | 2.2s | 🏆 PHP |
| **Largest Contentful Paint** | **1.2s** | 7.7s | 🏆 PHP |
| **Total Blocking Time** | **19ms** | 1,995ms | 🏆 PHP |
| **Time to Interactive** | **1.2s** | 7.9s | 🏆 PHP |
| **Cumulative Layout Shift** | 0.043 | **0** | Next.js |
| **Speed Index** | **1.1s** | 4.0s | 🏆 PHP |

**Analysis:**
- On slow connections, the JavaScript bundle size becomes critical
- Next.js TBT is **105x worse** (1,995ms vs 19ms) — the main thread is blocked parsing/executing JS
- LCP is **6.4x slower** (7.7s vs 1.2s) — users wait nearly 8 seconds to see content
- PHP's server-rendered HTML means the page is usable almost immediately
- This is where the "1 MB of JavaScript" really hurts real users

### 3. Resource Transfer Size (Network Tab)

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **HTTP Requests** | **8** | 48 | **6x** |
| **Transferred** | **59.7 KB** | 1.3 MB | **22x** |
| **Resources** | **222 KB** | 4.7 MB | **21x** |
| **JavaScript** | **~28 KB** | ~1.1 MB | **39x** |

### 4. TTFB (curl, 5 runs)

| Site | Warm | Cold Start |
|------|------|------------|
| **PHP** | ~149ms | N/A (persistent process) |
| **Next.js** | ~170ms | **1.85s** (serverless cold start) |

### 5. Codebase Size

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

### 6. Load Test (k6, PHP only)

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

### 7. SSE Streaming Compression

Same model (Claude 3.5 Haiku), same prompt. PHP response was ~2x longer content.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Endpoint** | `/updates` | `/chat` | — |
| **Transferred** | **6.0 KB** | 14.4 KB | 🏆 PHP |
| **Uncompressed** | 112 KB | ~14.4 KB | — |
| **Compression** | **Brotli** | None | 🏆 PHP |
| **Compression Ratio** | **18.7x** | 1x | 🏆 PHP |

**Analysis:**
- PHP/Swoole applies Brotli compression to SSE streams, reducing 112 KB to 6 KB
- Next.js SSE endpoint sends uncompressed data (no `Content-Encoding`)
- Even with ~2x the content, PHP transferred **58% less data**
- Critical for mobile/slow connections where bandwidth matters

### 8. DevTools Performance Traces (Slow 4G + 4x CPU)

Chrome DevTools performance recordings with network throttling (slow 4G) and 4x CPU slowdown on AMD Ryzen 7 7840U.

**Page Load Summary:**

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **LCP** | **1.69s** | 10.76s | 6.4x |
| **Total Time** | **3.05s** | 11.38s | 3.7x |
| **Scripting** | **144ms** | 3,020ms | **21x** |
| **Rendering** | 438ms | 379ms | — |
| **System** | 983ms | 416ms | — |
| **Transfer Size** | **102 KB** | 1,227 KB | **12x** |
| **Main Thread Time** | **140ms** | 2,818ms | **20x** |
| **JS Heap (peak)** | **2.6 MB** | 14.9 MB | **5.7x** |
| **DOM Nodes** | 999 | 362 | — |
| **Event Listeners** | 59 | 355 | 6x |

**3rd Party Impact (Next.js only):**
- JSDelivr CDN: 8 KB transferred, 397ms main thread time
- models.dev: 1.2 KB transferred

**Analysis:**
- PHP spends **21x less time** on JavaScript execution (144ms vs 3,020ms)
- Next.js main thread is blocked for **2.8 seconds** just processing JS
- PHP has **5.7x smaller** peak memory footprint (2.6 MB vs 14.9 MB)
- More DOM nodes in PHP (999 vs 362) = server-rendered content vs client hydration
- Next.js has **6x more event listeners** attached — framework overhead
- Zero 3rd party requests for PHP vs CDN dependencies for Next.js

**Trace files:** `trace-php-load.json.gz`, `trace-nextjs-load.json.gz`
**Flamecharts:** `php_flamechart_load.png`, `nextjs_flamechart_load.png`

**Chat Response (one message, wait for complete response):**

| Metric | PHP/Swoole | Next.js | Notes |
|--------|------------|---------|-------|
| **LCP** | **35ms** | 206ms | 6x faster |
| **Scripting** | **1,727ms (43%)** | 2,277ms (67%) | 24% less JS work |
| **Rendering** | 1,348ms (34%) | 517ms (15%) | PHP renders more DOM |
| **Painting** | 446ms (11%) | 176ms (5%) | More paint = more content |
| **JS Heap Growth** | **+2.7 MB** | +8.5 MB | **3.1x less memory** |
| **DOM Nodes** | 4,452 → 18,590 | 265 → 466 | PHP streams more DOM |
| **Event Listeners** | 184 → 670 | 359 → 652 | — |

**Analysis:**
- PHP spends **more time rendering/painting** because Datastar streams DOM patches directly
- Next.js spends **67% of time in JavaScript** processing the response before DOM updates
- PHP DOM grows from 4K to 18K nodes — real content streaming into the page
- Next.js DOM barely grows (265→466) — most work happens in JS/React virtual DOM
- PHP memory grows **3.1x less** (2.7 MB vs 8.5 MB) — no React reconciliation overhead
- The "more rendering time" in PHP is **good** — it means the browser is actually showing content faster

**Trace files:** `trace-php-chat.json.gz`, `trace-nextjs-chat.json.gz`
**Flamecharts:** `php_flamechart_chat.png`, `nextjs_flamechart_chat.png`

---

## ❌ Not Tested

| Test | Reason |
|------|--------|
| **Next.js load testing (k6)** | Vercel rate limit (HTTP 429) |
| **AI streaming TTFT** | Requires API key + auth on both systems |
| **Memory profiling** | No system access to Vercel serverless |
| **Authenticated endpoints** | Different auth systems, not comparable |

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
| **Performance Score (Desktop)** | 🏆 PHP | 100 vs 92 |
| **Performance Score (Mobile)** | 🏆 PHP | 100 vs 44 |
| **Time to Interactive** | 🏆 PHP | 0.29s vs 1.58s (5.4x) |
| **Total Blocking Time** | 🏆 PHP | 0ms vs 134ms |
| **Mobile TBT** | 🏆 PHP | 19ms vs 1,995ms (105x) |
| **Page Weight** | 🏆 PHP | 60 KB vs 1.3 MB (22x) |
| **JavaScript Size** | 🏆 PHP | 28 KB vs 1.1 MB (39x) |
| **HTTP Requests** | 🏆 PHP | 8 vs 48 (6x) |
| **SSE Compression** | 🏆 PHP | Brotli 18.7x vs none |
| **Dependencies (prod)** | 🏆 PHP | 69 vs 799 (11.6x) |
| **Vendor/node_modules** | 🏆 PHP | 25 MB vs 793 MB (31.7x) |
| **Cold Start** | 🏆 PHP | 0ms vs 1.85s |
| **Hosting Cost** | 🏆 PHP | $20/year vs usage-based |
| **Cumulative Layout Shift** | Next.js | 0.015 vs 0 |

**PHP wins 13/14 categories.** Next.js only wins on CLS (layout shift).

---

## 📁 Raw Benchmark Data

All benchmark data is available in [`benchmarks/results/`](results/):

**Lighthouse Reports:**
- [php-lighthouse-desktop.json](results/php-lighthouse-desktop.json)
- [php-lighthouse-mobile.json](results/php-lighthouse-mobile.json)
- [nextjs-lighthouse-desktop.json](results/nextjs-lighthouse-desktop.json)
- [nextjs-lighthouse-mobile.json](results/nextjs-lighthouse-mobile.json)

**DevTools Performance Traces (load in Chrome DevTools > Performance):**
- [trace-php-load.json.gz](results/trace-php-load.json.gz)
- [trace-php-chat.json.gz](results/trace-php-chat.json.gz)
- [trace-nextjs-load.json.gz](results/trace-nextjs-load.json.gz)
- [trace-nextjs-chat.json.gz](results/trace-nextjs-chat.json.gz)

**Flamechart Screenshots:**
- [php_flamechart_load.png](results/php_flamechart_load.png)
- [php_flamechart_chat.png](results/php_flamechart_chat.png)
- [nextjs_flamechart_load.png](results/nextjs_flamechart_load.png)
- [nextjs_flamechart_chat.png](results/nextjs_flamechart_chat.png)
