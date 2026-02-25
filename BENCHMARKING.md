# Benchmarking Guide

**Step-by-step instructions for running performance benchmarks and generating results.**

This guide walks you through running fair, reproducible performance comparisons between the PHP/Swoole implementation and the Next.js reference implementation. All results will be saved to `benchmarks/results/`.

## Prerequisites

Install the required tools:

```bash
# Lighthouse CLI
npm install -g lighthouse

# k6 for load testing (macOS)
brew install k6

# Or download from https://k6.io/docs/get-started/installation/

# Chrome browser (for DevTools traces)
# Already installed if you're testing locally
```

> **Optional — AI-assisted automation:** Parts 3, 5, and 6 of this guide can be driven interactively by an AI assistant with the `chrome-devtools-mcp` tool connected. Start it with:
> ```bash
> npx chrome-devtools-mcp@latest
> ```
> Each automation opportunity is marked with a 🤖 note in the relevant section.

## Test URLs

Update these if testing different deployments:

| App | Production URL | Local URL |
|-----|---------------|-----------|
| **PHP/Swoole** | `https://chat.zweiundeins.gmbh` | `http://localhost:8080` |
| **Next.js** | `https://demo.chat-sdk.dev/` | `http://localhost:3000` |

| App | Production URL | Local URL |
|-----|---------------|-----------|
| **PHP/Swoole** | `https://chat.zweiundeins.gmbh` | `http://localhost:8080` |
| **Next.js** | `https://demo.chat-sdk.dev/` | `http://localhost:3000` |

---

## Part 1: Lighthouse Performance Tests

Lighthouse measures page load performance, accessibility, and best practices.

### Step 1.1: Desktop Benchmark (Both Apps)

```bash
cd benchmarks

# PHP/Swoole - Desktop
lighthouse https://chat.zweiundeins.gmbh \
  --preset=desktop \
  --output json \
  --output-path ./results/php-lighthouse-desktop.json \
  --chrome-flags="--headless"

# Next.js - Desktop
lighthouse https://demo.chat-sdk.dev/ \
  --preset=desktop \
  --output json \
  --output-path ./results/nextjs-lighthouse-desktop.json \
  --chrome-flags="--headless"
```

**Expected output:** Two JSON files in `benchmarks/results/`

### Step 1.2: Mobile Benchmark (Simulated Slow 4G)

```bash
# PHP/Swoole - Mobile
lighthouse https://chat.zweiundeins.gmbh \
  --preset=desktop \
  --emulated-form-factor=mobile \
  --throttling-method=devtools \
  --throttling.cpuSlowdownMultiplier=4 \
  --output json \
  --output-path ./results/php-lighthouse-mobile.json \
  --chrome-flags="--headless"

# Next.js - Mobile
lighthouse https://demo.chat-sdk.dev/ \
  --preset=desktop \
  --emulated-form-factor=mobile \
  --throttling-method=devtools \
  --throttling.cpuSlowdownMultiplier=4 \
  --output json \
  --output-path ./results/nextjs-lighthouse-mobile.json \
  --chrome-flags="--headless"
```

**Expected output:** Two more JSON files (mobile results)

### Step 1.3: Extract Metrics

Run this script to extract key metrics from the JSON files:

```bash
# Create a quick extraction script
cat > scripts/extract-lighthouse.js << 'EOF'
#!/usr/bin/env node
const fs = require('fs');

const files = [
  'php-lighthouse-desktop.json',
  'php-lighthouse-mobile.json',
  'nextjs-lighthouse-desktop.json',
  'nextjs-lighthouse-mobile.json'
];

console.log('| Metric | PHP/Swoole | Next.js |');
console.log('|--------|------------|---------|');

files.forEach(file => {
  const data = JSON.parse(fs.readFileSync(`./results/${file}`, 'utf8'));
  const audits = data.audits;
  
  console.log(`\n**${file}**`);
  console.log(`Performance Score: ${data.categories.performance.score * 100}`);
  console.log(`FCP: ${audits['first-contentful-paint'].displayValue}`);
  console.log(`LCP: ${audits['largest-contentful-paint'].displayValue}`);
  console.log(`TBT: ${audits['total-blocking-time'].displayValue}`);
  console.log(`TTI: ${audits['interactive'].displayValue}`);
  console.log(`CLS: ${audits['cumulative-layout-shift'].displayValue}`);
});
EOF

chmod +x scripts/extract-lighthouse.js
node scripts/extract-lighthouse.js
```

