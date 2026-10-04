// Browser benchmarks for both chat apps, driven through the Chrome DevTools Protocol.
//
//   node benchmarks/scripts/browser-bench.mjs load <php|nextjs> <outdir>
//   node benchmarks/scripts/browser-bench.mjs chat <php|nextjs> <turns> <outdir> [--throttle]
//
// load: cold first visit under Slow 4G + 4x CPU, saved as a trace (summarise with trace-summary.py).
// chat: sends the same prompt <turns> times and records the streamed bytes per turn
//       (encoded = over the wire, decoded = after decompression). --throttle adds Slow 4G + 4x CPU
//       and a trace, for the chat-response trace.
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const SITES = {
    php: { url: 'https://chat.zweiundeins.gmbh/', stream: (r) => new URL(r.url).pathname === '/_sse' },
    nextjs: { url: 'https://chatbot.ai-sdk.dev/demo', stream: (r) => r.method === 'POST' && new URL(r.url).pathname.endsWith('/api/chat') },
};
const PROMPT = 'Answer directly in the chat and do not create a document: write the numbers from 1 to 300, separated by single spaces. Output nothing else.';
// Lighthouse's Slow 4G: 150 ms RTT, 1.6 Mbps down, 750 Kbps up
const SLOW_4G = { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 };
const TRACE_CATEGORIES = ['devtools.timeline', 'disabled-by-default-devtools.timeline', 'v8.execute', 'blink.user_timing', 'loading', 'latencyInfo', 'disabled-by-default-devtools.timeline.frame'];

