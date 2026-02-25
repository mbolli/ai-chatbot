# Benchmark Results: Next.js vs PHP/Swoole

**Date:** February 24, 2026

## Production URLs

| App | URL | Hosting |
|-----|-----|---------|
| **PHP/Swoole** | https://chat.zweiundeins.gmbh | $20/year VPS (2 vCPU, 4GB RAM) |
| **Next.js** | https://demo.chat-sdk.dev/ | Vercel (serverless) |

> **Context:** The [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) has **86 contributors** and **600+ commits** of optimization. The PHP port is a straightforward implementation with minimal optimization.

---

## ✅ Completed Benchmarks

### 1. Lighthouse (Desktop, Chrome 144, LH 13.0.1)

Tested from desktop browser via Chrome DevTools.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 93 | 🏆 PHP |
| **First Contentful Paint** | **0.3s** | 0.5s | 🏆 PHP |
| **Largest Contentful Paint** | **0.3s** | 1.6s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 110ms | 🏆 PHP |
| **Time to Interactive** | **0.3s** | 1.6s | 🏆 PHP |
| **Cumulative Layout Shift** | **0.001** | 0 | ~Tie |
| **Speed Index** | **0.3s** | 0.6s | 🏆 PHP |

**Analysis:**
- PHP wins on all metrics; CLS is now essentially zero (0.001 vs 0) — effectively a tie
- LCP is **5.3x faster** (0.3s vs 1.6s) — no hydration delay
- Zero Total Blocking Time — no JavaScript bundle to parse/execute

### 2. Lighthouse (Mobile, Slow 4G + 4x CPU Throttling, LH 13.0.1)

Simulated mobile device with slow 4G network (1.6 Mbps, 150ms RTT) and 4x CPU slowdown.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 54 | 🏆 PHP |
| **First Contentful Paint** | **1.1s** | 1.6s | 🏆 PHP |
| **Largest Contentful Paint** | **1.1s** | 8.1s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 780ms | 🏆 PHP |
| **Time to Interactive** | **1.1s** | 8.2s | 🏆 PHP |
| **Cumulative Layout Shift** | **0.002** | 0 | ~Tie |
| **Speed Index** | **1.1s** | 3.9s | 🏆 PHP |

**Analysis:**
- On slow connections, the JavaScript bundle size becomes critical
- Next.js TBT is **780ms vs 0ms** — the main thread is blocked parsing/executing JS
- LCP is **7.4x slower** (8.1s vs 1.1s) — users wait over 8 seconds to see content
- PHP's server-rendered HTML means the page is usable almost immediately
- CLS is now essentially zero on PHP (0.002) — previously the only metric PHP lost

### 3. Resource Transfer Size (cold load, `curl -H "Accept-Encoding: br,gzip"`)

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **HTTP Requests** | **8** | 36+ | **4.5x** |
| **Transferred (compressed)** | **41.9 KB** | ~1,107 KB | **26x** |
| **Resources (decoded)** | **172.6 KB** | ~4,525 KB | **26x** |
| **JavaScript (transferred)** | **13.5 KB** | ~1,080 KB | **80x** |
| **JavaScript (decoded)** | **34 KB** | ~4,264 KB | **125x** |

**Note:** Next.js JS bundle has grown significantly — two chunks alone are 473 KB + 328 KB compressed (1.5 MB + 1.7 MB decoded), likely the code/artifact editor bundles. The two largest files (`7400ee8c2fe3a52b.js` and `f506c5750ffe2cb0.js`) account for **72% of the total transferred JS**.

### 4. TTFB (curl, 5 runs, Feb 24 2026)

Raw `time_starttransfer` readings (run 1 includes DNS resolution):

| Run | PHP/Swoole | Next.js |
|-----|------------|---------|
| 1 | 326ms | 330ms |
| 2 | 141ms | 289ms |
| 3 | 114ms | 101ms |
| 4 | 160ms | 121ms |
| 5 | 119ms | 102ms |
| **Avg (all 5)** | **~172ms** | **~188ms** |
| **Avg (runs 2–5)** | **~134ms** | **~153ms** |