**Expected output:** Formatted metrics table ready for RESULTS.md

---

## Part 2: Load Testing (k6)

Test how each app performs under concurrent user load.

### Step 2.1: Run Load Test on PHP/Swoole

```bash
cd benchmarks

# Test PHP/Swoole
k6 run --env BASE_URL=https://chat.zweiundeins.gmbh scripts/load-test.js

# Results saved to: ./results/k6-summary.json
```

### Step 2.2: Run Load Test on Next.js

```bash
# Test Next.js (modify script to save different file)
k6 run --env BASE_URL=https://demo.chat-sdk.dev/ scripts/load-test.js \
  --out json=./results/k6-nextjs-summary.json
```

### Step 2.3: Compare Results

```bash
# View side-by-side comparison
cat > scripts/compare-k6.sh << 'EOF'
#!/bin/bash
echo "=== k6 Load Test Comparison ==="
echo ""
echo "PHP/Swoole:"
cat results/k6-summary.json | jq '.metrics.http_req_duration.values | {avg, p95, p99}'
echo ""
echo "Next.js:"
cat results/k6-nextjs-summary.json | jq '.metrics.http_req_duration.values | {avg, p95, p99}'
EOF

chmod +x scripts/compare-k6.sh
./scripts/compare-k6.sh
```

**Expected output:** Response time comparison (avg, p95, p99)

---

## Part 3: Chrome DevTools Performance Traces

Capture detailed frame-by-frame rendering analysis during AI streaming.

> **🤖 Automation available:** If the `chrome-devtools-mcp` tool is connected to your AI assistant (VS Code Copilot), Parts 3, 5, and 6 can be driven interactively — the AI can navigate to the URL, start a trace, send a chat message, stop the trace, and extract CWV scores — without manual DevTools interaction.

### Step 3.1: Manual Trace Recording

**For PHP/Swoole:**

1. Open Chrome and navigate to `https://chat.zweiundeins.gmbh`
2. Open DevTools (F12) → **Performance** tab
3. Click **Record** (Ctrl+E)
4. Start a new chat and send a message that triggers AI streaming
5. Wait for the full response to complete (~30 seconds)
6. Stop recording
7. **Save trace:**
   - Right-click in the timeline → **Save profile**
   - Save as `benchmarks/results/trace-php-streaming-YYYYMMDD.json`
8. **Export screenshot:**
   - Zoom to show the streaming section
   - Take screenshot → Save as `benchmarks/results/php-streaming-flamechart-YYYYMMDD.png`

**For Next.js:**

Repeat the same steps for `https://demo.chat-sdk.dev/`, saving files as:
- `trace-nextjs-streaming-YYYYMMDD.json`
- `nextjs-streaming-flamechart-YYYYMMDD.png`

### Step 3.2: Analyze Traces

Load the trace files in Chrome DevTools and extract:

| Metric | Where to Find | What to Look For |
|--------|---------------|------------------|
| **Frame Rate** | Top ruler | Should stay at 60fps (green) |
| **Main Thread Blocking** | Main thread (yellow blocks) | Long tasks >50ms cause jank |
| **JavaScript Time** | Bottom-Up tab → Filter by "script" | Total JS execution time |
| **Rendering Time** | Bottom-Up tab → Filter by "rendering" | Time spent painting DOM |

**Key findings to document:**
- Does the app maintain 60fps during streaming?
- Are there long JavaScript tasks blocking the main thread?
- Total time spent in JS vs rendering/painting

---

## Part 4: Testing with Identical LLM Responses

**Critical for fairness:** Ensure both apps process the same content.

### Step 4.1: Create a Test Fixture

```bash
# Create fixtures directory
mkdir -p benchmarks/fixtures

# Use a real LLM call and save the response
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-4-5",
    "max_tokens": 500,
    "messages": [{
      "role": "user",
      "content": "Explain React Server Components in exactly 200 words."
    }]
  }' | jq '.' > ./fixtures/standard-response-500tokens.json

# Or create a shorter test fixture manually
cat > ./fixtures/short-response.json << 'EOF'
{
  "prompt": "What is CQRS?",
  "response": "CQRS (Command Query Responsibility Segregation) is an architectural pattern that separates read and write operations into distinct models. Commands modify state, while queries retrieve data.",
  "token_count": 30
}
EOF
```

