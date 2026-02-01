# Benchmarking Strategy: Next.js vs PHP/Swoole

## Results Summary (February 1, 2026)

> **Context:** Next.js project has 86 contributors, 600+ commits. PHP project is unoptimized.

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Lighthouse Score** | **100** | 92 | 🏆 PHP |
| **Time to Interactive** | **0.7s** | 1.6s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 130ms | 🏆 PHP |
| **JavaScript Size** | **28 KB** | 1.09 MB | 🏆 PHP (39x less) |
| **Dependencies (prod)** | **69** | 799 | 🏆 PHP (11.6x fewer) |
| **Disk Size (prod)** | **25 MB** | 793 MB | 🏆 PHP (31.7x smaller) |
| **Cold Start** | **0ms** | 1.85s | 🏆 PHP |
| **Hosting Cost** | **$20/year** | Usage-based | 🏆 PHP |

### Load Test (k6)

**With only 1 Swoole worker:**

| Metric | Localhost | Production ($20 VPS) |
|--------|-----------|----------------------|
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

> The $20/year VPS with 1 Swoole worker handles **44 req/s** thanks to coroutine concurrency. Server processes each request in ~65ms.

---

## Overview

Compare both **production deployments** across multiple dimensions to provide objective data for the COMPARISON.md document.

### Production URLs

| App | URL | Hosting |
|-----|-----|---------|
| **Next.js** | https://demo.chat-sdk.dev/ | Vercel (serverless) |
| **PHP/Swoole** | https://chat.zweiundeins.gmbh | TBD (persistent process) |

> **Note:** Testing production deployments provides real-world data including CDN, SSL, geographic latency, and actual infrastructure differences.

## Metrics Categories

### 1. Web Vitals (Lighthouse)

Lighthouse is fully scriptable via CLI or Node API.

| Metric | Description | Target |
|--------|-------------|--------|
| **LCP** | Largest Contentful Paint | < 2.5s |
| **FID** | First Input Delay | < 100ms |
| **CLS** | Cumulative Layout Shift | < 0.1 |
| **TTFB** | Time to First Byte | < 800ms |
| **FCP** | First Contentful Paint | < 1.8s |
| **TTI** | Time to Interactive | < 3.8s |
| **TBT** | Total Blocking Time | < 200ms |
| **Performance Score** | Overall 0-100 | > 90 |

### 2. Bundle / Payload Size

| Metric | Next.js | PHP/Swoole |
|--------|---------|------------|
| **Initial HTML** | RSC payload + shell | Full HTML |
| **JavaScript** | ~200-500KB gzipped | ~15KB (Datastar) |
| **CSS** | Tailwind purged | Open Props + custom |
| **Total Transfer** | Measure with DevTools | Measure with DevTools |

### 3. Server Performance

| Metric | Tool | Description |
|--------|------|-------------|
| **TTFB** | curl/wrk | Time to first byte on cold/warm |
| **Throughput** | wrk/k6 | Requests per second |
| **Latency p50/p95/p99** | k6 | Response time percentiles |
| **Concurrent connections** | k6 | Max SSE connections |
| **Memory usage** | top/ps | RSS under load |
| **Cold start** | custom | Time from process start to first response |

### 4. AI Streaming Metrics

| Metric | Description | How to Measure |
|--------|-------------|----------------|
| **Time to First Token (TTFT)** | User sends → first AI token appears | Custom script with timestamps |
| **Tokens per Second** | Streaming throughput | Count chunks over time |
| **Total Generation Time** | Full response completion | End - start timestamp |
| **SSE Event Latency** | Server emit → client receive | Custom instrumentation |

### 5. Developer Experience (Qualitative)

| Metric | Next.js | PHP/Swoole |
|--------|---------|------------|
| **Lines of Code** | Count with cloc | Count with cloc |
| **Dependencies** | package.json count | composer.json count |
| **Build Time** | `next build` | N/A (interpreted) |
| **Dev Server Start** | `next dev` cold start | `composer serve` |
| **Type Coverage** | TypeScript strict | PHPStan level |

### 6. Codebase Size

**Measured sizes (January 2026):**

| Component | Next.js | PHP/Swoole |
|-----------|---------|------------|
| **App code** | 1.5 MB (app+components+lib) | 608 KB (src+templates+config) |
| **Dependencies** | **986 MB** (node_modules) | **76 MB** (vendor) |
| **Total** | **~988 MB** | **~77 MB** |
| **Ratio** | 13x larger | 1x (baseline) |

