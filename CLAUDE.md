# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

A PHP port of the Vercel AI Chatbot, built to compare a lean hypermedia stack against Next.js: PHP 8.5, OpenSwoole, php-via, Datastar, SQLite. The `ai-chatbot/` directory is a git submodule of the Vercel original, for reference only.

## Runtime

The app runs on **OpenSwoole** (`ext-openswoole ^26`) under PHP 8.5, started with the system `php`. php-via needs OpenSwoole; the Swoole extension must not be loaded in the same PHP process, so do not point `PHP_INI_SCAN_DIR` at a Swoole ini directory.

php-via comes from a Composer path repository, `../php-via-fix` (symlinked into `vendor/`), and the lock pins its branch `dev-fix/component-dom-ids`. That branch makes component wrapper ids valid CSS selectors on routes with parameters, which `/chat/{id}` needs. Before a deploy, php-via has to be released with that fix: require the released version in `composer.json`, delete the `repositories` entry and run `composer update mbolli/php-via`. Until then Composer only resolves where `../php-via-fix` exists. Composer also needs `--ignore-platform-req=ext-inotify` where inotify is missing (it is a dev requirement).

## Commands

```bash
composer serve          # php bin/server.php; 0.0.0.0:8080 unless config/autoload/app.local.php sets server.host/port
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

There is no stop or reload command. The server runs in the foreground: stop it with Ctrl+C or SIGTERM, and restart it after any PHP change, because the worker keeps the code in memory.

The e2e suite (`tests/e2e/`, `playwright.config.ts`) only sends the free test commands. Its web server runs `php bin/server.php` with `E2E_DATA_DIR` set, and `bin/server.php` then merges `config/e2e.php`: a temp database, `127.0.0.1:E2E_PORT`, `APP_ENV=testing` and placeholder AI keys, so `data/db.sqlite` is never touched and a stray AI call fails. Stop any server on that port first.

`pnpm install` runs a postinstall that copies the Datastar bundle and the on-keys plugin into `public/js/`. Those files are committed, so a Datastar upgrade shows up as a diff there. Datastar is pinned to a GitHub tag in `package.json`.

Outside production (`APP_ENV` other than `production`), chat messages such as `{help}`, `{longStream}`, `{slow}`, `{error}`, `{artifact:code}` trigger canned responses without calling an AI provider (`MessageCommands::TEST_COMMANDS`).

## Architecture

**Server start.** `bin/server.php` merges `config/autoload/*.global.php`, then `*.local.php`, then `config/e2e.php` when `E2E_DATA_DIR` is set. It builds `App\Container`, attaches the `ViaEventBus` to the `Via` app, registers `App\Web\ChatApp` and starts two timers: stale streaming sessions every 30 s, orphaned guest users hourly. php-via owns `/_sse` (one SSE stream per tab), `POST /_action/{id}`, the tab-close beacon `/_session/close`, and serves `public/` as static files. A port configured under the old `mezzio-swoole.swoole-http-server` key still works; the `server` key wins.

**Pages, signals, actions, components.** `ChatApp` registers two pages, `/` and `/chat/{id}`. A page handler creates:
- signals `message` and `model` (`$c->signal()`); templates bind them by id through `$signals['message']`, never by a hard-coded name,
- actions `send`, plus `stop` and `model` on a chat page; templates post to the URL in `$actions[...]`,
- live components `sidebar` and `toasts` (scope: user), `messages` (scope: chat) and `stream` (scope: chat stream, rendered inside `messages`).

Two feature classes add their own actions and slots the same way: `AccountFeature` (sign-in, register, guest upgrade, sign-out, votes, visibility, chat deletion, the `header` component) and `DocumentFeature` (the `artifact` panel component and its open/version/save/delete/suggestion actions, rendered by `DocumentPanel`). Their actions call `AccountFeature::guard()` first, which reloads a tab whose session switched user. Per-item ids travel in the action URL's query string (`$ctx->input('id')`); a custom `@post` payload would drop `via_ctx`.

The page itself joins no scope, so a broadcast re-renders only the components in that scope, never the whole page. `App\Web\Scopes` builds the scope names.

**Live updates.** The services in `App\Application` (`ChatCommands`, `MessageCommands`, `DocumentCommands`, `SuggestionCommands`, `VoteCommands`) change state and emit domain events on `EventBusInterface`; they return an HTTP-like status code and never render. `ViaEventBus` turns events into `LiveState` changes and `Via::broadcast()` calls: a chat update broadcasts the chat and user scopes, a streaming chunk updates the reply in `LiveState` and broadcasts the chat stream scope, at most once per 50 ms per chat with a trailing broadcast for the latest text. Components render from the database plus `LiveState`. To change what the user sees after an action, change the component's view or the event handling in `ViaEventBus`, not the action.

`LiveState` (reply being streamed, toasts), the php-via session data and `StreamingSessionManager`'s OpenSwoole table live in worker memory. The server therefore runs one worker, and a restart logs everybody out and drops running streams.

**Component wrapper.** php-via wraps every component in `<div id="c-…">`. `public/css/app.css` sets `display: contents` on `[id^="c-"]`, so the wrapper does not take part in the grid and flex layouts. Never give another element an id that starts with `c-`.

**Generating flag.** `messages.php` renders a hidden span whose `data-init` sets `$_generatingMessage` and whose id changes with every state change, so each re-render re-syncs the send/stop buttons.

**AI streaming.** `MessageCommands` starts a coroutine (`OpenSwoole\Coroutine::create`) that iterates `AIServiceInterface::streamChat()` and emits a `MessageStreamingEvent` with the full text so far per chunk. `AIService` is the implementation. It streams, and generates chat titles, through `AnthropicStreamingClient` / `OpenAIStreamingClient`, which talk HTTP/1.1 over raw `OpenSwoole\Coroutine\Socket` with TLS verification. The shared `SseHttpTransport` de-chunks the response with `ChunkedDecoder` before `SseParser` splits SSE lines (skipping that silently drops tokens), and `RetryPolicy` retries 429/5xx/overloaded only before anything was yielded. Both clients run the tool loop themselves, capped at 5 model requests (`StopReason::ToolLimit`), and yield typed events from `Domain/Service/Stream` (text, thinking, tool call, tool result, and one final `StreamEnd` with stop reason and summed usage). Tools implement `Tools/ToolInterface`; registering one in `AIService::streamChat()` is all the wiring both providers need.

- Anthropic continuation requests must echo the assistant turn back unchanged, thinking blocks and signatures included (Opus 5.5 / Sonnet 5.5 always think).
- Per-model request quirks live next to the clients: `LOW_EFFORT_MODELS` (Anthropic) and `NO_REASONING_MODELS` (OpenAI reasoning models need `reasoning_effort: none` to accept function tools on Chat Completions).
- Model lists, prices and the production subset (`APP_ENV=production` offers only cheap models) are constants in `AIService`. `streamChat()` replaces any model that is not currently offered with the default, which also covers chats stored with retired models.

**Shared state across coroutines.** `Container` builds each service once per worker and every coroutine shares it. Never keep per-request state on a service: `streamChat()` creates its clients and tools per call, and the command finds created documents via `DocumentRepository::findByMessageId()`. Shared tool state once leaked one user's document into another user's session.

**Users and limits.** `AuthService` keeps the user in the php-via session (`ViaSession`); guests get a session-backed user. `RateLimitService` enforces an hourly request window, a daily message limit and an optional daily token limit per user, with separate guest and registered tiers. `MessageCommands` stores tool calls in `messages.parts` (replayed as text notes by `ConversationHistoryBuilder`), usage in `message_usage`, and logs one `ai_response {json}` line per response.

**Schema changes** go into `data/schema.sql` (fresh installs, tests) and as a new step in `SchemaMigrator` (existing DBs, keyed on `PRAGMA user_version`); deploys run `composer db:migrate`.

**Domain models** (`Domain/Model`) are immutable: readonly properties, `fromArray()` from snake_case DB rows, `toArray()` back, and `update*`/`append*` methods return new instances. Repository interfaces live in `Domain/Repository`, SQLite implementations in `Infrastructure/Persistence`. `App\Container` wires everything by hand, one method per service.

**Datastar conventions.** Client-only signals are underscore-prefixed (`$_sidebarOpen`, `$_artifactOpen`, `$_aboutOpen`) and declared on `#app` in `templates/layout/default.php`. Server data arrives as HTML, not signals. Templates are plain PHP in `templates/`, rendered by `TemplateRenderer`, with Parsedown for markdown.

## Configuration

`bin/server.php` loads `.env` with phpdotenv. AI settings (keys, `AI_DEFAULT_MODEL`, `AI_MAX_TOKENS`, context compression) are documented in `.env.example` and read in `config/autoload/app.global.php`. Local overrides, including `server.host` and `server.port`, belong in `config/autoload/*.local.php` (gitignored). `APP_DEBUG=true` turns on php-via's dev mode; `VIA_DEVBAR=1` adds its Dev Bar. In production (`APP_ENV=production`) the session cookie is `Secure` with the `__Host-` prefix, so it needs HTTPS.
