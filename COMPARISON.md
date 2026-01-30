# Architecture Comparison: Next.js vs PHP/Swoole

A side-by-side comparison of two implementations of the same AI chatbot application.

## Overview

| Aspect | Next.js (AI SDK) | PHP/Swoole (Datastar) |
|--------|------------------|------------------------|
| **Runtime** | Node.js (Edge/Serverless) | Swoole (persistent process) |
| **Framework** | React + Vercel AI SDK | Mezzio + Datastar |
| **State Location** | Client (React state) | Server (HTML fragments) |
| **Rendering** | Client-side hydration | Server-side HTML |
| **Database** | Drizzle ORM + Postgres | PDO + SQLite |
| **Type Safety** | TypeScript | PHPStan |

---

## Table of Contents

1. [Streaming Architecture](#1-streaming-architecture)
2. [Routing & Request Handling](#2-routing--request-handling)
3. [Database & Persistence](#3-database--persistence)
4. [Authentication](#4-authentication)
5. [Templating & UI Rendering](#5-templating--ui-rendering)
6. [Error Handling](#6-error-handling)
7. [Testing](#7-testing)
8. [Deployment](#8-deployment)

---

## 1. Streaming Architecture

Both use **Server-Sent Events (SSE)** under the hood, but differ in state management.

### Key Differences

### 1. State Management

**Next.js**: Client owns the state
```tsx
// React state holds messages, updated by useChat hook
const { messages, setMessages } = useChat({
  onData: (dataPart) => setDataStream([...ds, dataPart]),
});
```

**PHP/Swoole**: Server owns the state
```php
// Server emits HTML fragments, DOM is the state
$this->eventBus->emit($userId, new MessageStreamingEvent(
    chunk: $chunk,
    messageId: $messageId,
));
```

### 2. Stream Data Format

**Next.js**: JSON data parts
```
data: {"type":"text-delta","textDelta":"Hello"}
data: {"type":"data-chat-title","data":"My Chat Title"}
```

**PHP/Swoole**: Datastar HTML patches
```
event: datastar-patch-elements
data: selector #message-123-content
data: mergeMode append
data: fragments <span>Hello</span>
```

### 3. Client Complexity

**Next.js**: Heavy client with hooks & context
- `useChat` hook manages conversation state
- `DataStreamProvider` context for custom data
- `DataStreamHandler` processes artifact updates
- SWR for cache invalidation

**PHP/Swoole**: Minimal client with declarative attributes
- Datastar attributes (`data-on:click`, `data-get`)
- No JavaScript state management
- Server pushes complete UI updates

### 4. AI Response Handling

**Next.js**: AI SDK abstracts streaming
```typescript
const result = streamText({
  model: getLanguageModel(modelId),
  messages: modelMessages,
  tools: { createDocument, updateDocument },
});
dataStream.merge(result.toUIMessageStream());
```

**PHP/Swoole**: Manual streaming with coroutines
```php
foreach ($this->aiService->streamChat($history, $model) as $chunk) {
    $this->eventBus->emit($userId, new MessageStreamingEvent(
        chunk: $chunk,
        messageId: $messageId,
    ));
}
```

### 5. Tool/Artifact Streaming

**Next.js**: Data parts interleaved with text
```typescript
// In tool execution
dataStream.write({ type: "data-id", data: documentId });
dataStream.write({ type: "data-kind", data: "code" });
// Client's DataStreamHandler updates artifact state
```

**PHP/Swoole**: Separate document events
```php
// After tool execution
$this->eventBus->emit($userId, new DocumentUpdatedEvent(
    document: $document,
    action: 'created',
));
// SseRequestListener renders document preview HTML
```

### 6. Resumable Streams

**Next.js**: Redis-backed stream persistence
```typescript
const streamContext = createResumableStreamContext({ waitUntil: after });
await streamContext.createNewResumableStream(streamId, () => sseStream);
```

**PHP/Swoole**: Session-based stream tracking
```php
$this->sessionManager->startSession($chatId, $userId, $messageId);
// Can check isStopRequested() to cancel mid-stream
```

---

## Similarities

1. **SSE Transport** - Both use Server-Sent Events for real-time updates
2. **Chunked Streaming** - AI responses stream token-by-token
3. **Tool Support** - Both support function calling for documents/artifacts
4. **Multi-model** - Both support switching between AI providers/models

---

## 2. Performance Benchmarks

**Tested January 30, 2026** against production deployments:
- PHP: https://chat.zweiundeins.gmbh ($20/year VPS)
- Next.js: https://demo.chat-sdk.dev (Vercel serverless)

> **Note:** The Next.js project has **86 contributors** and **600+ commits** of optimization. The PHP project is a straightforward port with minimal optimization—yet still outperforms on most metrics.

### Lighthouse Scores (Desktop, Chrome 144)

| Metric | PHP/Swoole | Next.js | Winner |
|--------|------------|---------|--------|
| **Performance Score** | **100** | 92 | 🏆 PHP |
| **First Contentful Paint** | 0.7s | **0.3s** | Next.js |
| **Largest Contentful Paint** | **0.7s** | 1.6s | 🏆 PHP |
| **Total Blocking Time** | **0ms** | 130ms | 🏆 PHP |
| **Time to Interactive** | **0.7s** | 1.6s | 🏆 PHP |
| **Cumulative Layout Shift** | 0.015 | **0** | Next.js |

### Transfer Size

| Resource | PHP/Swoole | Next.js | Ratio |
|----------|------------|---------|-------|
| **Total** | **230 KB** | 1.26 MB | 5.5x |
| **JavaScript** | **28 KB** | 1.09 MB | **39x** |
| **HTTP Requests** | **17** | 39 | 2.3x |

### TTFB (Time to First Byte)

| Site | Warm | Cold Start |
|------|------|------------|
| **PHP** | ~149ms | N/A (persistent) |
| **Next.js** | ~170ms | **1.85s** (serverless) |

### Load Test (k6)

**With only 1 Swoole worker:**

| Metric | Localhost | Production VPS |
|--------|-----------|----------------|
| **Throughput** | **~200 req/s** | **~44 req/s** |
| **Response Time (avg)** | **7.25ms** | 225ms |
| **Response Time (p95)** | **9.26ms** | 385ms |

> A single Swoole worker handles 200 req/s at 7ms. Production limited by network latency.

### Codebase Size

| Metric | PHP/Swoole | Next.js | Ratio |
|--------|------------|---------|-------|
| **Dependencies (installed)** | **140** | 921 | 6.6x |
| **node_modules / vendor** | **76 MB** | 986 MB | **13x** |

### Analysis

- **PHP wins on interactivity**: 0ms TBT means the page is instantly usable. Next.js must hydrate 1.09 MB of JavaScript.
- **Next.js wins on initial paint**: Edge CDN delivers the shell faster, but then blocks for hydration.
- **39x less JavaScript**: Datastar (28 KB) vs React runtime + app code (1.09 MB).
- **No cold starts**: Swoole's persistent process eliminates the 1.85s serverless penalty.

---

## 3. Routing & Request Handling

*TODO: Compare App Router vs Mezzio handlers, CQRS pattern*

---

## 3. Database & Persistence

*TODO: Compare Drizzle ORM vs Repository pattern with PDO*

---

## 4. Authentication

*TODO: Compare NextAuth vs custom session handling*

---

## 5. Templating & UI Rendering

*TODO: Compare React components vs PHP templates*

---

## 6. Error Handling

*TODO: Compare ChatSDKError vs domain exceptions*

---

## 7. Testing

*TODO: Compare Playwright/Vitest vs Pest*

---

## 8. Deployment

*TODO: Compare Vercel serverless vs Swoole persistent process*

---

## Summary: Trade-offs

| | Next.js (AI SDK) | PHP/Swoole (Datastar) |
|---|------------------|------------------------|
| **Pros** | Rich ecosystem, type-safe, resumable streams, faster FCP via edge CDN | **100 Lighthouse score**, 0ms TBT, 39x less JS, $20/year hosting, no cold starts |
| **Cons** | 1.09 MB JavaScript, 1.85s cold starts, 921 dependencies, complex state sync | Requires Swoole, smaller ecosystem |
| **Best for** | Complex SPAs, offline-first apps | Performance-critical apps, low-cost hosting, progressive enhancement |