**Dependency counts:**

| Metric | Next.js | PHP/Swoole |
|--------|---------|------------|
| **Direct (prod)** | 80 | 22 |
| **Direct (dev)** | 17 | 10 |
| **Direct total** | **97** | **32** |
| **Installed (all)** | **921** | **140** |

```bash
# Reproduce these measurements
du -sh ai-chatbot/node_modules  # 986M
du -sh vendor                    # 76M

jq '.dependencies | length' ai-chatbot/package.json      # 80
jq '.require | length' composer.json                     # 22
composer show | wc -l                                    # 140
```

> **Note:** The 13x difference in dependency size reflects the JavaScript ecosystem's tendency toward smaller, single-purpose packages vs PHP's more consolidated libraries.

---

## Tools & Scripts

### Lighthouse (Scriptable)

```bash
# CLI usage - Production URLs
npx lighthouse https://demo.chat-sdk.dev/ --output=json --output-path=./results/nextjs.json
npx lighthouse https://chat.zweiundeins.gmbh/ --output=json --output-path=./results/php.json

# Compare scores
node scripts/compare-lighthouse.js
```

```javascript
// Node API for automation
import lighthouse from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';

const URLS = {
  nextjs: 'https://demo.chat-sdk.dev/',
  php: 'https://chat.zweiundeins.gmbh/',
};

async function runLighthouse(url) {
  const chrome = await chromeLauncher.launch({chromeFlags: ['--headless']});
  const result = await lighthouse(url, {
    port: chrome.port,
    onlyCategories: ['performance'],
  });
  await chrome.kill();
  return result.lhr;
}
```

### wrk (HTTP Benchmarking)

```bash
# Basic throughput test - Production
wrk -t4 -c100 -d30s https://chat.zweiundeins.gmbh/
wrk -t4 -c100 -d30s https://demo.chat-sdk.dev/

# With latency stats
wrk -t4 -c100 -d30s --latency https://chat.zweiundeins.gmbh/
wrk -t4 -c100 -d30s --latency https://demo.chat-sdk.dev/
```

### k6 (Load Testing with SSE Support)

```javascript
// k6 script for SSE endpoint - Production
import { check } from 'k6';
import sse from 'k6/x/sse';

export const options = {
  vus: 50,
  duration: '60s',
};

const URLS = {
  php: 'https://chat.zweiundeins.gmbh/updates',
  nextjs: 'https://demo.chat-sdk.dev/api/chat', // SSE via fetch
};

export default function () {
  const response = sse.open(URLS.php);
  
  check(response, {
    'SSE connection established': (r) => r.status === 200,
  });
}
```

### Custom AI Streaming Benchmark

```javascript
// Measure time-to-first-token
async function measureTTFT(endpoint, message) {
  const start = performance.now();
  let firstTokenTime = null;
  
  const response = await fetch(endpoint, {
    method: 'POST',
    body: JSON.stringify({ message }),
  });
  
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    
    if (!firstTokenTime) {
      firstTokenTime = performance.now();
      console.log(`TTFT: ${firstTokenTime - start}ms`);
    }
  }
  
  console.log(`Total: ${performance.now() - start}ms`);
}
```

### Memory & Resource Monitoring

```bash
# PHP/Swoole memory
ps aux | grep swoole | awk '{print $6/1024 " MB"}'

# Next.js memory  
ps aux | grep node | awk '{print $6/1024 " MB"}'

# Continuous monitoring during load test
while true; do
  echo "$(date): $(ps -o rss= -p $PID | awk '{print $1/1024}') MB"
  sleep 1
done
```

### Code Metrics

```bash
# Lines of code comparison
cloc ai-chatbot/app ai-chatbot/components ai-chatbot/lib --json > results/nextjs-loc.json
cloc src templates --json > results/php-loc.json

# Dependency count
jq '.dependencies | length' ai-chatbot/package.json
jq '.require | length' composer.json
```

---

## Test Scenarios

### Scenario 1: Cold Start Performance
1. Stop both servers
2. Start server, immediately request homepage
3. Measure TTFB and full page load

### Scenario 2: Chat Page Load (Authenticated)
1. Login to both apps
2. Navigate to existing chat with 10+ messages
3. Measure LCP, TTI, JavaScript execution time

### Scenario 3: Message Send → First Token
1. Type message in input
2. Click send
3. Measure time until first assistant text appears

### Scenario 4: Full Conversation (5 exchanges)
1. Automated 5-turn conversation
2. Measure total time, average response time

