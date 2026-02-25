# AI Chatbot — PHP/Swoole/Datastar Stack Showcase

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Swoole](https://img.shields.io/badge/Swoole-5.0+-007EC6?logo=swoole&logoColor=white)](https://openswoole.com/)
[![Mezzio](https://img.shields.io/badge/Mezzio-3.19-6C3BAF?logo=laminas&logoColor=white)](https://docs.mezzio.dev/)
[![Datastar](https://img.shields.io/badge/Datastar-1.0-FF6B35?logo=rocket&logoColor=white)](https://data-star.dev/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![Made by zweiundeins.gmbh](https://img.shields.io/badge/Made%20with%20%E2%98%95%20by-zweiundeins.gmbh-blue)](https://zweiundeins.gmbh)

**[🚀 Live Demo](https://chat.zweiundeins.gmbh)** | **[📊 Benchmark Results](benchmarks/RESULTS.md)** | **[📝 Blog Post](https://zweiundeins.gmbh/en/methodology/spa-vs-hypermedia-real-world-performance-under-load)**

A real-time AI chatbot built with **PHP 8.2+**, **Swoole**, **Mezzio**, and **Datastar**. Features streaming responses, document/artifact generation, and a modern reactive UI—all without JavaScript frameworks.

> **🎯 Project Goal:** This is a side-by-side comparison with the [Vercel AI Chatbot (Next.js)](https://github.com/vercel/ai-chatbot), demonstrating that a lean PHP stack can deliver the same features with **dramatically less complexity** and **better performance**.

## 🆚 The Comparison: Next.js vs PHP

This project exists to challenge the assumption that modern AI chat apps require heavy JavaScript stacks. We rebuilt the Vercel AI Chatbot using PHP—and the results speak for themselves.

> **Context:** The [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) has **86 contributors** and **600+ commits** of optimization. This PHP port is a straightforward implementation with minimal optimization—yet outperforms on most metrics.

### Measured Performance (February 2026)

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

| Aspect | Next.js | PHP/Swoole | Ratio |
|--------|---------|------------|-------|
| **Dependencies (prod)** | 799 packages | **69 packages** | **11.6x fewer** |
| **node_modules / vendor** | 793 MB | **25 MB** | **31.7x smaller** |
| **Build Step** | Required | **None** | — |
| **Hosting Cost** | Usage-based | **$20/year VPS** | — |

**The takeaway:** Modern PHP with Swoole is a serious contender for real-time applications. No transpilation, no hydration, no serverless cold starts—just fast, efficient code.

> ⚠️ **Feature Completeness:** This is a **working proof-of-concept**, not a production-ready clone. Core features (chat, streaming, artifacts, auth, voting) work. Missing: file attachments, edit/regenerate messages. See the [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) for the full-featured original.

## ✨ Features

- **Real-time AI Streaming** - Token-by-token streaming via Server-Sent Events (SSE)
- **Multiple AI Providers** - Support for Anthropic (Claude) and OpenAI (GPT) models
- **Document Artifacts** - AI can create and edit code, text, spreadsheets, and images
- **CQRS Architecture** - Clean separation of commands, queries, and events
- **Session-based Auth** - Simple authentication with guest and registered user support
- **Rate Limiting** - Configurable daily message limits for guests and registered users
- **Responsive UI** - Mobile-friendly design with sidebar navigation
- **No Build Required** - Datastar provides reactivity without complex JS bundling
- **Zero CDN Dependencies** - All assets served locally (Open Props, marked.js, SVG icons)

## 📋 Requirements

- PHP 8.2 or higher
- Swoole extension (`pecl install swoole`)
- SQLite3 extension
- Composer
- Node.js 18+ (optional, for TypeScript development)

## 🚀 Quick Start

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd ai-chatbot

# Install PHP dependencies
composer install

# Install frontend dependencies (optional)
npm install
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
AI_DEFAULT_MODEL=claude-sonnet-4-5
AI_MAX_TOKENS=4096
```

Optionally copy the local PHP config for additional settings:

```bash
cp config/autoload/app.local.php.dist config/autoload/app.local.php
```

### 3. Initialize Database

```bash
# Initialize the SQLite database
composer db:init

# Or manually:
sqlite3 data/db.sqlite < data/schema.sql
```

### 4. Start the Server

```bash
# Start the Swoole server (runs on http://localhost:8080)
composer serve
```

Visit **http://localhost:8080** in your browser.

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  Frontend (Datastar + TypeScript)                               │
│  └── SSE connection to /updates for real-time DOM patching      │
├─────────────────────────────────────────────────────────────────┤
│  Infrastructure Layer                                           │
│  ├── Http/Handler/Command/  → POST/PUT/DELETE mutations         │
│  ├── Http/Handler/Query/    → GET read operations               │
│  ├── Http/Listener/         → SseRequestListener for streaming  │
│  └── AI/                    → LLPhantAIService, streaming tools │
├─────────────────────────────────────────────────────────────────┤
│  Application Layer (Events)                                     │
│  ├── Domain/Event/          → MessageStreamingEvent, ChatUpdated│
│  └── EventBus/              → SwooleEventBus for SSE broadcasts │
├─────────────────────────────────────────────────────────────────┤
│  Domain Layer                                                   │
│  ├── Model/                 → Chat, Message, Document, User     │
│  ├── Service/               → AIServiceInterface, RateLimitSvc  │
│  └── Repository/            → Interface definitions             │
├─────────────────────────────────────────────────────────────────┤
│  Persistence (SQLite)                                           │
│  └── data/db.sqlite                                             │
└─────────────────────────────────────────────────────────────────┘
```

### CQRS Pattern

The application follows the Command Query Responsibility Segregation pattern:

- **Commands** (`/cmd/*`) - POST/PUT/DELETE operations that modify state
- **Queries** (`/api/*`) - GET operations that read state
- **Events** - Emitted when state changes, broadcast to clients via SSE

### Real-time Streaming Flow (Fat Morph)

1. User sends message via `POST /cmd/chat/{chatId}/message`
2. `MessageCommandHandler` creates user + assistant message placeholders
3. Swoole coroutine starts `streamAiResponse()` calling `AIService::streamChat()`
4. Server accumulates full content; each chunk emits `MessageStreamingEvent` with `fullContent`
5. `SseRequestListener` renders full markdown server-side and sends `PatchElements`
6. Datastar morphs the DOM efficiently (only changed nodes update)
7. Container auto-scrolls via `data-on:datastar-fetch__window`

**Why fat morph?** Brotli compresses repetitive content ~same as deltas. Server-side markdown means no client scripts per chunk. DOM stays clean — just rendered HTML.

## 📁 Project Structure

```
├── bin/
│   ├── init-db.php           # Database initialization script
│   └── seed.php              # Sample data seeder
├── config/
│   ├── config.php            # Config aggregator
│   ├── container.php         # DI container setup
│   ├── routes.php            # Route definitions
│   ├── pipeline.php          # Middleware pipeline
│   └── autoload/             # Environment-specific configs
├── data/
│   ├── schema.sql            # Database schema
│   └── db.sqlite             # SQLite database (created on init)
├── public/
│   ├── css/
│   │   ├── app.css               # Custom styles
│   │   └── open-props-bundle.css # Open Props CSS (bundled)
│   ├── icons.svg             # SVG icon sprite
│   └── js/
│       ├── app.js            # Custom TypeScript (compiled)
│       ├── datastar.js       # Datastar library
│       └── marked.min.js     # Markdown parser
├── src/App/
│   ├── ConfigProvider.php    # DI factories
│   ├── Domain/
│   │   ├── Event/            # Domain events
│   │   ├── Model/            # Entity classes (Chat, Message, Document, etc.)
│   │   ├── Repository/       # Repository interfaces
│   │   └── Service/          # Service interfaces
│   └── Infrastructure/
│       ├── AI/               # AI service implementations
│       ├── Auth/             # Authentication middleware
│       ├── EventBus/         # SSE event broadcasting
│       ├── Http/Handler/     # Request handlers
│       ├── Persistence/      # SQLite repositories
│       ├── Session/          # Swoole-based sessions
│       └── Template/         # Template renderer
├── templates/
│   ├── app/                  # Page templates
│   ├── layout/               # Layout templates
│   └── partials/             # Reusable components
└── tests/
    ├── Feature/              # Integration tests
    └── Unit/                 # Unit tests
```

## 🔌 API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login` | Login with email/password |
| POST | `/auth/register` | Register new account |
| POST | `/auth/logout` | Logout current session |
| POST | `/auth/upgrade` | Upgrade guest to registered |
| GET | `/auth/status` | Get current auth status |

### Queries (Read Operations)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/chats` | List user's chats |
| GET | `/api/chats/{id}` | Get chat details |
| GET | `/api/chats/{id}/messages` | Get chat messages |
| GET | `/api/documents/{id}` | Get document/artifact |

### Commands (Write Operations)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/cmd/chat` | Create new chat |
| DELETE | `/cmd/chat/{id}` | Delete chat |
| PATCH | `/cmd/chat/{id}/visibility` | Toggle public/private |
| POST | `/cmd/chat/{chatId}/message` | Send message & generate response |
| POST | `/cmd/chat/{chatId}/generate` | Regenerate AI response |
| POST | `/cmd/chat/{chatId}/stop` | Stop streaming response |
| POST | `/cmd/document` | Create document |
| PUT | `/cmd/document/{id}` | Update document |
| DELETE | `/cmd/document/{id}` | Delete document |
| PATCH | `/cmd/vote/{chatId}/{messageId}` | Vote on message |

### Real-time

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/updates` | SSE endpoint for real-time updates |

## 🤖 AI Models

### Supported Models

**Anthropic (Claude 4.5)**
- `claude-opus-4-5` - Claude Opus 4.5 (Maximum intelligence)
- `claude-sonnet-4-5` - Claude Sonnet 4.5 (Best balance)
- `claude-haiku-4-5` - Claude Haiku 4.5 (Fast/Cheap)

**Anthropic (Legacy)**
- `claude-opus-4-1` - Claude Opus 4.1
- `claude-opus-4` - Claude Opus 4
- `claude-sonnet-4` - Claude Sonnet 4
- `claude-3-5-haiku-20241022` - Claude Haiku 3.5
- `claude-3-haiku-20240307` - Claude Haiku 3 (Cheapest!)

**OpenAI (GPT-5.x)**
- `gpt-5.2` / `gpt-5.1` / `gpt-5` - Full capability
- `gpt-5-mini` - Balanced (cost-effective)
- `gpt-5-nano` - Cheapest

**OpenAI (GPT-4.x)**
- `gpt-4.1` / `gpt-4.1-mini` / `gpt-4.1-nano`
- `gpt-4o` / `gpt-4o-mini`

### AI Tools

The AI can use tools to create and update documents:

- **CreateDocument** - Create code, text, spreadsheet, or image artifacts
- **UpdateDocument** - Modify existing artifacts

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
composer serve          # Start Swoole server at :8080
composer stop           # Stop Swoole server
composer reload         # Reload Swoole workers

# Database
composer db:init        # Initialize database schema
composer db:seed        # Seed with sample data

# Testing
composer test           # Run Pest tests
composer test:coverage  # Run tests with coverage

# Code Quality
composer cs             # Check code style (dry-run)
composer cs:fix         # Fix code style issues
composer stan           # Run PHPStan static analysis

# Frontend (optional)
npm run build           # Build TypeScript with esbuild
npm run watch           # Watch mode for development
npm run typecheck       # TypeScript type checking
```

### Running Tests

Tests use Pest PHP with in-memory SQLite:

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/pest tests/Unit/ChatTest.php

# Run with coverage
composer test:coverage
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

The frontend uses [Datastar](https://data-star.dev/) for reactive UI without JavaScript frameworks.

### Key Concepts

- **Signals** - Client-side state (form inputs, UI flags)
- **PatchElements** - Server-sent HTML fragments that update DOM
- **ExecuteScript** - Server-sent JavaScript execution
- **Actions** - Declarative HTTP requests (`@post`, `@get`, etc.)

### Example Usage

```html
<!-- SSE connection for real-time updates -->
<div data-init="@get('/updates')">

<!-- Form with signal binding -->
<input type="text" data-model="$message" />

<!-- Action on click -->
<button data-on:click="@post('/cmd/chat/123/message')">
    Send
</button>

<!-- Conditional rendering -->
<div data-show="$isGenerating">
    Generating...
</div>
```

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
AI_DEFAULT_MODEL=claude-3-haiku-20240307
AI_MAX_TOKENS=2048

# Context compression settings
AI_CONTEXT_RECENT_MESSAGES=6
AI_CONTEXT_MAX_OLDER_CHARS=500

# Application Settings
APP_ENV=development
APP_DEBUG=true

# Rate Limits
RATE_LIMIT_GUEST_HOURLY=10
RATE_LIMIT_GUEST_DAILY=20
RATE_LIMIT_USER_HOURLY=30
RATE_LIMIT_USER_DAILY=100
```

For additional PHP configuration overrides, create `config/autoload/app.local.php`:

```php
<?php

return [
    'database' => [
        'path' => getcwd() . '/data/db.sqlite',
    ],
    'templates' => [
        'paths' => [
            '' => getcwd() . '/templates',
        ],
    ],
];
```

### Swoole Configuration

Default Swoole settings can be overridden in `config/autoload/swoole.local.php`:

```php
<?php

return [
    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '0.0.0.0',
            'port' => 8080,
            'options' => [
                'worker_num' => 4,
                'task_worker_num' => 2,
                'max_request' => 10000,
            ],
        ],
    ],
];
```

## 🚢 Deployment

### Production Checklist

1. Set `debug` to `false` in configuration
2. Use strong session secrets
3. Configure proper rate limits
4. Set up SSL/TLS termination (nginx/Caddy)
5. Configure log rotation

### Example Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name chat.example.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # SSE endpoint needs special handling
    location /updates {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection '';
        proxy_buffering off;
        proxy_cache off;
        chunked_transfer_encoding off;
    }
}
```

### Docker (Example)

```dockerfile
FROM php:8.2-cli

RUN pecl install openswoole && docker-php-ext-enable openswoole
RUN docker-php-ext-install pdo pdo_sqlite

WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader

EXPOSE 8080
CMD ["php", "vendor/bin/laminas", "mezzio:swoole:start"]
```

## 📄 License

MIT License - see [LICENSE](LICENSE) for details.

## 🙏 Acknowledgments

- **Baseline:** [Vercel AI Chatbot](https://github.com/vercel/ai-chatbot) — the Next.js reference implementation we're comparing against
- **AI Integration:** [LLPhant](https://github.com/theodo-group/LLPhant) — PHP library for LLM interactions
- **Reactivity:** [Datastar](https://data-star.dev/) — HTML-over-the-wire without the JS framework tax

---

*Tired of JavaScript complexity?* [zwei und eins gmbh](https://zweiundeins.gmbh) builds high-performance PHP applications that compete with (and often outperform) modern JS stacks.