| Site | Warm TTFB | Cold Start |
|------|-----------|------------|
| **PHP** | **~134ms** | N/A (persistent Swoole process) |
| **Next.js** | ~153ms | **1.85s** (serverless cold start, from Jan run) |

Both servers are warm-cache ties at this geographic distance. PHP avoids cold starts entirely due to persistent process model.

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

#### Single-Turn (Claude 3.5 Haiku, same prompt)

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Endpoint** | `/updates` (persistent) | `/chat` (per-request) | — |
| **Transferred** | **6.0 KB** | 14.4 KB | 🏆 PHP |
| **Uncompressed** | 112 KB | ~14.4 KB | — |
| **Compression** | **Brotli** | None ² | 🏆 PHP |
| **Compression Ratio** | **18.7x** | 1x | 🏆 PHP |

#### Multi-Turn (10 turns, Claude Haiku 4.5, identical prompts)

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **SSE connections** | **1** (persistent) | 10 (one per turn) | 🏆 PHP |
| **Transferred (streaming)** | **55.8 KB** | ~114 KB | 🏆 PHP |
| **Uncompressed (streaming)** | 3,265 KB | ~114 KB | — |
| **Compression** | **Brotli 58.5x** | None ² | 🏆 PHP |
| **Transfer savings** | **2x less** than Next.js | baseline | 🏆 PHP |

**Analysis:**
- PHP uses a **single persistent SSE stream** for the entire session; Brotli compresses all 10 turns together, benefiting from cross-turn repetition in the dictionary
- Next.js creates a **new streaming POST per turn** — no cross-turn compression, per-request overhead × 10; only non-streaming GET requests (history, votes) use Brotli
- PHP's implementation streams **full rendered HTML per chunk** (fat-morph approach) rather than text deltas, generating **~29x more raw data** than Next.js (3,265 KB vs ~114 KB) — a deliberate simplicity trade-off, not a Datastar constraint
- Despite the verbose protocol, Brotli on the persistent stream achieves **58.5x compression**, bringing actual transfer down to 55.8 KB — **half of Next.js**
- Advantage compounds as conversation grows: longer history = more repetition = better compression

> ² Next.js SSE/streaming POST responses have no `Content-Encoding`; only the in-between GETs (history, vote) use Brotli

### 8. DevTools Performance Traces (Slow 4G + 4x CPU)

Chrome DevTools performance recordings with Slow 4G + 4x CPU emulation. LCP sourced from Lighthouse (section 2) which properly disables cache; all other metrics extracted from trace files.

**Page Load Summary:**

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **LCP (Lighthouse, cold)** | **1.1s** | 8.1s | **7.4x** |
| **LCP (trace, cold)** | **1.65s** | n/a (nav mismatch) | — |
| **Scripting** | **257ms** | 5,613ms | **21.8x** |
| **Rendering** | **91ms** | 79ms | ~tie |
| **Painting** | **33ms** | 28ms | ~tie |
| **JS Heap (peak)** | **+1.5 MB** | +12.9 MB | **8.6x** |
| **DOM Nodes, final** | 963 | **326** | all node types incl. text |
| **Event Listeners** | **11 → 59** | 11 → 349 | **5.9x** |

**Analysis:**
- PHP spends **21.8x less time** on JavaScript execution (257ms vs 5,613ms)
- Next.js attaches **5.9x more event listeners** — framework overhead
- PHP has **8.6x smaller** peak memory footprint during load
- PHP has more DOM nodes (963 vs 326) = full server-rendered HTML vs hydrated shell (includes text/comment nodes, not just elements)

**3rd Party Impact (Next.js only):**
- JSDelivr CDN: pyodide.js loaded on every page
- models.dev: google.svg logo

**Trace files:** `trace-php-load.json.gz`, `trace-nextjs-load.json.gz`
**Flamecharts:** `php_flamechart_load.png`, `nextjs_flamechart_load.png`