### Scenario 5: Concurrent Users
1. 50 users sending messages simultaneously
2. Measure throughput, error rate, latency percentiles

### Scenario 6: Long SSE Connection
1. Keep SSE connection open for 10 minutes
2. Monitor memory growth, connection stability

---

## Output Format

### Actual Results (January 30, 2026)

**Lighthouse Desktop (Chrome 144, Windows):**

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 92 | PHP |
| **First Contentful Paint** | 0.7s | **0.3s** | Next.js |
| **Largest Contentful Paint** | **0.7s** | 1.6s | PHP |
| **Total Blocking Time** | **0ms** | 130ms | PHP |
| **Cumulative Layout Shift** | 0.015 | **0** | Next.js |
| **Speed Index** | 0.7s | **0.4s** | Next.js |
| **Time to Interactive** | **0.7s** | 1.6s | PHP |

**Analysis:**
- **PHP wins on LCP, TBT, TTI** — Server-rendered HTML is immediately interactive with zero JS blocking
- **Next.js wins on FCP, SI** — Faster initial paint due to edge CDN, but then JS hydration delays interactivity
- **LCP difference (0.7s vs 1.6s)** — The 2.3x difference is due to React hydration overhead
- **TBT (0ms vs 130ms)** — No JavaScript execution blocking on PHP; Next.js must hydrate components

**TTFB Test (curl, 5 runs):**

| Site | Average | Cold Start |
|------|---------|------------|
| **PHP** | ~149ms | N/A (persistent) |
| **Next.js** | ~170ms (warm) | 1.85s (serverless) |

**Resource Transfer Size (from Lighthouse):**

| Resource | PHP/Swoole | Next.js | Difference |
|----------|------------|---------|------------|
| **Total** | **230 KB** | **1.26 MB** | 5.5x larger |
| **JavaScript** | **28 KB** | **1.09 MB** | 39x larger |
| **CSS** | 40 KB | 27 KB | — |
| **HTML Document** | 8 KB | 11 KB | — |
| **Fonts** | 157 KB | 120 KB | — |
| **Requests** | **17** | **39** | 2.3x more |

**Key insight:** Next.js ships **39x more JavaScript** (1.09 MB vs 28 KB). This explains:
- The 130ms Total Blocking Time (vs 0ms for PHP)
- The 1.6s Time to Interactive (vs 0.7s for PHP)
- The need for hydration after initial paint

---

```json
{
  "timestamp": "2026-01-30T12:00:00Z",
  "apps": {
    "nextjs": {
      "lighthouse": { "performance": 85, "lcp": 2100, "fid": 50 },
      "server": { "ttfb_p50": 120, "rps": 450 },
      "streaming": { "ttft": 340, "tps": 45 },
      "resources": { "memory_mb": 180, "bundle_kb": 320 }
    },
    "php": {
      "lighthouse": { "performance": 95, "lcp": 800, "fid": 20 },
      "server": { "ttfb_p50": 15, "rps": 2800 },
      "streaming": { "ttft": 280, "tps": 52 },
      "resources": { "memory_mb": 45, "bundle_kb": 18 }
    }
  }
}
```

---

## Execution Plan

### Phase 1: Setup (Day 1)
- [ ] Create benchmark scripts directory
- [ ] Install tools (lighthouse, wrk, k6)
- [ ] Write Lighthouse automation script
- [ ] Write basic HTTP benchmark script

### Phase 2: Server Benchmarks (Day 2)
- [ ] Run TTFB tests (cold/warm)
- [ ] Run throughput tests with wrk
- [ ] Run concurrent connection tests with k6
- [ ] Collect memory usage data

### Phase 3: AI Streaming Benchmarks (Day 3)
- [ ] Write TTFT measurement script
- [ ] Mock AI responses for consistent comparison
- [ ] Run streaming benchmarks
- [ ] Measure SSE overhead

### Phase 4: Lighthouse & Web Vitals (Day 4)
- [ ] Run Lighthouse on both apps
- [ ] Collect Core Web Vitals
- [ ] Measure bundle sizes
- [ ] Generate comparison report

### Phase 5: Analysis & Documentation (Day 5)
- [ ] Aggregate all results
- [ ] Generate charts/visualizations
- [ ] Update COMPARISON.md with findings
- [ ] Document methodology

---

## Notes

