# AI Chatbot: PHP/OpenSwoole/Datastar Stack Showcase

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![OpenSwoole](https://img.shields.io/badge/OpenSwoole-26-007EC6)](https://openswoole.com/)
[![php-via](https://img.shields.io/badge/php--via-0.14-6C3BAF)](https://via.zweiundeins.gmbh)
[![Datastar](https://img.shields.io/badge/Datastar-1.0-FF6B35?logo=rocket&logoColor=white)](https://data-star.dev/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![Made by zweiundeins.gmbh](https://img.shields.io/badge/Made%20with%20%E2%98%95%20by-zweiundeins.gmbh-blue)](https://zweiundeins.gmbh)

**[🚀 Live Demo](https://chat.zweiundeins.gmbh)** | **[📊 Benchmark Results](benchmarks/RESULTS.md)** | **[📝 Blog Post](https://zweiundeins.gmbh/en/methodology/spa-vs-hypermedia-real-world-performance-under-load)**

A real-time AI chatbot built with **PHP 8.5**, **OpenSwoole**, **[php-via](https://via.zweiundeins.gmbh)** and **Datastar**: streaming responses, documents the AI can create and edit, and a reactive UI without a JavaScript framework.

> **🎯 Project Goal:** This is a side-by-side comparison with the [Vercel AI Chatbot (Next.js)](https://github.com/vercel/ai-chatbot), demonstrating that a lean PHP stack can deliver the same features with **dramatically less complexity** and **better performance**.

## 🆚 The Comparison: Next.js vs PHP

This project exists to challenge the assumption that modern AI chat apps require heavy JavaScript stacks. We rebuilt the Vercel AI Chatbot in PHP and measured both.

> **Context:** The [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) has **86 contributors** and **600+ commits** of optimization. This PHP port is a straightforward implementation with minimal optimization, and it still leads on most metrics.

### Measured Performance (February 2026)

Measured on the Swoole/Mezzio version, before the port to php-via.

**Desktop (Chrome 144):**

| Metric | Next.js (Vercel) | PHP/Swoole | Difference |
|--------|------------------|------------|------------|
| **Lighthouse Score** | 93 | **100** | 🏆 PHP |
| **Time to Interactive** | 1.6s | **0.3s** | **5.3x faster** |
| **Total Blocking Time** | 110ms | **0ms** | ∞ better |
| **JavaScript Sent** | ~1,080 KB | **13.5 KB** | **80x less** |
| **HTTP Requests** | 36+ | **8** | **4.5x fewer** |
| **Page Weight** | 1,107 KB | **42 KB** | **26x smaller** |

**Mobile (Slow 4G + 4x CPU throttling):**

| Metric | Next.js (Vercel) | PHP/Swoole | Difference |
|--------|------------------|------------|------------|
| **Lighthouse Score** | 54 | **100** | 🏆 PHP |
| **Time to Interactive** | 8.2s | **1.1s** | **7.5x faster** |
| **Total Blocking Time** | 780ms | **0ms** | ∞ better |
| **Largest Contentful Paint** | 8.1s | **1.1s** | **7.4x faster** |

### Codebase Comparison (Production)

| Aspect | Next.js | PHP/OpenSwoole | Ratio |
|--------|---------|----------------|-------|
| **Dependencies (prod)** | 592 packages | **18 packages** | **33x fewer** |
| **node_modules / vendor** | 714 MB | **2.4 MB** | **298x smaller** |
| **Build Step** | Required | **None on deploy** (`public/js/app.js` is committed) | |
| **Hosting Cost** | Usage-based | **$20/year VPS** | |

PHP numbers: `composer install --no-dev --optimize-autoloader` into a clean copy, then `du -sh vendor` (October 2026, php-via 0.14 installed as a copy of its dist files). Nyholm PSR-7 and `openswoole/core` arrive as php-via dependencies; Twig is optional since php-via 0.14 and not installed. Next.js numbers: `pnpm install --prod` of vercel/ai-chatbot at c2f8235 (July 2026), counting the unique packages in `node_modules/.pnpm`.

**The takeaway:** Modern PHP on OpenSwoole is a serious contender for real-time applications: no transpilation, no hydration, no serverless cold starts.

> ⚠️ **Feature Completeness:** This is a **working proof-of-concept**, not a production-ready clone. Core features (chat, streaming, artifacts, auth, voting) work. Missing: file attachments, edit/regenerate messages. See the [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) for the full-featured original.

## ✨ Features

- **Real-time AI Streaming** - Token-by-token streaming via Server-Sent Events (SSE)
- **Multiple AI Providers** - Support for Anthropic (Claude) and OpenAI (GPT) models
- **Document Artifacts** - AI can create and edit code, text, spreadsheets, and images, and suggest edits you can accept or dismiss
- **Reasoning Display** - Collapsible reasoning summary for models that think (Claude Opus/Sonnet 5.5)
- **Usage Accounting** - Token usage per response, prompt caching, and one structured log line per AI response
- **Commands and events** - Actions call application commands; the commands emit domain events, and live components re-render from them
- **Session-based Auth** - Simple authentication with guest and registered user support
- **Rate Limiting** - Hourly request, daily message and optional daily token limits for guests and registered users
- **Responsive UI** - Mobile-friendly design with sidebar navigation
- **No build step on deploy** - Datastar provides the reactivity; the small TypeScript bundle is committed
- **Zero CDN Dependencies** - All assets served locally (Open Props, Datastar, SVG icons), markdown is rendered server-side. Exception: running Python artifacts loads Pyodide from jsDelivr on demand

## 📋 Requirements

- PHP 8.5 with `pdo_sqlite`
- OpenSwoole 26 (`ext-openswoole`), built with OpenSSL: the AI clients connect over TLS from OpenSwoole coroutine sockets. The Swoole extension must not be loaded in the same PHP binary
- Composer
- Node.js and pnpm, only for TypeScript changes and the Playwright suite

This app targets php-via 0.14, which is not released yet and still lacks a fix it needs (component ids on routes with parameters). Until both are released, `composer.json` installs php-via from a path repository at `../php-via-014`: a checkout of php-via's 0.14 line with commit `fix: give components on routes with parameters a wrapper id that works as a CSS selector` applied.

## 🚀 Quick Start

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd ai-chatbot

# Install PHP dependencies (add --ignore-platform-req=ext-inotify if inotify is missing; only the dev tools need it)
composer install

# Install frontend dependencies (optional)
pnpm install
```

### 2. Configure Environment

```bash
# Copy the environment template and add your API keys
cp .env.example .env
```

Edit `.env` with your API keys:

```bash
# Required: At least one AI provider API key
ANTHROPIC_API_KEY=sk-ant-api03-your-key-here
# OPENAI_API_KEY=sk-your-key-here

# Optional: Model and token configuration
AI_DEFAULT_MODEL=claude-haiku-4-5
AI_MAX_TOKENS=4096
```

Optionally copy the local PHP config, for example to change the host and port:

```bash
cp config/autoload/app.local.php.dist config/autoload/app.local.php
```

### 3. Initialize Database

```bash
# Initialize the SQLite database
composer db:init

# Upgrade an existing database to the current schema (safe to repeat)
composer db:migrate
```

### 4. Start the Server

```bash
# php bin/server.php, listens on 0.0.0.0:8080 unless app.local.php sets server.host/server.port
composer serve
```

Visit **http://localhost:8080** in your browser. The server runs in the foreground; stop it with Ctrl+C and restart it after PHP changes.

## 🏗️ Architecture

```
Browser (Datastar)
 ├── GET  /  and  /chat/{id}   ChatApp page: signals, actions, live components
 ├── POST /_action/{id}        send, stop, model  ──>  ChatCommands, MessageCommands
 └── GET  /_sse  (one per tab) <── re-rendered components
                                        ^
 MessageCommands ── domain events ──> ViaEventBus ──> LiveState + Via::broadcast(scope)
```

[php-via](https://via.zweiundeins.gmbh) runs the HTTP server on OpenSwoole. Each tab holds one Server-Sent Events stream, and Datastar morphs the HTML that arrives on it.

- **Pages.** `App\Web\ChatApp` registers `/` and `/chat/{id}`. A page handler creates its signals (`message`, `model`), its actions (`send`, `stop`, `model`) and its live components: `sidebar` and `toasts` join the user's scope, `messages` the chat's scope, and `stream` (the reply being written) the chat stream scope.
- **Commands.** Actions call `App\Application\ChatCommands` and `MessageCommands`. They change state and emit domain events; they never render.
- **Broadcasts.** `App\Web\ViaEventBus` turns each event into a `LiveState` change (the reply being streamed, toasts) and a broadcast to the affected scope. Only the components in that scope re-render, from the database and `LiveState`. The page itself joins no scope.
- **Single worker.** `LiveState`, the php-via sessions and the table of running streams live in worker memory, so the server runs one worker, and a restart logs everybody out.

### Real-time Streaming Flow

1. The `send` action calls `MessageCommands::send()`, which saves the user message and an empty assistant message
2. An OpenSwoole coroutine iterates `AIService::streamChat()`
3. Each chunk emits a `MessageStreamingEvent` with the full text so far
4. `ViaEventBus` stores the text in `LiveState` and broadcasts the chat stream scope, which php-via renders at most once per 50 ms per chat (`withBroadcastThrottle()`)
5. The `stream` component renders the reply as Markdown on the server; Datastar morphs only the changed nodes
6. When the reply is complete, the message list renders the stored message
7. The message container keeps the newest text in view unless the user scrolled up

Each update carries the whole reply as rendered HTML, so the browser runs no Markdown code and a missed update is repaired by the next one.

## 📁 Project Structure

```
├── bin/
│   ├── server.php            # Loads config, builds the container, starts php-via
│   ├── init-db.php           # Creates data/db.sqlite from data/schema.sql
│   └── migrate.php           # Upgrades an existing database
├── config/
│   ├── autoload/             # app.global.php, your *.local.php overrides
│   └── e2e.php               # Overrides for the Playwright suite
├── data/
│   ├── schema.sql            # Database schema
│   └── db.sqlite             # SQLite database (created on init)
├── public/
│   ├── css/                  # app.css, open-props-bundle.css
│   ├── icons.svg             # SVG icon sprite
│   └── js/                   # app.js (built from src/ts), datastar.js, datastar-on-keys.js
├── src/App/
│   ├── Application/          # ChatCommands, MessageCommands
│   ├── Container.php         # Builds the shared services
│   ├── Domain/               # Events, models, repository and service interfaces
│   ├── Infrastructure/       # AI clients and tools, auth, SQLite repositories, templates
│   └── Web/                  # ChatApp, ViaEventBus, LiveState, Scopes, ViaSession
├── src/ts/main.ts            # Textarea auto-resize, Python artifacts (Pyodide)
├── templates/
│   ├── app/                  # Page templates
│   ├── layout/               # Layout templates
│   └── partials/             # Components and dialogs
└── tests/
    ├── Feature/              # Integration tests
    ├── Unit/                 # Unit tests
    └── e2e/                  # Playwright specs
```

## 🔌 Routes

| Method | Path | Description |
|--------|------|-------------|
| GET | `/` | Home page, the first message creates a chat |
| GET | `/chat/{id}` | A chat the user owns, or a public chat |
| GET | `/_sse` | php-via: the tab's SSE stream |
| POST | `/_action/{id}` | php-via: runs a page action (`send`, `stop`, `model`) |
| POST | `/_session/close` | php-via: tab-close beacon |
| GET | `/css/*`, `/js/*` | Static files from `public/` |

Actions belong to the tab that rendered the page; the browser posts the tab's context id and signals with each action.

## 🤖 AI Models

### Supported Models

**Anthropic**
- `claude-opus-5-5` - Claude Opus 5.5 (Maximum intelligence)
- `claude-sonnet-5-5` - Claude Sonnet 5.5 (Best balance)
- `claude-haiku-4-5` - Claude Haiku 4.5 (Fast/Cheap, the only Anthropic model in production mode)

**OpenAI**
- `gpt-6-sol` / `gpt-5.6-terra` - Full capability
- `gpt-6-luna` - Cheapest
- `gpt-5.6-luna`
- `gpt-4.1-mini` / `gpt-4o-mini`

In production mode (`APP_ENV=production`) only the cheap models are offered: Haiku 4.5, GPT-6 Luna, GPT-4o Mini, GPT-5.6 Luna, GPT-4.1 Mini. Chats stored with a model that is no longer offered fall back to the default.

### AI Tools

The AI can use tools to create and update documents:

- **CreateDocument** - Create code, text, spreadsheet, or image artifacts
- **UpdateDocument** - Modify existing artifacts
- **RequestSuggestions** - Propose up to 5 edits for a text document, shown with Accept/Dismiss

Tool calls are stored with each message and replayed as short notes in later turns, so the model can refer to documents it created earlier. One response runs at most 5 model requests.

## 🗄️ Database Schema

```sql
-- Users (session-based auth)
users (id, email, password_hash, is_guest, created_at)

-- Chats (conversations)
chats (id, user_id, title, model, visibility, created_at, updated_at)

-- Messages
messages (id, chat_id, role, content, parts, created_at)

-- Documents/Artifacts
documents (id, chat_id, message_id, kind, title, language, created_at, updated_at)

-- Document versions (undo/redo)
document_versions (id, document_id, content, version, created_at)

-- Message votes
votes (id, chat_id, message_id, user_id, is_upvote, created_at)

-- AI suggestions
suggestions (id, document_id, content, status, created_at)
```

## 🛠️ Development

### Available Scripts

```bash
# Server
composer serve          # php bin/server.php, foreground; restart after PHP changes

# Database
composer db:init        # Create data/db.sqlite from data/schema.sql
composer db:migrate     # Upgrade an existing database (safe to repeat)

# Testing
composer test           # Run Pest tests
composer test:coverage  # Run tests with coverage

# Code Quality
composer cs             # Check code style (dry-run)
composer cs:fix         # Fix code style issues
composer stan           # Run PHPStan static analysis

# Frontend (optional)
pnpm build              # Build TypeScript with esbuild
pnpm watch              # Watch mode for development
pnpm typecheck          # TypeScript type checking
pnpm test:e2e           # Playwright suite
```

### Running Tests

Tests use Pest PHP with in-memory SQLite:

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/pest tests/Unit/Domain/ChatTest.php

# Run with coverage
composer test:coverage
```

End-to-end tests use Playwright and only the free test commands (`{help}`, `{slow}`, `{error}` and so on, available whenever `APP_ENV` is not `production`), so they never call an AI provider. The config starts `php bin/server.php` on a temporary database at `127.0.0.1:E2E_PORT` (default 8094) through `config/e2e.php`, and stops it with SIGTERM. Browsers are not downloaded; point `PLAYWRIGHT_CHROMIUM_PATH` at a local Chromium:

```bash
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 pnpm install
PLAYWRIGHT_CHROMIUM_PATH=/usr/bin/chromium pnpm test:e2e
```

### Code Style

This project uses PHP-CS-Fixer with PSR-12 style:

```bash
# Check for issues
composer cs

# Auto-fix issues
composer cs:fix
```

### Static Analysis

PHPStan is configured at level 6:

```bash
composer stan
```

## 🎨 Frontend (Datastar)

The frontend uses [Datastar](https://data-star.dev/) for reactive UI without JavaScript frameworks. php-via renders the HTML and sends updates over the tab's SSE stream.

### Key Concepts

- **Signals** - State shared with the server (`$c->signal()` in PHP), or client-only when the name starts with `_` (`$_sidebarOpen`)
- **Actions** - `@post()` to a php-via action URL; the action changes state, and the components re-render
- **Components** - Parts of a page that join a broadcast scope and re-render on their own
- **PatchElements** - The SSE events that carry the re-rendered HTML

### Example Usage

```php
// A page with a signal, an action and a live component (php-via)
$app->page('/chat/{id}', function (Context $c, string $id): void {
    $message = $c->signal('', 'message');
    $send = $c->action(function () use ($message): void { /* store $message->string() */ }, 'send');
    $messages = $c->component(function (Context $cc) use ($id): void {
        $cc->addScope(Scopes::chat($id));
        $cc->view(fn (): string => '...');
    }, 'messages');
    $c->view(fn (): string => $messages() . '<textarea data-bind="' . $message->id() . '"></textarea>'
        . '<button data-on:click="@post(\'' . $send->url() . '\')">Send</button>');
});

// Anywhere, after the data changed: re-render every "messages" component of that chat
$app->broadcast(Scopes::chat($id));
```

`App\Web\ChatApp` follows this shape with templates instead of inline HTML.

## 🔧 Configuration Reference

### Environment Configuration

Create a `.env` file from the example:

```bash
cp .env.example .env
```

Available environment variables:

```bash
# AI Provider API Keys (at least one required)
ANTHROPIC_API_KEY=sk-ant-api03-your-key-here
OPENAI_API_KEY=sk-your-key-here

# AI Model Configuration
AI_DEFAULT_MODEL=claude-haiku-4-5
AI_MAX_TOKENS=2048

# Context budget: older messages are dropped once the history exceeds this (estimated tokens)
AI_CONTEXT_MAX_TOKENS=8000

# Application Settings (APP_ENV=production limits the models, hides the test commands and sets a Secure cookie)
APP_ENV=development
APP_DEBUG=true

# Rate Limits (daily token limits: 0 = unlimited)
RATE_LIMIT_GUEST_HOURLY=10
RATE_LIMIT_GUEST_DAILY=20
RATE_LIMIT_GUEST_DAILY_TOKENS=0
RATE_LIMIT_USER_HOURLY=30
RATE_LIMIT_USER_DAILY=100
RATE_LIMIT_USER_DAILY_TOKENS=0
```

`APP_DEBUG=true` turns on php-via's dev mode and the signal debug panel. `VIA_DEVBAR=1` adds php-via's Dev Bar, which shows session and context ids, so keep it off in production.

### Server Configuration

Host, port and database path go into `config/autoload/app.local.php`:

```php
<?php

return [
    'server' => [
        'host' => '127.0.0.1',   // default 0.0.0.0
        'port' => 3200,          // default 8080
    ],
    'database' => [
        'path' => '/var/lib/ai-chatbot/db.sqlite',
    ],
];
```

A port set under the old `mezzio-swoole.swoole-http-server` key is still read when `server` does not set one. The server always runs a single worker: live state and sessions are kept in its memory.

## 🚢 Deployment

The app is one long-running PHP process. Put a reverse proxy in front for TLS and compression, bind the app to `127.0.0.1`, and run it under systemd.

### Production Checklist

1. `APP_ENV=production` and `APP_DEBUG=false` in `.env`; no `VIA_DEVBAR`
2. Require a released php-via version in `composer.json` and drop the `../php-via-014` path repository
3. `server.host` set to `127.0.0.1` in `config/autoload/app.local.php`
4. HTTPS in front: in production the session cookie is `Secure` with the `__Host-` prefix
5. After each update: back up `data/db.sqlite`, `composer install --no-dev --optimize-autoloader`, `php bin/migrate.php`, restart the service. A restart logs every user out and ends running replies, because sessions live in memory

### Caddy

php-via compresses pages, static files and the SSE streams with Brotli itself. That needs the `brotli` PHP extension, `'server' => ['brotli' => true]` in `config/autoload/app.local.php` (which turns on `withH2c()->withBrotli()`) and HTTP/2 cleartext from Caddy to the app, without Caddy's own `encode`:

```caddy
chat.example.com {
    reverse_proxy h2c://127.0.0.1:3200
}
```

`reverse_proxy` flushes `text/event-stream` responses immediately; php-via writes an SSE comment after 15 seconds of silence, so idle streams stay open. This setup (Caddy 2.11.6 with `h2c://`, php-via 0.14 with `withBrotli()`) served pages, static files and `/_sse` with `Content-Encoding: br` and passed a streaming browser run in October 2026. Each open SSE stream keeps its own Brotli encoder, which costs memory per tab (see php-via's deployment docs).

Without the brotli extension, leave `brotli` off and let Caddy compress instead: `encode zstd gzip` and a plain `reverse_proxy 127.0.0.1:3200`. Stock Caddy has no Brotli encoder.

### systemd

```ini
[Unit]
Description=AI Chatbot
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/ai-chatbot
ExecStart=/usr/bin/php8.5 bin/server.php
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

The process stays in the foreground and exits cleanly on SIGTERM, so `systemctl stop` and `restart` need no extra settings. Deploy new code with `restart`: the unit has no reload command, and an OpenSwoole worker reload would keep the classes the master process loaded at start.

### Moving a Mezzio/Swoole installation to php-via

For a server that runs the earlier version (`php8.5 vendor/bin/laminas mezzio:swoole:start` under systemd, Swoole loaded for PHP 8.5, Caddy in front). Deploy a commit that contains the php-via ports of documents, accounts, votes, visibility and chat deletion; the first php-via commits lack them.

1. Back up the database and note the running commit for a rollback:
   ```bash
   cp data/db.sqlite data/db.sqlite.$(date +%F).bak
   git rev-parse HEAD
   ```
2. Install OpenSwoole for PHP 8.5. Use the distribution package if one exists (`apt-cache policy php8.5-openswoole`), otherwise build it with OpenSSL:
   ```bash
   sudo apt install php8.5-dev libssl-dev
   pecl download openswoole-26.2.0 && tar xzf openswoole-26.2.0.tgz && cd openswoole-26.2.0
   phpize8.5 && ./configure --with-php-config=php-config8.5 --enable-openssl --enable-http2
   make -j"$(nproc)" && sudo make install
   echo 'extension=openswoole.so' | sudo tee /etc/php/8.5/mods-available/openswoole.ini
   ```
3. Swap the extensions for the PHP 8.5 CLI and check the result. Swoole and OpenSwoole cannot share a process, and PHP 8.4 is not involved:
   ```bash
   php8.5 --ini                          # find the ini file that loads swoole
   sudo phpdismod -v 8.5 -s cli swoole
   sudo phpenmod -v 8.5 -s cli openswoole
   php8.5 -m | grep -i swoole            # only "openswoole"
   php8.5 --ri openswoole | grep -E 'Version|openssl'
   ```
4. Update the code and dependencies with PHP 8.5 (the `php` on the path may be 8.4, which `composer.json` rejects):
   ```bash
   git pull
   php8.5 "$(command -v composer)" install --no-dev --optimize-autoloader
   php8.5 bin/migrate.php
   ```
5. Move the port in `config/autoload/app.local.php` from `mezzio-swoole.swoole-http-server` to the `server` key, with `'host' => '127.0.0.1'`. `bin/server.php` still reads the old key, but binds `0.0.0.0` unless a host is set there.
6. Point the unit at the new command. `systemctl cat chat.service` shows the unit and its drop-in; in the drop-in, clear and replace `ExecStart`, and remove any `Type=forking`, `PIDFile=`, `ExecStop=` or `ExecReload=` lines that belong to `mezzio:swoole`:
   ```ini
   [Service]
   Type=simple
   ExecStart=
   ExecStart=/usr/bin/php8.5 bin/server.php
   ```
   ```bash
   sudo systemctl daemon-reload && sudo systemctl restart chat
   journalctl -u chat -f
   ```
7. Brotli in php-via: build the `brotli` extension for PHP 8.5 if `php8.5 -m` does not list it, add `'brotli' => true` to the `server` key in `app.local.php`, and switch Caddy to `reverse_proxy h2c://127.0.0.1:3200` without `encode` for this site (`caddy validate`, then `systemctl reload caddy`). Do this in the same step as the restart: the Mezzio app does not speak h2c.
8. Check: `curl -s -D - -o /dev/null https://chat.example.com/` answers 200 with a `__Host-via_session_id` cookie, and a test message streams token by token. In the browser's network panel, `/_sse` stays open with `Content-Type: text/event-stream`.

Rollback: check out the noted commit, run `composer install --no-dev`, swap the extensions back (`phpdismod -v 8.5 -s cli openswoole`, `phpenmod -v 8.5 -s cli swoole`), restore the drop-in and restart. The port to php-via itself does not change the schema; if `migrate.php` applied a migration, restore the backup as well.

### Docker (Example)

```dockerfile
FROM php:8.5-cli

# OpenSwoole builds without OpenSSL unless asked; the AI clients need it for HTTPS
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev unzip \
    && pecl install -D 'enable-sockets="no" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="no" enable-hook-curl="no" with-postgres="no"' openswoole \
    && docker-php-ext-enable openswoole \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
# Only while composer.json points at ../php-via-014: build with --build-context php-via=../php-via-014
COPY --from=php-via . /php-via-014
COPY . .
RUN composer install --no-dev --optimize-autoloader

VOLUME /app/data
EXPOSE 8080
CMD ["sh", "-c", "php bin/init-db.php && php bin/migrate.php && exec php bin/server.php"]
```

```bash
docker build --build-context php-via=../php-via-014 -t ai-chatbot .
docker run -p 8080:8080 --env-file .env -v ai-chatbot-data:/app/data ai-chatbot
```

`.dockerignore` keeps `.env`, `vendor/`, the database and the benchmark data out of the image. The container listens on `0.0.0.0:8080` and creates or migrates the database on start. Without `APP_ENV` the app runs in production mode, where the session cookie is `Secure`, so put the container behind HTTPS.

## 📄 License

MIT License - see [LICENSE](LICENSE) for details.

## 🙏 Acknowledgments

- **Baseline:** [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot): the Next.js reference implementation we're comparing against
- **Server:** [php-via](https://via.zweiundeins.gmbh) on [OpenSwoole](https://openswoole.com/)
- **Reactivity:** [Datastar](https://data-star.dev/): HTML-over-the-wire without the JS framework tax

---

*Tired of JavaScript complexity?* [zwei und eins gmbh](https://zweiundeins.gmbh) builds high-performance PHP applications that compete with (and often outperform) modern JS stacks.
