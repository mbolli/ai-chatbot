# Copilot Instructions - AI Chatbot

`CLAUDE.md` is the maintained guide to this codebase; this file is the short version.

## Architecture

PHP 8.5 on OpenSwoole, served by [php-via](https://via.zweiundeins.gmbh): pages, server-side actions, signals and live components, with one Server-Sent Events stream per tab. Datastar runs in the browser and morphs the HTML the server sends. SQLite for storage.

- `bin/server.php` loads the config, builds `App\Container` (shared services), registers `App\Web\ChatApp` and starts the server.
- `ChatApp` registers the pages `/` and `/chat/{id}`. Each page creates its signals (`message`, `model`), actions (`send`, `stop`, `model`) and live components (`sidebar`, `toasts`, `messages`, `stream`).
- Components join broadcast scopes (`App\Web\Scopes`: user, chat, chat stream). The page itself joins none, so a broadcast re-renders only the affected component.
- `App\Application\ChatCommands` and `MessageCommands` change state and emit domain events on `EventBusInterface`. They never render.
- `App\Web\ViaEventBus` turns those events into `LiveState` changes (the reply being streamed, toasts) and `Via::broadcast()` calls. Components re-render from the database and `LiveState`.

## AI streaming flow

1. The `send` action calls `MessageCommands::send()`, which saves the user message and an empty assistant message and emits `ChatUpdatedEvent`.
2. A coroutine iterates `AIService::streamChat()` and emits a `MessageStreamingEvent` with the full text so far per chunk.
3. `ViaEventBus` stores the text in `LiveState` and broadcasts the chat stream scope, at most once per 50 ms.
4. The `stream` component renders the reply as Markdown on the server; Datastar morphs it into the page.
5. When the reply is complete, the stream entry is dropped and the message list renders the stored message.

## Conventions

- Underscore signals (`$_sidebarOpen`, `$_generatingMessage`) are client-only. Server signals come from `$c->signal()`; templates reference them by id (`$signals['message']`).
- Server data arrives as HTML, not signals.
- Container services are shared by every coroutine: never keep per-request state on them.
- `LiveState`, php-via sessions and the streaming session table live in worker memory, so the server runs one worker and loses them on restart.
- php-via wraps every component in `<div id="c-…">`; `public/css/app.css` sets `display: contents` on `[id^="c-"]` so the wrapper does not break grid and flex layouts. Do not give other elements ids starting with `c-`.
- Domain models are immutable (`fromArray()` / `toArray()`, `update*` return new instances). Repository interfaces live in `Domain/Repository`, SQLite implementations in `Infrastructure/Persistence`.

## Commands

```bash
composer serve      # php bin/server.php, port 8080 unless config/autoload/app.local.php sets server.port
composer test       # Pest, in-memory SQLite (createTestPdo() in tests/Pest.php)
composer stan       # PHPStan
composer cs:fix     # PHP-CS-Fixer
pnpm build          # esbuild src/ts/main.ts -> public/js/app.js (committed)
PLAYWRIGHT_CHROMIUM_PATH=/usr/bin/chromium pnpm test:e2e
```