**Expected output:** `benchmarks/fixtures/*.json` files

### Step 4.2: Document Test Conditions

When running benchmarks with the fixtures, document:

```markdown
## Test Conditions

**Fixture:** `standard-response-500tokens.json`
**Token Count:** 500
**Streaming Duration:** ~30 seconds
**Prompt:** "Explain React Server Components in exactly 200 words."
```

This ensures both implementations process identical content.

---

## Part 5: Multi-Turn Conversation Test

Test how performance changes over multiple messages.

> **🤖 Automation available:** `chrome-devtools-mcp` can drive the real browser UI for this test (navigate → fill input → click send → wait → repeat), testing the actual DOM path rather than raw HTTP like the curl script does. Network timings are pulled directly via `list_network_requests`.

### Step 5.1: Create Test Script

```bash
cat > benchmarks/scripts/multi-turn-test.sh << 'EOF'
#!/bin/bash
set -e

BASE_URL=${1:-"http://localhost:8080"}
OUTPUT_DIR="benchmarks/results/multi-turn-$(date +%Y%m%d-%H%M%S)"

mkdir -p "$OUTPUT_DIR"

echo "Testing 10-message conversation at $BASE_URL"
echo "Results will be saved to: $OUTPUT_DIR"
echo ""

for i in {1..10}; do
  echo "Message $i/10..."
  
  START=$(date +%s%N)
  
  curl -s -w "%{time_total}\n" \
    -o "$OUTPUT_DIR/response-$i.txt" \
    "$BASE_URL/cmd/chat/test-$(uuidgen)/message" \
    -d "{\"content\":\"Explain concept $i in 100 words\"}" \
    > "$OUTPUT_DIR/time-$i.txt" 2>&1
  
  DURATION=$(cat "$OUTPUT_DIR/time-$i.txt")
  echo "$i,$DURATION" >> "$OUTPUT_DIR/timings.csv"
  
  sleep 1
done

echo ""
echo "Complete! Results:"
cat "$OUTPUT_DIR/timings.csv" | awk -F, '{sum+=$2; count++; print "Message "$1": "$2"s"} END {print "\nAverage: "sum/count"s"}'
EOF

chmod +x benchmarks/scripts/multi-turn-test.sh
```

### Step 5.2: Run Tests

```bash
cd benchmarks

# Test PHP/Swoole
./scripts/multi-turn-test.sh https://chat.zweiundeins.gmbh

#Test Next.js
./scripts/multi-turn-test.sh https://demo.chat-sdk.dev/

# Results saved to benchmarks/results/multi-turn-YYYYMMDD-HHMMSS/
```

**Expected output:** CSV file with response times for each message, showing any degradation over time.

---

## Part 6: Network Transfer Analysis

Measure actual bytes transferred during streaming.

> **🤖 Automation available:** `chrome-devtools-mcp`'s `list_network_requests` replaces the manual HAR export — trigger a streaming session and query all request sizes in-session, no file download needed.

### Step 6.1: Capture Network Traffic

1. Open Chrome DevTools → **Network** tab
2. Check "Disable cache"
3. Clear existing requests
4. Start a chat and send a message that triggers streaming
5. Wait for complete response
6. Right-click on any request → **Save all as HAR with content**
7. Save to `benchmarks/results/network-php-streaming.har`

Repeat for Next.js → `network-nextjs-streaming.har`

### Step 6.2: Analyze HAR Files

