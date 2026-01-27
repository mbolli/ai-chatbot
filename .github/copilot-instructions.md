# Copilot Instructions - Life Timeline

## Architecture Overview

This is a **PHP/Swoole** timeline application using **CQRS** pattern with real-time **SSE multiplayer** via Datastar frontend framework.

```
┌─────────────────────────────────────────────────────────────────┐
│  Frontend (Datastar + TypeScript)                                │
│  └── SSE connection to /updates for real-time DOM patching      │
├─────────────────────────────────────────────────────────────────┤
│  Infrastructure Layer                                            │
│  ├── Http/Handler/Command/  → POST/PUT/DELETE requests          │
│  ├── Http/Handler/Query/    → GET requests                       │
│  └── UpdatesHandler         → SSE streaming via SwooleEventBus  │
├─────────────────────────────────────────────────────────────────┤
│  Application Layer (CQRS)                                        │
│  ├── Command/{Action}/      → {Action}Command + {Action}Handler │
│  └── Query/GetTimeline/     → Read-only handler                  │
├─────────────────────────────────────────────────────────────────┤
│  Domain Layer                                                    │
│  ├── Model/                 → TimelineGroup, TimelineItem        │
│  ├── Event/                 → TimelineChangedEvent               │
│  └── Repository/            → Interface definitions              │
├─────────────────────────────────────────────────────────────────┤
│  SQLite (data/timeline.db)                                       │
└─────────────────────────────────────────────────────────────────┘
```

## Key Patterns

### CQRS Commands
Each write operation follows this structure in `src/App/Application/Command/{Action}/`:
```php
// CreateItemCommand.php - Immutable value object with fromArray()
// CreateItemHandler.php - Executes command, emits TimelineChangedEvent
```
Handlers receive repository + EventBusInterface via constructor injection.

### Real-time Updates Flow
1. HTTP handler invokes command handler
2. Handler calls `$this->eventBus->emit(new TimelineChangedEvent(...))`
3. `SwooleEventBus` broadcasts to all SSE subscribers
4. `UpdatesHandler` re-renders timeline partial and sends Datastar `PatchElements`

### Datastar Frontend Conventions
- Signals are **only for client state** (e.g., form inputs, modal visibility, zoom level)
- Server responses should return **HTML via PatchElements**, not signals
- Always use the **Datastar SDK** (`starfederation/datastar`) for SSE responses
- SSE connection via `data-init="@get('/updates')"`
- Actions: `data-on:click="@post('/cmd/items')"` with form data
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
- `TimelineItem::fromArray()` maps DB columns (snake_case) to properties (camelCase)
- `TimelineItem::toArray()` for persistence (converts back to snake_case)
- Dates stored as `YYYY-MM` strings, `null` end_date = ongoing

## Testing Patterns

Tests use **Pest PHP** with in-memory SQLite:
```php
beforeEach(function (): void {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));
    $this->repository = new SqliteTimelineRepository($this->pdo);
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

**New Command** (e.g., UpdateItem):
1. Create `src/App/Application/Command/UpdateItem/UpdateItemCommand.php`
2. Create `src/App/Application/Command/UpdateItem/UpdateItemHandler.php`
3. Register factory in `ConfigProvider::getDependencies()`
4. Add route in `config/routes.php`
5. Add method to HTTP handler (or create new handler)

**New Query**: Same pattern in `src/App/Application/Query/`

## Important Notes

- Swoole runs persistently - static singletons for EventBus are intentional
- SSE uses Datastar SDK's `PatchElements` for HTML fragment updates
- Repository interface in Domain, implementation in Infrastructure
- All handlers return `EmptyResponse(204)` on success - UI updates via SSE
