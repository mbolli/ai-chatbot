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

pnpm build              # esbuild src/ts/main.ts -> public/js/app.js (committed)
pnpm typecheck
```

`pnpm install` runs a postinstall that copies the Datastar bundle and the on-keys plugin into `public/js/`. Those files are committed, so a Datastar upgrade shows up as a diff there. Datastar is pinned to a GitHub tag in `package.json`.

On localhost, chat messages such as `{help}`, `{longStream}`, `{error}`, `{artifact:code}` trigger canned responses without calling an AI provider (`MessageCommandHandler::TEST_COMMANDS`).

## Architecture

**CQRS over SSE.** Command handlers (`Http/Handler/Command/`) mutate state, emit domain events on the `EventBus` and return `204`. They never render the result. Every open page holds one SSE connection to `/updates`. `SseRequestListener` subscribes it to the user's events and pushes HTML fragments with Datastar `PatchElements` (plus a few `PatchSignals`). To change what the user sees after an action, change the event handling in `SseRequestListener`, not the command's response.

**Swoole request path.** `/updates` never reaches the Mezzio pipeline: `SseRequestListener` is registered before `RequestHandlerRequestListener` in the `mezzio-swoole` listener list in `config/autoload/app.global.php` (`MergeReplaceKey` keeps the order). `CleanupTimerListener` starts timers on worker start.

**AI streaming.** `MessageCommandHandler` starts a coroutine (`Swoole\Coroutine::create`) that iterates `AIServiceInterface::streamChat()` and emits a `MessageStreamingEvent` per chunk. `LLPhantAIService` is the implementation, despite the name. LLPhant only generates chat titles. Streaming goes through `AnthropicStreamingClient` / `OpenAIStreamingClient`, which talk HTTP/1.1 over raw `Swoole\Coroutine\Socket` with TLS verification. They de-chunk the response with `ChunkedDecoder` before splitting SSE lines; skipping that silently drops tokens. Both clients run the tool loop (`createDocument`, `updateDocument`) themselves by recursing into a continuation request.

- Anthropic continuation requests must echo the assistant turn back unchanged, thinking blocks and signatures included (Opus 5.5 / Sonnet 5.5 always think).
- Per-model request quirks live next to the clients: `LOW_EFFORT_MODELS` (Anthropic) and `NO_REASONING_MODELS` (OpenAI reasoning models need `reasoning_effort: none` to accept function tools on Chat Completions).
- Model lists, prices and the production subset (`APP_ENV=production` offers only cheap models) are constants in `LLPhantAIService`. `streamChat()` replaces any model that is not currently offered with the default, which also covers chats stored with retired models.

**Shared state across coroutines.** Container services are singletons shared by every coroutine in a worker. Never keep per-request state on them: `streamChat()` creates its clients and tools per call, and the handler finds created documents via `DocumentRepository::findByMessageId()`. Shared tool state once leaked one user's document into another user's session.

**Sessions and streaming state** live in `Swoole\Table` (`SwooleTableSessionPersistence`, `StreamingSessionManager`). The data is in memory and is lost on restart. Guests get a session-backed user. `RateLimitService` enforces a daily message limit per user, with separate guest and registered tiers. The `requests_per_hour` settings in config are not enforced anywhere.

**Domain models** (`Domain/Model`) are immutable: readonly properties, `fromArray()` from snake_case DB rows, `toArray()` back, and `update*`/`append*` methods return new instances. Repository interfaces live in `Domain/Repository`, SQLite implementations in `Infrastructure/Persistence`. All DI factories are closures in `src/App/ConfigProvider.php`. Routes in `config/routes.php` use a `.action` route-name suffix that the multi-action handlers dispatch on.

**Datastar conventions.** Signals hold client-only state (underscore-prefixed, e.g. `$_message`, `$_artifactOpen`). Server data arrives as HTML fragments, not signals. Use the `starfederation/datastar-php` SDK for SSE output. Templates are plain PHP in `templates/`, rendered by `TemplateRenderer`, with Parsedown for markdown.

## Configuration

`.env` is loaded in `config/config.php`. AI settings (keys, `AI_DEFAULT_MODEL`, `AI_MAX_TOKENS`, context compression) are documented in `.env.example` and read in `config/autoload/app.global.php`. Local overrides belong in `config/autoload/*.local.php` (gitignored).