```bash
cat > benchmarks/scripts/analyze-har.js << 'EOF'
#!/usr/bin/env node
const fs = require('fs');

const file = process.argv[2];
if (!file) {
  console.error('Usage: node analyze-har.js <file.har>');
  process.exit(1);
}

const har = JSON.parse(fs.readFileSync(file, 'utf8'));
const entries = har.log.entries;

let stats = {
  totalSize: 0,
  transferredSize: 0,
  requests: entries.length,
  byType: {}
};

entries.forEach(entry => {
  const size = entry.response.content.size || 0;
  const transferred = entry.response._transferSize || size;
  
  stats.totalSize += size;
  stats.transferredSize += transferred;
  
  const mimeType = entry.response.content.mimeType.split(';')[0];
  if (!stats.byType[mimeType]) {
    stats.byType[mimeType] = {size: 0, count: 0};
  }
  stats.byType[mimeType].size += size;
  stats.byType[mimeType].count++;
});

console.log(`Total Requests: ${stats.requests}`);
console.log(`Total Size: ${(stats.totalSize / 1024).toFixed(2)} KB`);
console.log(`Transferred: ${(stats.transferredSize / 1024).toFixed(2)} KB`);
console.log(`Compression: ${((1 - stats.transferredSize/stats.totalSize) * 100).toFixed(1)}%`);
console.log(`\nBy Content Type:`);
Object.entries(stats.byType).forEach(([type, data]) => {
  console.log(`  ${type}: ${(data.size / 1024).toFixed(2)} KB (${data.count} requests)`);
});
EOF

chmod +x benchmarks/scripts/analyze-har.js

# Run analysis
echo "=== PHP/Swoole ==="
node benchmarks/scripts/analyze-har.js benchmarks/results/network-php-streaming.har

echo ""
echo "=== Next.js ==="
node benchmarks/scripts/analyze-har.js benchmarks/results/network-nextjs-streaming.har
```

**Expected output:** Breakdown of total transfer size, compression ratio, requests by type.

---

## Part 7: CLS Improvements

Fix Cumulative Layout Shift to match Next.js (goal: 0).

### Step 7.1: Current CLS Score

```bash
# Check current CLS
lighthouse http://localhost:8080 \
  --preset=desktop \
  --emulated-form-factor=mobile \
  --output json \
  --output-path ./benchmarks/results/cls-before.json

# Extract CLS
cat benchmarks/results/cls-before.json | jq '.audits["cumulative-layout-shift"].displayValue'
```

### Step 7.2: Add Image Dimensions

Find and fix images without explicit dimensions:

```bash
# Find images without width/height
grep -r '<img' templates/ --include="*.php" | grep -v 'width=' | grep -v 'height='

# Example fix in templates/partials/user-avatar.php
```

Before:
```php
<img src="<?= $avatarUrl ?>" alt="<?= $userName ?>" class="avatar" />
```

After:
```php
<img src="<?= $avatarUrl ?>" alt="<?= $userName ?>" class="avatar" width="40" height="40" />
```

### Step 7.3: Add Container Min-Heights

Edit `public/css/app.css`:

```css
/* Prevent layout shift during content load */
.message-container {
  min-height: 80px;
}

.code-artifact {
  min-height: 400px;
  aspect-ratio: 16 / 9;
}

/* Skeleton loading state */
.message-loading::before {
  content: '';
  display: block;
  height: 60px;
  background: linear-gradient(90deg, 
    rgba(0,0,0,0.05) 25%, 
    rgba(0,0,0,0.02) 50%, 
    rgba(0,0,0,0.05) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
```

### Step 7.4: Verify Improvements

```bash
# Re-test
lighthouse http://localhost:8080 \
  --preset=desktop \
  --emulated-form-factor=mobile \
  --output json \
  --output-path ./benchmarks/results/cls-after.json

# Compare before/after
echo "Before: $(cat benchmarks/results/cls-before.json | jq '.audits["cumulative-layout-shift"].displayValue')"
echo "After: $(cat benchmarks/results/cls-after.json | jq '.audits["cumulative-layout-shift"].displayValue')"
```

**Goal:** CLS < 0.01

---

## Part 8: Generate Final Report

Compile all benchmark data into `benchmarks/RESULTS.md`.

### Step 8.1: Create Report Generator