const [mode, siteName, ...rest] = process.argv.slice(2);
const site = SITES[siteName];
if (!site || !['load', 'chat'].includes(mode)) {
    console.error('usage: browser-bench.mjs load|chat php|nextjs ...');
    process.exit(1);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium' });
const context = await browser.newContext({ viewport: { width: 1350, height: 940 } });
const page = await context.newPage();
const cdp = await context.newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Performance.enable');

const metrics = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
const delta = (a, b) => ({
    scriptingMs: Math.round((b.ScriptDuration - a.ScriptDuration) * 1000),
    layoutMs: Math.round((b.LayoutDuration - a.LayoutDuration) * 1000),
    styleMs: Math.round((b.RecalcStyleDuration - a.RecalcStyleDuration) * 1000),
    taskMs: Math.round((b.TaskDuration - a.TaskDuration) * 1000),
    heapUsedMB: +(b.JSHeapUsedSize / 1048576).toFixed(1),
    heapGrowthMB: +((b.JSHeapUsedSize - a.JSHeapUsedSize) / 1048576).toFixed(1),
    nodes: [a.Nodes, b.Nodes],
    listeners: [a.JSEventListeners, b.JSEventListeners],
});

async function throttle() {
    await cdp.send('Network.emulateNetworkConditions', SLOW_4G);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
}

async function startTrace() {
    const events = [];
    cdp.on('Tracing.dataCollected', (e) => events.push(...e.value));
    await cdp.send('Tracing.start', { categories: TRACE_CATEGORIES.join(','), transferMode: 'ReportEvents' });
    return async (file) => {
        const done = new Promise((resolve) => cdp.once('Tracing.tracingComplete', resolve));
        await cdp.send('Tracing.end');
        await done;
        writeFileSync(file, gzipSync(JSON.stringify({ traceEvents: events })));
    };
}

if (mode === 'load') {
    // A first visit in a fresh context: cold cache, and on the Vercel demo the guest sign-in redirect,
    // which Lighthouse and every new visitor go through as well
    const [outdir] = rest;
    mkdirSync(outdir, { recursive: true });
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await throttle();
    const stopTrace = await startTrace();
    const t0 = Date.now();
    await page.goto(site.url, { waitUntil: 'load', timeout: 120000 });
    const loadMs = Date.now() - t0;
    await page.waitForTimeout(5000);
    await stopTrace(`${outdir}/trace-${siteName}-load.json.gz`);
    const result = { site: siteName, loadEventMs: loadMs };
    writeFileSync(`${outdir}/${siteName}-load.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
} else {
    const [turnsArg, outdir, flag] = rest;
    const turns = Number(turnsArg);
    mkdirSync(outdir, { recursive: true });

    // Bytes per stream request: encodedDataLength from dataReceived, or from loadingFinished when a response
    // reports its encoded size only at the end
    const streams = new Map();
    cdp.on('Network.requestWillBeSent', (e) => {
        if (site.stream({ url: e.request.url, method: e.request.method })) streams.set(e.requestId, { encoded: 0, decoded: 0, finishedEncoded: null, encoding: null });
    });
    cdp.on('Network.responseReceived', (e) => {
        const s = streams.get(e.requestId);
        if (s) s.encoding = e.response.headers['content-encoding'] ?? e.response.headers['Content-Encoding'] ?? null;
    });
    cdp.on('Network.dataReceived', (e) => {
        const s = streams.get(e.requestId);
        if (s) {
            s.encoded += e.encodedDataLength;
            s.decoded += e.dataLength;
        }
    });
    cdp.on('Network.loadingFinished', (e) => {
        const s = streams.get(e.requestId);
        if (s) s.finishedEncoded = e.encodedDataLength;
    });
    const totals = () => {
        let encoded = 0;
        let decoded = 0;
        for (const s of streams.values()) {
            encoded += s.finishedEncoded ?? s.encoded;
            decoded += s.decoded;
        }
        return { encoded, decoded, connections: streams.size, encodings: [...new Set([...streams.values()].map((s) => s.encoding))] };
    };

    await page.goto(site.url, { waitUntil: 'load', timeout: 120000 });
    await page.waitForTimeout(1500);
    const throttled = flag === '--throttle';
    if (throttled) await throttle();

    const answers = () => page.evaluate(() => (document.body.innerText.match(/298 299 300/g) ?? []).length);
    const perTurn = [];
    let stopTrace = null;
    let before = null;
    if (throttled) {
        before = await metrics();
        stopTrace = await startTrace();
    }
    const start = totals();
    for (let turn = 1; turn <= turns; turn++) {
        const t = totals();
        const t0 = Date.now();
        const input = page.locator('textarea').first();
        await input.click();
        await input.fill(PROMPT);
        await input.press('Enter');
        // Done when the answer is complete and the stream has gone quiet
        await page.waitForFunction((n) => (document.body.innerText.match(/298 299 300/g) ?? []).length >= n, turn, { timeout: 60000, polling: 250 }).catch(async (e) => {
            await page.screenshot({ path: `${outdir}/${siteName}-timeout-turn-${turn}.png` });
            console.error('page text ends with:', JSON.stringify((await page.evaluate(() => document.body.innerText)).slice(-600)));
            throw e;
        });
        let last = totals().decoded;
        for (;;) {
            await page.waitForTimeout(1500);
            const now = totals().decoded;
            if (now === last) break;
            last = now;
        }
        const u = totals();
        perTurn.push({ turn, ms: Date.now() - t0, encoded: u.encoded - t.encoded, decoded: u.decoded - t.decoded });
        console.log(JSON.stringify(perTurn.at(-1)));
    }
    const end = totals();
    const answerChars = await page.evaluate(() => {
        const m = document.body.innerText.match(/1 2 3 4 5[\d ]*300/);
        return m ? m[0].length : null;
    });
    const result = {
        site: siteName,
        turns,
        throttled,
        model: 'default of each app',
        prompt: PROMPT,
        answerChars,
        encodings: end.encodings,
        streamConnections: end.connections,
        encodedBytes: end.encoded - start.encoded,
        decodedBytes: end.decoded - start.decoded,
        perTurn,
    };
    if (throttled) {
        result.performance = delta(before, await metrics());
        await stopTrace(`${outdir}/trace-${siteName}-chat.json.gz`);
    }
    writeFileSync(`${outdir}/${siteName}-chat-${turns}${throttled ? '-throttled' : ''}.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify({ ...result, perTurn: undefined }));
    console.log('answers on page:', await answers());
}

await browser.close();
