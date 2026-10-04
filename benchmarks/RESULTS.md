# Benchmark Results: Next.js vs PHP

**Measured:** October 4 and 5, 2026. The first run was in February 2026; where a figure could not be measured again, the February value is shown and marked.

## Apps

| App | URL | Stack | Hosting |
|-----|-----|-------|---------|
| **PHP** | https://chat.zweiundeins.gmbh | php-via 0.14 on OpenSwoole 26, PHP 8.5, one worker | $20/year VPS (2 vCPU, 4 GB RAM) |
| **Next.js** | https://chatbot.ai-sdk.dev/demo | vercel/ai-chatbot (Next.js 16, AI SDK 7) | Vercel |

In February the PHP app ran on Mezzio with Swoole, and the Vercel demo lived at demo.chat-sdk.dev. Both sides are measured from the same machine, in Chromium 154 with Lighthouse 13.5. Lighthouse figures are the median of three runs.

> **Context:** The [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) has many contributors and hundreds of commits of optimization. The PHP port is a straightforward implementation.

---

## 1. Lighthouse, desktop

| Metric | PHP | Next.js |
|--------|-----|---------|
| **Performance score** | **100** | 90 |
| **First Contentful Paint** | **0.30 s** | 0.32 s |
| **Largest Contentful Paint** | **0.30 s** | 1.64 s |
| **Total Blocking Time** | **0 ms** | 153 ms |
| **Time to Interactive** | **0.30 s** | 1.64 s |
| **Cumulative Layout Shift** | 0 | 0 |
| **Speed Index** | **0.40 s** | 0.80 s |

February: PHP 100 (LCP 0.3 s, TBT 0 ms), Next.js 93 (LCP 1.6 s, TBT 110 ms).

## 2. Lighthouse, mobile (simulated Slow 4G, 4x CPU slowdown)

| Metric | PHP | Next.js |
|--------|-----|---------|
| **Performance score** | **100** | 52 |
| **First Contentful Paint** | **1.07 s** | 1.12 s |
| **Largest Contentful Paint** | **1.07 s** | 8.85 s |
| **Total Blocking Time** | **0 ms** | 898 ms |
| **Time to Interactive** | **1.07 s** | 8.87 s |
| **Cumulative Layout Shift** | 0 | 0 |
| **Speed Index** | **1.07 s** | 4.05 s |

February: PHP 100 (LCP 1.1 s, TBT 0 ms), Next.js 54 (LCP 8.1 s, TBT 780 ms). LCP on Next.js is 8.3x slower than on PHP.

## 3. Transfer size (cold load, from the Lighthouse desktop run)

| Metric | PHP | Next.js | Ratio |
|--------|-----|---------|-------|
| **Requests** | **7** | 35 | 5x |
| **Transferred** | **51.8 KB** | 1,415.5 KB | 27x |
| **Decoded** | **231.0 KB** | 5,015.7 KB | 22x |
| **JavaScript transferred** | **13.6 KB** (3 files) | 1,291.4 KB (23 files) | 95x |
| **JavaScript decoded** | **36.0 KB** | 4,247.9 KB | 118x |

The PHP figure includes the first 10.7 KB of the SSE stream, which carries the page's initial state. The largest Next.js file alone transfers 679 KB. February: PHP 8 requests and 41.9 KB, Next.js 36 requests and about 1,107 KB.

## 4. TTFB (curl, 5 runs)

| Run | PHP | Next.js |
|-----|-----|---------|
| 1 | 97 ms | 1,345 ms ¹ |
| 2 | 219 ms | 115 ms |
| 3 | 77 ms | 135 ms |
| 4 | 77 ms | 121 ms |
| 5 | 194 ms | 119 ms |

¹ The first request to the demo goes through two redirects of its guest sign-in; the later ones carry its cookie. The PHP app runs as one long-lived process, so it has no cold start. The Next.js cold start of 1.85 s is from the January run and was not measured again.

## 5. Dependencies

Production install (`--no-dev`), PHP app at master with php-via 0.14.0 from Packagist, Next.js at vercel/ai-chatbot c2f8235 (July 2026):

| Metric | PHP | Next.js | Ratio |
|--------|-----|---------|-------|
| **Packages installed** | **18** | 592 | 33x |
| **Disk (vendor / node_modules)** | **4.6 MB** | 714 MB | 155x |

With dev dependencies:

| Metric | PHP | Next.js | Ratio |
|--------|-----|---------|-------|
| **Packages installed** | **105** | 754 | 7.2x |
| **Disk (vendor / node_modules)** | **83 MB** | 885 MB | 11x |
| **Direct dependencies** | **14** | 85 | 6.1x |

php-via itself takes 2.2 MB of the 4.6 MB; its package also ships benchmarks and internal notes. In February the PHP app (Mezzio) needed 69 packages and 25 MB, the count of 799 Next.js "packages" from back then counted `package.json` files, not packages.

## 6. Load test (k6, PHP only)

The Vercel demo rate-limits load tests, so only the PHP app is tested.

**10 virtual users without pause, 15 s** (the test from February):

| Metric | October | February |
|--------|---------|----------|
| **Throughput** | **298 req/s** | ~44 req/s |
| **Response time, average** | **33 ms** | 225 ms |
| **Response time, p95** | **69 ms** | 385 ms |
| **Failed** | 0 | 0 |

**Ramp to 10, then 50 users with 1 s between requests, 90 s** (`scripts/load-test.js`): 2,295 requests, median 31 ms, p95 75 ms, one request failed. Times include the network path from the test machine to the server.