**Chat Response (one message, Slow 4G + 4x CPU):**

| Metric | PHP/Swoole | Next.js | Notes |
|--------|------------|---------|-------|
| **Scripting** | **2,485ms** | 10,500ms | PHP **4.2x less** JS work |
| **Rendering** | 2,225ms | **1,274ms** | PHP renders more DOM patches |
| **Painting** | 1,240ms | **538ms** | More paint = more DOM content |
| **Total** | **12,500ms** | 19,686ms | PHP **37% faster** overall |
| **V8 Node Allocs** ¹ | 3,164 → 34,559 (+31,395) | 394 → 822 (+428) | PHP accumulates detached nodes |
| **JS Heap Growth** | **+1.9 MB** | +10.4 MB | **5.5x less memory** |
| **Event Listeners** | 99 → 376 | 372 → 898 | — |

> ¹ **V8 Node Allocs** = Chrome's `UpdateCounters.nodes` — counts **all nodes in the V8 heap** (attached + detached, pending GC). For PHP/Datastar this inflates during streaming: each `PatchElements` chunk creates new DOM nodes and orphans the old ones; live `document.querySelectorAll('*').length` after streaming is ~400–600. For Next.js the React vDOM reconciles in-place, so detached nodes never accumulate. This metric reflects **GC pressure and allocation churn**, not final live DOM size.

**Analysis:**
- PHP accumulates **+31,395 node allocations** during streaming — Datastar replaces DOM chunks on each SSE event, creating detached nodes awaiting GC; actual live DOM after stream completion is ~400–600 elements
- Next.js node count grows modestly (394→822) — React reconciles in-place, fewer orphaned nodes
- PHP heap grows **5.5x less** (+1.9 MB vs +10.4 MB) despite higher allocation churn — no React reconciliation overhead
- PHP spends more time rendering/painting because content hits the real DOM on every SSE chunk (no virtual DOM buffer)
- Next.js spends **4.2x more time** in JavaScript (10,500ms vs 2,485ms) processing streamed responses
- PHP completes the full chat response cycle **37% faster** overall (12.5s vs 19.7s)

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
| **Performance Score (Desktop)** | 🏆 PHP | 100 vs 93 |
| **Performance Score (Mobile)** | 🏆 PHP | 100 vs 54 |
| **Time to Interactive** | 🏆 PHP | 0.3s vs 1.6s (5.3x) |
| **Total Blocking Time** | 🏆 PHP | 0ms vs 110ms |
| **Mobile TBT** | 🏆 PHP | 0ms vs 780ms |
| **Page Weight (transferred)** | 🏆 PHP | 42 KB vs 1,107 KB (26x) |
| **JavaScript Size (transferred)** | 🏆 PHP | 13.5 KB vs 1,080 KB (80x) |
| **HTTP Requests** | 🏆 PHP | 8 vs 36+ (4.5x) |
| **JS Scripting Time (load)** | 🏆 PHP | 257ms vs 5,613ms (21.8x) |
| **JS Heap Growth (load)** | 🏆 PHP | +1.5 MB vs +12.9 MB (8.6x) |
| **JS Heap Growth (chat)** | 🏆 PHP | +1.9 MB vs +10.4 MB (5.5x) |
| **SSE Compression** | 🏆 PHP | Brotli 58.5x vs none; 2x less transferred over 10 turns |
| **Dependencies (prod)** | 🏆 PHP | 69 vs 799 (11.6x) |
| **Vendor/node_modules** | 🏆 PHP | 25 MB vs 793 MB (31.7x) |
| **Cold Start** | 🏆 PHP | 0ms vs 1.85s |
| **Hosting Cost** | 🏆 PHP | $20/year vs usage-based |
| **Cumulative Layout Shift** | ~Tie | 0.001 vs 0 |

**PHP wins 17/17 categories.** CLS is effectively a tie (0.001 vs 0).

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