- **Production testing** — Both apps tested on their production URLs
- Use **mocked AI responses** for streaming consistency (or same model/prompt)
- Run each test **3x minimum** and average
- Run from **same geographic location** to normalize network latency
- Document **exact versions** of all software
- Consider **time of day** (serverless cold starts vary)
- Be mindful of **rate limits** on production APIs

### Infrastructure Differences to Document

| Aspect | Next.js (Vercel) | PHP/Swoole |
|--------|------------------|------------|
| **Compute** | Serverless functions | Persistent process |
| **Cost** | Usage-based | ~$20/year VPS |
| **Cold start** | Yes (after idle) | No |
| **CDN** | Vercel Edge | None (direct) |
| **SSL** | Automatic | Let's Encrypt |
| **Region** | Auto (edge) | Single region |
| **Scaling** | Auto | Manual |

---

## SSE Endpoint Analysis

### Metrics to Measure

| Metric | Description | Tool |
|--------|-------------|------|
| **Compression ratio** | gzip/brotli savings | curl + compare |
| **Event overhead** | SSE framing bytes vs payload | Custom script |
| **Bytes per token** | Transfer efficiency | Wireshark/DevTools |
| **Keep-alive efficiency** | Connection reuse | DevTools Network |
| **Heartbeat overhead** | Keep-alive event size | Packet capture |

### SSE Compression Test

```bash
# Check if SSE is compressed (it often isn't due to streaming)
# Uncompressed
curl -s -o /dev/null -w '%{size_download}' \
  -H "Accept: text/event-stream" \
  https://chat.zweiundeins.gmbh/updates

# Request with compression
curl -s -o /dev/null -w '%{size_download}' \
  -H "Accept: text/event-stream" \
  -H "Accept-Encoding: gzip, deflate, br" \
  --compressed \
  https://chat.zweiundeins.gmbh/updates
```

### SSE Event Overhead Analysis

```javascript
// Measure SSE protocol overhead vs actual content
async function measureSseOverhead(url) {
  const response = await fetch(url, {
    headers: { 'Accept': 'text/event-stream' }
  });
  
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  
  let totalBytes = 0;
  let payloadBytes = 0;
  let eventCount = 0;
  
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    
    totalBytes += value.length;
    const text = decoder.decode(value);
    
    // Parse SSE events
    const events = text.split('\n\n').filter(Boolean);
    for (const event of events) {
      eventCount++;
      // Extract actual data payload
      const dataMatch = event.match(/^data: (.+)$/m);
      if (dataMatch) {
        payloadBytes += new TextEncoder().encode(dataMatch[1]).length;
      }
    }
  }
  
  const overhead = totalBytes - payloadBytes;
  const overheadPercent = ((overhead / totalBytes) * 100).toFixed(1);
  
  return {
    totalBytes,
    payloadBytes,
    overhead,
    overheadPercent: `${overheadPercent}%`,
    eventCount,
    avgBytesPerEvent: Math.round(totalBytes / eventCount),
  };
}
```

### SSE Format Comparison

**Next.js AI SDK format:**
```
data: {"type":"text-delta","textDelta":"Hello"}

data: {"type":"text-delta","textDelta":" world"}

```
- JSON overhead per token
- Double newline separators
- Type field in every event

**Datastar format:**
```
event: datastar-patch-elements
data: selector #message-123-content
data: mergeMode append
data: fragments <span>Hello</span>

```
- Multiple data lines
- Event type header
- HTML fragments (larger but no client parsing)

### Measuring Bytes Per AI Token

```javascript
// Compare transfer efficiency during AI streaming
async function measureBytesPerToken(endpoint, message) {
  let totalBytes = 0;
  let tokenCount = 0;
  
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message }),
  });
  
  const reader = response.body.getReader();
  
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    
    totalBytes += value.length;
    // Count text-delta events or Datastar patches
    const text = new TextDecoder().decode(value);
    tokenCount += (text.match(/text-delta|fragments/g) || []).length;
  }
  
  return {
    totalBytes,
    tokenCount,
    bytesPerToken: Math.round(totalBytes / tokenCount),
  };
}
```

### Expected Results

| Metric | Next.js (AI SDK) | PHP (Datastar) | Notes |
|--------|------------------|----------------|-------|
| **SSE compression** | Usually none | Usually none | Streaming prevents buffering |
| **Event overhead** | ~40-60 bytes/event | ~80-120 bytes/event | Datastar has more metadata |
| **Payload efficiency** | JSON (compact) | HTML (verbose) | But no client parsing needed |
| **Bytes per token** | ~50-80 | ~100-150 | Datastar trades size for simplicity |
