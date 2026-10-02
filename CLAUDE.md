# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

A PHP port of the Vercel AI Chatbot, built to compare a lean hypermedia stack against Next.js: PHP 8.5, Swoole 6, Mezzio, Datastar, SQLite. The `ai-chatbot/` directory is a git submodule of the Vercel original, for reference only.

## Runtime

The app needs the **Swoole** extension (`ext-swoole ^6`), not OpenSwoole: mezzio-swoole dropped OpenSwoole in 4.12. Swoole and OpenSwoole cannot both load in one PHP process. On a machine where the system PHP loads OpenSwoole, point `PHP_INI_SCAN_DIR` at an ini directory that loads `swoole.so` instead, for every PHP command, Composer included.

## Commands

```bash
composer serve          # Start the Swoole server (port in config/autoload/app.global.php)
composer stop           # Stop it; restart after any PHP change, workers keep code in memory
composer test           # Pest, in-memory SQLite built from data/schema.sql
vendor/bin/pest tests/Unit/Infrastructure/AIToolsTest.php --filter 'refuses to update'
composer stan           # PHPStan level 6 + type-coverage 100% (bleedingEdge)
composer cs             # php-cs-fixer dry run; composer cs:fix to apply
composer db:init        # Create data/db.sqlite from data/schema.sql
composer db:migrate     # Upgrade an existing DB in place (idempotent)

pnpm build              # esbuild src/ts/main.ts -> public/js/app.js (committed)
pnpm typecheck
PLAYWRIGHT_CHROMIUM_PATH=/usr/bin/chromium pnpm test:e2e   # Playwright; starts its own server on E2E_PORT (8094) with a temp DB
```

The e2e suite (`tests/e2e/`, `playwright.config.ts`) only sends the free test commands. It starts the server with `E2E_DATA_DIR` set, which makes `config/e2e.php` point the database, port and pid file at a temp directory, so `data/db.sqlite` is never touched. Stop any server on that port first. `PHP_INI_SCAN_DIR` defaults to `~/.local/php-swoole/conf.d`.

`pnpm install` runs a postinstall that copies the Datastar bundle and the on-keys plugin into `public/js/`. Those files are committed, so a Datastar upgrade shows up as a diff there. Datastar is pinned to a GitHub tag in `package.json`.

On localhost, chat messages such as `{help}`, `{longStream}`, `{error}`, `{artifact:code}` trigger canned responses without calling an AI provider (`MessageCommandHandler::TEST_COMMANDS`).

## Architecture

**CQRS over SSE.** Command handlers (`Http/Handler/Command/`) mutate state, emit domain events on the `EventBus` and return `204`. They never render the result. Every open page holds one SSE connection to `/updates`. `SseRequestListener` subscribes it to the user's events and pushes HTML fragments with Datastar `PatchElements` (plus a few `PatchSignals`). To change what the user sees after an action, change the event handling in `SseRequestListener`, not the command's response.

**Swoole request path.** `/updates` never reaches the Mezzio pipeline: `SseRequestListener` is registered before `RequestHandlerRequestListener` in the `mezzio-swoole` listener list in `config/autoload/app.global.php` (`MergeReplaceKey` keeps the order). `CleanupTimerListener` starts timers on worker start.

**AI streaming.** `MessageCommandHandler` starts a coroutine (`Swoole\Coroutine::create`) that iterates `AIServiceInterface::streamChat()` and emits a `MessageStreamingEvent` per chunk. `AIService` is the implementation. It streams, and generates chat titles, through `AnthropicStreamingClient` / `OpenAIStreamingClient`, which talk HTTP/1.1 over raw `Swoole\Coroutine\Socket` with TLS verification. The shared `SseHttpTransport` de-chunks the response with `ChunkedDecoder` before `SseParser` splits SSE lines (skipping that silently drops tokens), and `RetryPolicy` retries 429/5xx/overloaded only before anything was yielded. Both clients run the tool loop themselves, capped at 5 model requests (`StopReason::ToolLimit`), and yield typed events from `Domain/Service/Stream` (text, thinking, tool call, tool result, and one final `StreamEnd` with stop reason and summed usage). Tools implement `Tools/ToolInterface`; registering one in `AIService::streamChat()` is all the wiring both providers need.

- Anthropic continuation requests must echo the assistant turn back unchanged, thinking blocks and signatures included (Opus 5.5 / Sonnet 5.5 always think).
- Per-model request quirks live next to the clients: `LOW_EFFORT_MODELS` (Anthropic) and `NO_REASONING_MODELS` (OpenAI reasoning models need `reasoning_effort: none` to accept function tools on Chat Completions).
- Model lists, prices and the production subset (`APP_ENV=production` offers only cheap models) are constants in `AIService`. `streamChat()` replaces any model that is not currently offered with the default, which also covers chats stored with retired models.

**Shared state across coroutines.** Container services are singletons shared by every coroutine in a worker. Never keep per-request state on them: `streamChat()` creates its clients and tools per call, and the handler finds created documents via `DocumentRepository::findByMessageId()`. Shared tool state once leaked one user's document into another user's session.

**Sessions and streaming state** live in `Swoole\Table` (`SwooleTableSessionPersistence`, `StreamingSessionManager`). The data is in memory and is lost on restart. Guests get a session-backed user. `RateLimitService` enforces an hourly request window, a daily message limit and an optional daily token limit per user, with separate guest and registered tiers. `MessageCommandHandler` stores tool calls in `messages.parts` (replayed as text notes by `ConversationHistoryBuilder`), usage in `message_usage`, and logs one `ai_response {json}` line per response.

**Schema changes** go into `data/schema.sql` (fresh installs, tests) and as a new step in `SchemaMigrator` (existing DBs, keyed on `PRAGMA user_version`); deploys run `composer db:migrate`.

**Domain models** (`Domain/Model`) are immutable: readonly properties, `fromArray()` from snake_case DB rows, `toArray()` back, and `update*`/`append*` methods return new instances. Repository interfaces live in `Domain/Repository`, SQLite implementations in `Infrastructure/Persistence`. All DI factories are closures in `src/App/ConfigProvider.php`. Routes in `config/routes.php` use a `.action` route-name suffix that the multi-action handlers dispatch on.

**Datastar conventions.** Signals hold client-only state (underscore-prefixed, e.g. `$_message`, `$_artifactOpen`). Server data arrives as HTML fragments, not signals. Use the `starfederation/datastar-php` SDK for SSE output. Templates are plain PHP in `templates/`, rendered by `TemplateRenderer`, with Parsedown for markdown.

## Configuration

`.env` is loaded in `config/config.php`. AI settings (keys, `AI_DEFAULT_MODEL`, `AI_MAX_TOKENS`, context compression) are documented in `.env.example` and read in `config/autoload/app.global.php`. Local overrides belong in `config/autoload/*.local.php` (gitignored).