```bash
cat > benchmarks/scripts/generate-report.sh << 'EOF'
#!/bin/bash

DATE=$(date +"%B %d, %Y")
OUTPUT="benchmarks/RESULTS.md"

cat > "$OUTPUT" << HEADER
# Benchmark Results: Next.js vs PHP/Swoole

**Date:** $DATE

## Production URLs

| App | URL | Hosting |
|-----|-----|---------|
| **PHP/Swoole** | https://chat.zweiundeins.gmbh | \\$20/year VPS (2 vCPU, 4GB RAM) |
| **Next.js** | https://demo.chat-sdk.dev/ | Vercel (serverless) |

---

## ✅ Completed Benchmarks

HEADER

# Add Lighthouse results
if [ -f "results/php-lighthouse-desktop.json" ]; then
  echo "### 1. Lighthouse (Desktop)" >> "$OUTPUT"
  echo "" >> "$OUTPUT"
  # Extract and format metrics
  node scripts/extract-lighthouse.js >> "$OUTPUT"
fi

# Add k6 results
if [ -f "results/k6-summary.json" ]; then
  echo "" >> "$OUTPUT"
  echo "### 2. Load Testing (k6)" >> "$OUTPUT"
  echo "" >> "$OUTPUT"
  cat results/k6-summary.json | jq -r '.metrics.http_req_duration.values | "- Avg: \(.avg)ms\n- p95: \(.["p(95)"])ms\n- p99: \(.["p(99)"])ms"' >> "$OUTPUT"
fi

# Add raw data section
cat >> "$OUTPUT" << FOOTER

---

## 📁 Raw Benchmark Data

All raw data is available in [\`benchmarks/results/\`](results/):

**Lighthouse Reports:**
- [php-lighthouse-desktop.json](results/php-lighthouse-desktop.json)
- [php-lighthouse-mobile.json](results/php-lighthouse-mobile.json)
- [nextjs-lighthouse-desktop.json](results/nextjs-lighthouse-desktop.json)
- [nextjs-lighthouse-mobile.json](results/nextjs-lighthouse-mobile.json)

**Performance Traces:**
- [trace-php-streaming.json](results/trace-php-streaming.json)
- [trace-nextjs-streaming.json](results/trace-nextjs-streaming.json)

**Network Analysis:**
- [network-php-streaming.har](results/network-php-streaming.har)
- [network-nextjs-streaming.har](results/network-nextjs-streaming.har)
FOOTER

echo "✓ Report generated: $OUTPUT"
EOF

chmod +x benchmarks/scripts/generate-report.sh
```

### Step 8.2: Generate Report

```bash
cd benchmarks
./scripts/generate-report.sh

# View results
cat RESULTS.md
```

**Expected output:** Complete `benchmarks/RESULTS.md` with all metrics formatted.

---

## Final Checklist

Before committing results:

- [ ] All Lighthouse tests run (desktop + mobile, both apps)
- [ ] k6 load tests completed for both apps
- [ ] DevTools performance traces captured
- [ ] Network HAR files saved
- [ ] Multi-turn conversation tests run
- [ ] CLS fixes implemented and verified
- [ ] All raw data files in `benchmarks/results/`
- [ ] Report generated with `generate-report.sh`
- [ ] Screenshots added (`php_flamechart.png`, etc.)
- [ ] Test conditions documented (browser version, network throttling, etc.)

---

## Quick Command Reference

```bash
# All tests in sequence
cd benchmarks

# 1. Lighthouse Desktop
lighthouse https://chat.zweiundeins.gmbh --preset=desktop \
  --output json --output-path ./results/php-lighthouse-desktop.json

# 2. Lighthouse Mobile  
lighthouse https://chat.zweiundeins.gmbh --preset=desktop \
  --emulated-form-factor=mobile --throttling.cpuSlowdownMultiplier=4 \
  --output json --output-path ./results/php-lighthouse-mobile.json

# 3. Load test
k6 run --env BASE_URL=https://chat.zweiundeins.gmbh scripts/load-test.js

# 4. Multi-turn test
./scripts/multi-turn-test.sh https://chat.zweiundeins.gmbh

# 5. Generate final report
./scripts/generate-report.sh
```

---

## Troubleshooting

**Lighthouse timeout errors:**
```bash
# Increase timeout
lighthouse <URL> --max-wait-for-load=90000 --timeout-config=120000
```

**k6 connection refused:**
```bash
# Verify server is running
curl -I http://localhost:8080

# Check firewall settings
```

**HAR file won't open:**
```bash
# Validate JSON
jq '.' network-capture.har > /dev/null && echo "Valid" || echo "Invalid"
```

**Out of memory errors:**
```bash
# Reduce test load
# Edit scripts/load-test.js and decrease target users
```

---

## Further Resources

- [Lighthouse CI](https://github.com/GoogleChrome/lighthouse-ci) - Automated testing in CI/CD
- [k6 Documentation](https://k6.io/docs/) - Advanced load testing
- [HAR Analyzer](https://toolbox.googleapps.com/apps/har_analyzer/) - Online HAR viewer
- [Chrome DevTools Performance](https://developer.chrome.com/docs/devtools/performance/) - Performance profiling
- [SPA vs Hypermedia Article](https://zweiundeins.gmbh/en/methodology/spa-vs-hypermedia-real-world-performance-under-load) - Context for this comparison