Two changes made the difference after the move to php-via: SQLite runs in WAL mode, and PHP starts with `opcache.enable_cli=1`, so the PHP templates are no longer compiled on every render. Before them the same ramp test gave a median of 51 ms and a p95 of 104 ms, and locally the single worker was saturated (p95 1.19 s, now 22 ms). Page views also no longer create a guest user; the first message does.

About 2% of fresh connections from the test machine to the server stall or fail, also for static files and other sites on the same server and without load. A host elsewhere showed none. This is the network path, not the app; it explains the rare multi-second outliers in the load tests.

## 7. SSE streaming

Prompt: "Answer directly in the chat and do not create a document: write the numbers from 1 to 300, separated by single spaces. Output nothing else." The answer is 1,091 characters. PHP uses its default model, Claude Haiku 4.5.

**PHP, 10 turns in one chat:**

| Metric | Value |
|--------|-------|
| **SSE connections** | 2 (home page, then the new chat page) |
| **Transferred** | **34.5 KB** |
| **Decoded** | 2,512 KB |
| **Compression** | Brotli, 73x |
| **First turn** | 15.7 KB, including the switch to the chat page |
| **Every further turn** | 1.9 to 2.2 KB |

php-via sends the growing reply as rendered HTML, at most 20 times a second per chat. Brotli on the persistent stream compresses that well because each patch repeats most of the previous one.

**Next.js was not measured again.** The demo now answers chat requests from automated browsers with HTTP 403 (bot protection), and it only offers models the PHP app does not (DeepSeek, Kimi, GPT OSS, Grok). February, 10 turns with Claude Haiku 4.5 on both sides and a different prompt: PHP 55.8 KB transferred (3,265 KB decoded, Brotli 58.5x), Next.js ~114 KB over 10 separate streaming requests without compression.

## 8. DevTools traces (Slow 4G, 4x CPU)

The traces are summarised with `scripts/trace-summary.py`: self time per category on the renderer main threads, and JS heap, DOM nodes and listeners from the trace's `UpdateCounters` events. Its categories differ slightly from the DevTools Performance panel, so the February traces are summarised again with the same script.

**Page load** (cold first visit, median of 3):

| Metric | PHP | Next.js | Ratio | Feb PHP | Feb Next.js |
|--------|-----|---------|-------|---------|-------------|
| **Load event** | **0.63 s** | 8.76 s | 14x | | |
| **Scripting** | **86 ms** | 3,836 ms | 45x | 175 ms | 3,094 ms |
| **Rendering** | **99 ms** | 206 ms | 2.1x | 172 ms | 240 ms |
| **Painting** | **28 ms** | 32 ms | ~tie | 45 ms | 46 ms |
| **JS heap at the end** | **2.2 MB** | 16.4 MB | 7.5x | 2.3 MB | 13.0 MB |
| **JS heap peak** | **2.2 MB** | 20.4 MB | 9.3x | 2.3 MB | 13.7 MB |
| **DOM nodes at the end** | 2,429 | **504** | | 963 | 326 |
| **Event listeners** | **54** | 385 | 7.1x | 59 | 349 |

The Next.js load includes the demo's guest sign-in redirect, as for every first visit and in Lighthouse. The PHP page has more DOM nodes: it arrives fully rendered, including the hidden sign-in and about dialogs.

**Chat response** (one turn of the prompt above, PHP only):

| Metric | PHP October | PHP February | Next.js February |
|--------|-------------|--------------|------------------|
| **Scripting** | **334 ms** | 2,565 ms | 11,662 ms |
| **Rendering** | **317 ms** | 1,763 ms | 1,260 ms |
| **Painting** | **170 ms** | 1,286 ms | 652 ms |
| **JS heap, start → end** | 2.4 → 5.2 MB | 4.0 → 4.5 MB | 17.2 → 21.0 MB |

February's chat traces used a different prompt with a longer answer, so the comparison is rough. The lower PHP figures also come from php-via sending at most 20 updates a second instead of one per token.

---

## Reproduce

```bash
# Lighthouse (desktop and mobile), three runs each
CHROME_PATH=/usr/bin/chromium lighthouse https://chat.zweiundeins.gmbh/ --only-categories=performance --preset=desktop --output=json
CHROME_PATH=/usr/bin/chromium lighthouse https://chat.zweiundeins.gmbh/ --only-categories=performance --output=json

# TTFB, 5 runs (the cookie jar keeps the demo's guest cookie)
for i in 1 2 3 4 5; do curl -s -o /dev/null -L -c jar -b jar -w '%{time_starttransfer}\n' https://chatbot.ai-sdk.dev/demo; done

# Traces and SSE bytes
node benchmarks/scripts/browser-bench.mjs load php benchmarks/results/2026-10
node benchmarks/scripts/browser-bench.mjs chat php 10 benchmarks/results/2026-10
node benchmarks/scripts/browser-bench.mjs chat php 1 benchmarks/results/2026-10 --throttle
python3 benchmarks/scripts/trace-summary.py benchmarks/results/2026-10/trace-php-load.json.gz

# Load tests
k6 run benchmarks/scripts/load-test.js
k6 run --vus 10 --duration 15s - <<< "import http from 'k6/http'; export default () => http.get('https://chat.zweiundeins.gmbh/');"

# Dependencies
composer install --no-dev --optimize-autoloader && composer show | wc -l && du -sh vendor
pnpm install --prod --frozen-lockfile --ignore-scripts
ls node_modules/.pnpm | grep -v -e '^lock.yaml$' -e '^node_modules$' | sed 's/_.*//' | sort -u | wc -l
du -sh node_modules
```

## Raw data

October 2026 in [`results/2026-10/`](results/2026-10/): Lighthouse reports (the median run per site and preset), load and chat traces. February 2026 in [`results/`](results/): Lighthouse reports, traces and flamechart screenshots.
