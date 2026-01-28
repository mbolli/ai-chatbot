# Copilot Instructions - AI Chatbot

## Architecture Overview

This is a **PHP/Swoole** AI chatbot application using **CQRS** pattern with real-time **SSE streaming** via Datastar frontend framework.

```
┌─────────────────────────────────────────────────────────────────┐
│  Frontend (Datastar + TypeScript)                               │
│  └── SSE connection to /updates for real-time DOM patching      │
├─────────────────────────────────────────────────────────────────┤
│  Infrastructure Layer                                           │
│  ├── Http/Handler/Command/  → POST/PUT/DELETE (messages, chats) │
│  ├── Http/Handler/Query/    → GET requests                      │
│  ├── Http/Listener/         → SseRequestListener for streaming  │
│  └── AI/                    → LLPhantAIService, streaming tools │
├─────────────────────────────────────────────────────────────────┤
│  Application Layer (Events)                                     │
│  ├── Domain/Event/          → MessageStreamingEvent, ChatUpdated│
│  └── EventBus/              → SwooleEventBus for SSE broadcasts │
├─────────────────────────────────────────────────────────────────┤
│  Domain Layer                                                   │
│  ├── Model/                 → Chat, Message, Document           │
│  ├── Service/               → AIServiceInterface, RateLimitSvc  │
│  └── Repository/            → Interface definitions             │
├─────────────────────────────────────────────────────────────────┤
│  SQLite (data/db.sqlite)                                        │
└─────────────────────────────────────────────────────────────────┘
```

## Key Patterns

### AI Streaming Flow
1. User sends message via POST `/cmd/chat/{chatId}/send`
2. `MessageCommandHandler` creates user + assistant message placeholders
3. Coroutine starts `streamAiResponse()` which calls `AIService::streamChat()`
4. Each chunk emits `MessageStreamingEvent` via `EventBus`
5. `SseRequestListener` receives events and sends `PatchElements` to client
6. Client's Datastar appends chunks to message content in real-time

### AI Service Implementation
- `LLPhantAIService` wraps LLPhant library for Anthropic/OpenAI
- **Important**: LLPhant's default streaming buffers entire response before returning
- For true streaming, implement custom HTTP client with SSE parsing
- Models defined in `ANTHROPIC_MODELS` and `OPENAI_MODELS` constants

### Error Handling for AI Responses
- Always validate `$fullContent` is not empty before saving
- Catch exceptions in `streamAiResponse()` and provide user-friendly messages
- Log errors with `error_log()` for debugging
- Empty responses should trigger retry or error notification

### Datastar Frontend Conventions
- Signals are **only for client state** (e.g., form inputs, modal visibility, `_isGenerating`)
- Server responses should return **HTML via PatchElements**, not signals
- Always use the **Datastar SDK** (`starfederation/datastar`) for SSE responses
- SSE connection via `data-init="@get('/updates')"`
- Actions: `data-on:click="@post('/cmd/chat/{id}/send')"` with form data
- Datastar wraps signals in `{'datastar': {...}}` - handle in `getRequestData()`

## Development Commands

```bash
composer serve      # Start Swoole server at :8080
composer test       # Run Pest tests (uses in-memory SQLite)
composer stan       # PHPStan analysis
composer cs:fix     # PHP-CS-Fixer
composer db:seed    # Seed sample data

npm run build       # Build TypeScript with esbuild
npm run watch       # Watch mode
```

## Domain Models

Models use **readonly constructor properties** and factory methods:
- `Chat::fromArray()` / `Message::fromArray()` / `Document::fromArray()`
- Maps DB columns (snake_case) to properties (camelCase)
- `toArray()` for persistence (converts back to snake_case)
- UUIDs for IDs, timestamps as `DateTimeImmutable`

## Testing Patterns

Tests use **Pest PHP** with in-memory SQLite:
```php
beforeEach(function (): void {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));
    $this->repository = new SqliteChatRepository($this->pdo);
    $this->eventBus = new SwooleEventBus();
});
```
Test events by subscribing before action: `$this->eventBus->subscribe(fn($e) => ...)`

## File Conventions

- **ConfigProvider.php** - All DI container factories (no separate factory classes)
- **routes.php** - Route-to-handler mapping with `:method` suffixes for multi-action handlers
- **templates/** - Plain PHP templates with `<?php echo $var; ?>` escaping
- **swoole-server.php** - Custom SSE handling bypassing Mezzio for `/updates`

## Adding New Features

**New Command** (e.g., UpdateChat):
1. Create handler in `src/App/Infrastructure/Http/Handler/Command/`
2. Register factory in `ConfigProvider::getDependencies()`
3. Add route in `config/routes.php`
4. Emit events for SSE updates via `EventBusInterface`

**New Query**: Add to existing query handlers or create new ones in `Http/Handler/Query/`

## Important Notes

- Swoole runs persistently - static singletons for EventBus are intentional
- SSE uses Datastar SDK's `PatchElements` for HTML fragment updates
- Repository interface in Domain, implementation in Infrastructure
- All handlers return `EmptyResponse(204)` on success - UI updates via SSE
- AI streaming happens in Swoole coroutines to avoid blocking
