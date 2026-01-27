# AI Chatbot Migration Plan

## From: Next.js + Vercel AI SDK → To: PHP/Swoole/Mezzio + Datastar

**Goal**: Compare complexity and performance between the two approaches

---

## Decisions Made

| Decision | Choice |
|----------|--------|
| **AI Provider** | Claude (Anthropic) via LLPhant, extensible for others |
| **AI Library** | [LLPhant](https://github.com/theodo-group/LLPhant) - multi-provider, streaming, tools |
| **Database** | SQLite (like timeline) |
| **Auth Library** | [delight-im/PHP-Auth](https://github.com/delight-im/PHP-Auth) |
| **Artifacts** | Full support (text, code, sheet, image) |
| **Code Execution** | Pyodide from CDN (client-side) |
| **Feature Scope** | Full parity where possible |
| **Project Location** | `/var/www/ai-chatbot/` (alongside the Next.js submodule) |
| **CSS Framework** | [Open Props](https://open-props.style/) (CSS custom properties) |
| **Deployment** | Caddy (same as timeline) |
| **Testing** | Pest PHP |

---

## 1. Source Analysis (Vercel AI Chatbot)

### Core Features Identified

| Feature | Description | Priority |
|---------|-------------|----------|
| **Streaming Chat** | Real-time AI response streaming via SSE | P0 - Critical |
| **Multi-model Support** | Anthropic, OpenAI, Google, xAI | P0 - Critical |
| **Authentication** | Login/register with guest users | P0 - Critical |
| **Chat History** | Persistent chats with sidebar | P1 - High |
| **Artifacts** | Text, Code, Sheet, Image documents | P1 - High |
| **Document Versioning** | Undo/redo for artifacts | P2 - Medium |
| **Message Voting** | Upvote/downvote responses | P2 - Medium |
| **Suggestions** | AI writing suggestions | P3 - Low |
| **Rate Limiting** | Per-user message limits | P1 - High |
| **File Uploads** | Image attachments | P2 - Medium |

### Database Schema (to replicate)

```
User
├── id (uuid, PK)
├── email (unique)
├── password (hashed)
└── created_at

Chat
├── id (uuid, PK)
├── user_id (FK → User)
├── title
├── visibility (public/private)
└── created_at

Message
├── id (uuid, PK)
├── chat_id (FK → Chat)
├── role (user/assistant/system)
├── content (JSON - parts)
├── attachments (JSON)
└── created_at

Document (Artifacts)
├── id (uuid)
├── created_at (part of PK - versioning)
├── user_id (FK → User)
├── title
├── content (text)
├── kind (text/code/sheet/image)
└── PK(id, created_at)

Vote
├── chat_id (FK)
├── message_id (FK)
├── is_upvoted (bool)
└── PK(chat_id, message_id)
```

### API Endpoints (to replicate)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/chat` | Send message, stream response |
| DELETE | `/api/chat` | Delete a chat |
| GET | `/api/history` | Paginated chat history |
| DELETE | `/api/history` | Delete all chats |
| GET | `/api/document` | Get document versions |
| POST | `/api/document` | Save document |
| DELETE | `/api/document` | Delete document |
| GET | `/api/vote` | Get votes for chat |
| PATCH | `/api/vote` | Vote on message |
| POST | `/api/auth/*` | Authentication |

### AI Tools (function calling)

1. **getWeather** - Get weather data (requires user confirmation)
2. **createDocument** - Create new artifact
3. **updateDocument** - Update existing artifact  
4. **requestSuggestions** - Get writing suggestions

---

## 2. Target Architecture (PHP/Swoole/Mezzio + Datastar)

### Key Libraries

| Library | Purpose | Notes |
|---------|---------|-------|
| `theodo-group/llphant` | AI/LLM integration | Multi-provider (Anthropic, OpenAI, Mistral, Ollama), streaming, tools |
| `delight-im/auth` | Authentication | Session-based, built-in throttling, roles, SQLite support |
| `starfederation/datastar-php` | SSE/Datastar events | Already used in timeline |
| `mezzio/mezzio-swoole` | HTTP server | Long-running, SSE capable |
| Open Props | Frontend styling | CSS custom properties, no build step, modern defaults |

### Project Structure

```
/var/www/ai-chatbot/
├── ai-chatbot/                    # Next.js submodule (for reference)
├── bin/
│   ├── server.php                 # Swoole entry point
│   └── seed.php                   # Database seeder
├── config/
│   ├── config.php
│   ├── container.php
│   ├── routes.php
│   └── autoload/
│       ├── dependencies.global.php
│       ├── mezzio.global.php
│       └── swoole.global.php
├── data/
│   ├── schema.sql                 # Combined schema (auth + app)
│   └── db.sqlite
├── public/
│   ├── index.php                  # Fallback for non-Swoole
│   ├── js/
│   │   ├── datastar.js
│   │   └── app.js                 # Pyodide integration, etc.
│   └── css/
│       └── app.css                # Custom styles (Open Props via CDN)
├── src/
│   └── App/
│       ├── ConfigProvider.php
│       ├── Application/
│       │   ├── Command/
│       │   │   ├── SendMessage/
│       │   │   │   ├── SendMessageCommand.php
│       │   │   │   └── SendMessageHandler.php
│       │   │   ├── CreateChat/
│       │   │   ├── DeleteChat/
│       │   │   ├── CreateDocument/
│       │   │   ├── UpdateDocument/
│       │   │   └── VoteMessage/
│       │   └── Query/
│       │       ├── GetChat/
│       │       ├── GetChatHistory/
│       │       ├── GetDocument/
│       │       └── GetModels/
│       ├── Domain/
│       │   ├── Event/
│       │   │   ├── MessageStreamingEvent.php
│       │   │   ├── ChatUpdatedEvent.php
│       │   │   └── DocumentUpdatedEvent.php
│       │   ├── Model/
│       │   │   ├── Chat.php
│       │   │   ├── Message.php
│       │   │   ├── Document.php
│       │   │   └── Vote.php
│       │   ├── Repository/
│       │   │   ├── ChatRepositoryInterface.php
│       │   │   ├── MessageRepositoryInterface.php
│       │   │   └── DocumentRepositoryInterface.php
│       │   └── Service/
│       │       ├── AIService.php
│       │       └── AIToolExecutor.php
│       └── Infrastructure/
│           ├── AI/
│           │   ├── LLPhantChatService.php
│           │   ├── StreamingHandler.php
│           │   └── Tools/
│           │       ├── CreateDocumentTool.php
│           │       ├── UpdateDocumentTool.php
│           │       ├── RequestSuggestionsTool.php
│           │       └── GetWeatherTool.php
│           ├── EventBus/
│           │   ├── EventBusInterface.php
│           │   └── SwooleEventBus.php
│           ├── Http/
│           │   ├── Handler/
│           │   │   ├── HomeHandler.php
│           │   │   ├── ChatHandler.php
│           │   │   ├── UpdatesHandler.php
│           │   │   ├── Command/
│           │   │   │   ├── ChatCommandHandler.php
│           │   │   │   ├── MessageCommandHandler.php
│           │   │   │   ├── DocumentCommandHandler.php
│           │   │   │   ├── VoteCommandHandler.php
│           │   │   │   └── AuthCommandHandler.php
│           │   │   └── Query/
│           │   │       ├── ChatQueryHandler.php
│           │   │       ├── HistoryQueryHandler.php
│           │   │       └── DocumentQueryHandler.php
│           │   ├── Listener/
│           │   │   └── SseRequestListener.php
│           │   └── Middleware/
│           │       ├── AuthMiddleware.php
│           │       ├── GuestUserMiddleware.php
│           │       ├── RateLimitMiddleware.php
│           │       └── JsonBodyParserMiddleware.php
│           ├── Persistence/
│           │   ├── SqliteChatRepository.php
│           │   ├── SqliteMessageRepository.php
│           │   └── SqliteDocumentRepository.php
│           └── Template/
│               └── TemplateRenderer.php
├── templates/
│   ├── layout.php                 # Base layout with Open Props
│   ├── home.php                   # Main chat page
│   └── partials/
│       ├── sidebar.php            # Chat history
│       ├── chat.php               # Chat area
│       ├── message.php            # Single message
│       ├── message-user.php
│       ├── message-assistant.php
│       ├── artifact.php           # Artifact panel
│       ├── artifact-text.php
│       ├── artifact-code.php
│       ├── artifact-sheet.php
│       ├── artifact-image.php
│       ├── model-selector.php
│       └── modals/
│           ├── login.php
│           ├── register.php
│           └── confirm-action.php
├── tests/
│   ├── Pest.php
│   ├── TestCase.php
│   ├── Unit/
│   │   ├── Domain/
│   │   │   ├── MessageTest.php
│   │   │   ├── ChatTest.php
│   │   │   └── DocumentTest.php
│   │   └── Infrastructure/
│   │       └── AI/
│   │           └── StreamingHandlerTest.php
│   └── Feature/
│       ├── ChatRepositoryTest.php
│       ├── SendMessageTest.php
│       ├── AuthenticationTest.php
│       ├── DocumentTest.php
│       └── RateLimitTest.php
├── composer.json
├── package.json                   # For TypeScript (optional, like timeline)
├── phpunit.xml
├── chatbot.caddyfile
├── chatbot.service
└── README.md
```

### Key Architectural Decisions

#### 1. Authentication with delight-im/PHP-Auth

PHP-Auth handles all auth complexity for us:
- Session management (compatible with Swoole via custom session handler)
- Password hashing (bcrypt/argon2)
- Built-in throttling
- Roles system (guest vs registered)
- SQLite support out of the box

```php
// Simple usage
$db = new PDO('sqlite:./data/db.sqlite');
$auth = new \Delight\Auth\Auth($db);

// Register
$userId = $auth->register($email, $password);

// Login
$auth->login($email, $password, $rememberDuration);

// Check status
if ($auth->isLoggedIn()) {
    $userId = $auth->getUserId();
    $email = $auth->getEmail();
}
```

Guest users: We'll auto-create a guest user on first visit using `Auth::createUuid()` and store in session.

#### 2. AI Integration with LLPhant

LLPhant provides a clean abstraction for multiple providers:

```php
use LLPhant\Chat\AnthropicChat;
use LLPhant\Chat\OpenAIChat;

// Anthropic (primary)
$chat = new AnthropicChat(new AnthropicConfig(AnthropicConfig::CLAUDE_3_5_SONNET));

// Streaming response
$stream = $chat->generateStreamOfText($prompt);
foreach ($stream as $chunk) {
    // Send via SSE
    $response->write($chunk);
}

// Tool calling (for artifacts)
$tool = FunctionBuilder::buildFunctionInfo(new CreateDocumentTool(), 'createDocument');
$chat->addTool($tool);
```

#### 3. SSE Streaming Architecture (same as timeline)

```
┌─────────────────────────────────────────────────────────────────┐
│                         Browser                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Datastar                                                 │   │
│  │  - data-on:submit → POST /cmd/chat/send                  │   │
│  │  - data-init="@get('/updates')" → SSE connection         │   │
│  └─────────────────────────────────────────────────────────┘   │
└──────────────────────────┬──────────────────────────────────────┘
                           │
          ┌────────────────┴────────────────┐
          │         Swoole Server            │
          │    (Long-running process)        │
          ├──────────────────────────────────┤
          │                                  │
          │  POST /cmd/chat/send             │
          │  ┌──────────────────────────┐   │
          │  │ SendMessageHandler        │   │
          │  │ 1. Save user message      │   │
          │  │ 2. Start AI streaming     │   │
          │  │ 3. Emit MessageCreated    │──┼──► EventBus
          │  │ 4. Return 204 No Content  │   │
          │  └──────────────────────────┘   │
          │                                  │
          │  GET /updates (SSE)              │
          │  ┌──────────────────────────┐   │
          │  │ SseRequestListener        │   │
          │  │ 1. Subscribe to EventBus  │◄─┼── EventBus
          │  │ 2. On event → PatchElements│  │
          │  │ 3. Stream AI chunks       │   │
          │  └──────────────────────────┘   │
          └──────────────────────────────────┘
```

#### 4. AI Streaming via SSE + Datastar

Instead of complex client-side state management (useChat, SWR, contexts), we use:

```php
// Server-side: Stream AI response chunks via Datastar
// In SseRequestListener, when AI generates chunks:
foreach ($aiStream as $chunk) {
    $event = new MergeFragments(
        '<span data-append="#message-' . $messageId . '-content">' . 
        htmlspecialchars($chunk) . 
        '</span>'
    );
    $response->write($event->getOutput());
}
```

```html
<!-- Client-side: Simple Datastar attributes with Open Props styling -->
<form style="padding: var(--size-4); background: var(--surface-2); border-radius: var(--radius-2);" 
      data-on:submit__prevent="@post('/cmd/chat/send', {contentType: 'form'})">
    <div style="margin-block-end: var(--size-3);">
        <textarea name="message" data-bind="$_message" 
                  placeholder="Send a message..."
                  style="width: 100%; padding: var(--size-2); border-radius: var(--radius-2); border: 1px solid var(--surface-3); background: var(--surface-1); color: var(--text-1); resize: vertical; min-height: 80px;"></textarea>
    </div>
    <button class="btn" type="submit" style="background: var(--blue-7); color: white;">
        <i class="fas fa-paper-plane"></i> Send
    </button>
</form>

<div id="messages">
    <!-- Messages patched here via SSE -->
</div>
```

#### 5. Pyodide for Code Execution (Client-Side)

```html
<!-- Load Pyodide from CDN -->
<script src="https://cdn.jsdelivr.net/pyodide/v0.26.4/full/pyodide.js"></script>

<script>
// In app.js - initialize Pyodide once
let pyodide = null;
async function initPyodide() {
    if (!pyodide) {
        pyodide = await loadPyodide();
    }
    return pyodide;
}

// Execute Python code
async function runPython(code) {
    const py = await initPyodide();
    try {
        const result = await py.runPythonAsync(code);
        return { success: true, output: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

// Expose to window for Datastar
window.runPythonCode = runPython;
</script>

<!-- Artifact code editor with run button -->
<div class="artifact-code" style="padding: var(--size-3);">
    <pre style="background: var(--surface-1); padding: var(--size-3); border-radius: var(--radius-2); overflow-x: auto;"><code id="code-content"><!-- Code here --></code></pre>
    <button class="btn" style="background: var(--green-7); color: white; margin-block: var(--size-2);" 
            data-on:click="const result = await window.runPythonCode($_codeContent); $_output = result.output">
        ▶ Run
    </button>
    <div id="console-output" style="background: var(--gray-9); color: var(--gray-0); padding: var(--size-3); border-radius: var(--radius-2); font-family: var(--font-mono);">
        <pre data-text="$_output"></pre>
    </div>
</div>
```

#### 6. CQRS Pattern (like timeline)

**Commands** (write operations):
- `SendMessageCommand` → Creates message, triggers AI
- `CreateChatCommand` → Creates new chat
- `DeleteChatCommand` → Deletes chat
- `CreateDocumentCommand` → Creates artifact
- `VoteMessageCommand` → Votes on message

**Queries** (read operations):
- `GetChatQuery` → Returns chat with messages
- `GetHistoryQuery` → Returns paginated history
- `GetDocumentQuery` → Returns document versions

### Database Schema

Combined schema for PHP-Auth + Application:

```sql
-- =====================================================
-- PHP-Auth tables (from delight-im/auth SQLite schema)
-- Source: https://github.com/delight-im/PHP-Auth/blob/master/Database/SQLite.sql
-- =====================================================

PRAGMA foreign_keys = OFF;

CREATE TABLE "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "email" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("email") <= 249),
    "password" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("password") <= 255),
    "username" TEXT DEFAULT NULL COLLATE NOCASE CHECK (LENGTH("username") <= 100),
    "status" INTEGER NOT NULL CHECK ("status" >= 0) DEFAULT 0,
    "verified" INTEGER NOT NULL CHECK ("verified" >= 0 AND "verified" <= 1) DEFAULT 0,
    "resettable" INTEGER NOT NULL CHECK ("resettable" >= 0 AND "resettable" <= 1) DEFAULT 1,
    "roles_mask" INTEGER NOT NULL CHECK ("roles_mask" >= 0) DEFAULT 0,
    "registered" INTEGER NOT NULL CHECK ("registered" >= 0),
    "last_login" INTEGER CHECK ("last_login" >= 0) DEFAULT NULL,
    "force_logout" INTEGER NOT NULL CHECK ("force_logout" >= 0) DEFAULT 0,
    CONSTRAINT "users_email_uq" UNIQUE ("email")
);

CREATE TABLE "users_2fa" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "mechanism" INTEGER NOT NULL CHECK ("mechanism" >= 0),
    "seed" TEXT DEFAULT NULL COLLATE BINARY CHECK (LENGTH("seed") <= 255),
    "created_at" INTEGER NOT NULL CHECK ("created_at" >= 0),
    "expires_at" INTEGER CHECK ("expires_at" >= 0) DEFAULT NULL,
    CONSTRAINT "users_2fa_user_id_mechanism_uq" UNIQUE ("user_id", "mechanism")
);

CREATE TABLE "users_audit_log" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER DEFAULT NULL CHECK ("user_id" >= 0),
    "event_at" INTEGER NOT NULL CHECK ("event_at" >= 0),
    "event_type" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("event_type") <= 128),
    "admin_id" INTEGER DEFAULT NULL CHECK ("admin_id" >= 0),
    "ip_address" TEXT DEFAULT NULL COLLATE NOCASE CHECK (LENGTH("ip_address") <= 49),
    "user_agent" TEXT DEFAULT NULL,
    "details_json" TEXT DEFAULT NULL
);
CREATE INDEX "users_audit_log_event_at_ix" ON "users_audit_log" ("event_at");
CREATE INDEX "users_audit_log_user_id_event_at_ix" ON "users_audit_log" ("user_id", "event_at");
CREATE INDEX "users_audit_log_user_id_event_type_event_at_ix" ON "users_audit_log" ("user_id", "event_type", "event_at");

CREATE TABLE "users_confirmations" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "email" TEXT NOT NULL COLLATE NOCASE CHECK (LENGTH("email") <= 249),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 16),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_confirmations_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_confirmations_email_expires_ix" ON "users_confirmations" ("email", "expires");
CREATE INDEX "users_confirmations_user_id_ix" ON "users_confirmations" ("user_id");

CREATE TABLE "users_otps" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "mechanism" INTEGER NOT NULL CHECK ("mechanism" >= 0),
    "single_factor" INTEGER NOT NULL CHECK ("single_factor" >= 0 AND "single_factor" <= 1) DEFAULT 0,
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 24),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires_at" INTEGER CHECK ("expires_at" >= 0) DEFAULT NULL
);
CREATE INDEX "users_otps_user_id_mechanism_ix" ON "users_otps" ("user_id", "mechanism");
CREATE INDEX "users_otps_selector_user_id_ix" ON "users_otps" ("selector", "user_id");

CREATE TABLE "users_remembered" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 24),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_remembered_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_remembered_user_ix" ON "users_remembered" ("user");

CREATE TABLE "users_resets" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("selector") <= 20),
    "token" TEXT NOT NULL COLLATE BINARY CHECK (LENGTH("token") <= 255),
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "users_resets_selector_uq" UNIQUE ("selector")
);
CREATE INDEX "users_resets_user_expires_ix" ON "users_resets" ("user", "expires");

CREATE TABLE "users_throttling" (
    "bucket" TEXT PRIMARY KEY NOT NULL COLLATE BINARY CHECK (LENGTH("bucket") <= 44),
    "tokens" REAL NOT NULL CHECK ("tokens" >= 0),
    "replenished_at" INTEGER NOT NULL CHECK ("replenished_at" >= 0),
    "expires_at" INTEGER NOT NULL CHECK ("expires_at" >= 0)
);
CREATE INDEX "users_throttling_expires_at_ix" ON "users_throttling" ("expires_at");

-- =====================================================
-- Application tables
-- =====================================================

-- Chats
CREATE TABLE IF NOT EXISTS chats (
    id TEXT PRIMARY KEY,              -- UUID
    user_id INTEGER NOT NULL,
    title TEXT,
    model TEXT NOT NULL DEFAULT 'claude-3-5-sonnet',
    visibility TEXT NOT NULL DEFAULT 'private',  -- 'private' or 'public'
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_chats_user_id ON chats(user_id);
CREATE INDEX idx_chats_created_at ON chats(created_at);

-- Messages  
CREATE TABLE IF NOT EXISTS messages (
    id TEXT PRIMARY KEY,              -- UUID
    chat_id TEXT NOT NULL,
    role TEXT NOT NULL,               -- 'user', 'assistant', 'system'
    content TEXT,                     -- Plain text for simple messages
    parts TEXT,                       -- JSON array for complex content (tool calls, etc.)
    attachments TEXT,                 -- JSON array for file attachments
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
);

CREATE INDEX idx_messages_chat_id ON messages(chat_id);

-- Documents (Artifacts)
CREATE TABLE IF NOT EXISTS documents (
    id TEXT NOT NULL,                 -- UUID (same ID for all versions)
    created_at TEXT NOT NULL,         -- Part of composite PK for versioning
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT,
    kind TEXT NOT NULL,               -- 'text', 'code', 'sheet', 'image'
    language TEXT,                    -- For code: 'python', etc.
    PRIMARY KEY (id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_documents_user_id ON documents(user_id);

-- Votes
CREATE TABLE IF NOT EXISTS votes (
    chat_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    is_upvoted INTEGER NOT NULL,      -- 1 = upvote, 0 = downvote
    PRIMARY KEY (chat_id, message_id),
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);

-- Suggestions (for documents)
CREATE TABLE IF NOT EXISTS suggestions (
    id TEXT PRIMARY KEY,              -- UUID
    document_id TEXT NOT NULL,
    document_created_at TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    original_text TEXT NOT NULL,
    suggested_text TEXT NOT NULL,
    description TEXT,
    is_resolved INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (document_id, document_created_at) REFERENCES documents(id, created_at) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rate limiting (application-level, beyond PHP-Auth throttling)
CREATE TABLE IF NOT EXISTS user_message_counts (
    user_id INTEGER NOT NULL,
    date TEXT NOT NULL,               -- YYYY-MM-DD
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Guest users mapping (for anonymous users)
CREATE TABLE IF NOT EXISTS guest_sessions (
    session_id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

## 3. Requirements Specification

### Functional Requirements

#### FR-1: Chat Messaging
- FR-1.1: User can send text messages
- FR-1.2: User can receive AI responses streamed in real-time
- FR-1.3: User can stop generation mid-stream
- FR-1.4: Messages support markdown rendering
- FR-1.5: Messages persist across page refreshes

#### FR-2: Authentication
- FR-2.1: User can register with email/password
- FR-2.2: User can login with email/password
- FR-2.3: Guest users (anonymous) are auto-created
- FR-2.4: Sessions persist via cookies

#### FR-3: Chat History
- FR-3.1: Sidebar shows list of previous chats
- FR-3.2: Chats are grouped by time (Today, Yesterday, etc.)
- FR-3.3: User can delete individual chats
- FR-3.4: User can delete all chats

#### FR-4: AI Models
- FR-4.1: Support multiple AI providers (Anthropic, OpenAI)
- FR-4.2: User can select which model to use
- FR-4.3: Auto-generate chat titles

#### FR-5: Artifacts (Documents)
- FR-5.1: AI can create text documents
- FR-5.2: AI can create code snippets (Python)
- FR-5.3: AI can create spreadsheets (CSV)
- FR-5.4: Documents support versioning
- FR-5.5: User can view/edit documents

#### FR-6: Rate Limiting
- FR-6.1: Guest users: 20 messages/day
- FR-6.2: Registered users: 50 messages/day

### Non-Functional Requirements

#### NFR-1: Performance
- NFR-1.1: First contentful paint < 500ms
- NFR-1.2: SSE connection latency < 100ms
- NFR-1.3: AI streaming starts within 1s of request

#### NFR-2: Simplicity (key comparison metric)
- NFR-2.1: No client-side JavaScript framework
- NFR-2.2: Single SSE connection for all updates
- NFR-2.3: < 50KB total JavaScript (excluding Datastar)
- NFR-2.4: Server-rendered HTML with progressive enhancement

---

## 4. Test Plan (Pest PHP)

### Unit Tests

```php
// tests/Unit/Domain/MessageTest.php
it('creates a user message', function () {
    $message = new Message(
        id: Uuid::uuid4(),
        chatId: Uuid::uuid4(),
        role: 'user',
        content: 'Hello',
    );
    
    expect($message->role)->toBe('user');
    expect($message->content)->toBe('Hello');
});

it('creates an assistant message with parts', function () {
    $message = Message::fromAI(
        chatId: $chatId,
        parts: [
            ['type' => 'text', 'text' => 'Hello!'],
            ['type' => 'tool-invocation', 'toolName' => 'createDocument'],
        ]
    );
    
    expect($message->role)->toBe('assistant');
    expect($message->getParts())->toHaveCount(2);
});
```

### Feature Tests

```php
// tests/Feature/SendMessageTest.php
it('sends a message and receives AI response', function () {
    $chat = createTestChat();
    $handler = new SendMessageHandler($repo, $aiService, $eventBus);
    
    $command = new SendMessageCommand(
        chatId: $chat->id,
        content: 'Hello',
    );
    
    $message = ($handler)($command);
    
    expect($message)->not->toBeNull();
    expect($message->role)->toBe('user');
    
    // Verify event was emitted
    expect($eventBus->getEmittedEvents())->toHaveCount(1);
});

// tests/Feature/AuthenticationTest.php
it('registers a new user', function () {
    $handler = new RegisterUserHandler($userRepo);
    
    $command = new RegisterUserCommand(
        email: 'test@example.com',
        password: 'password123',
    );
    
    $user = ($handler)($command);
    
    expect($user->email)->toBe('test@example.com');
    expect(password_verify('password123', $user->password))->toBeTrue();
});

it('prevents duplicate registration', function () {
    $handler = new RegisterUserHandler($userRepo);
    
    $command = new RegisterUserCommand(
        email: 'test@example.com',
        password: 'password123',
    );
    
    ($handler)($command); // First registration
    
    expect(fn() => ($handler)($command))
        ->toThrow(DuplicateEmailException::class);
});
```

### Integration Tests (with mock AI)

```php
// tests/Feature/ChatStreamingTest.php
it('streams AI response chunks', function () {
    $mockAI = Mockery::mock(AIServiceInterface::class);
    $mockAI->shouldReceive('streamChat')
        ->andReturnUsing(function () {
            yield 'Hello';
            yield ' world';
            yield '!';
        });
    
    $handler = new ChatStreamHandler($mockAI, $eventBus);
    $chunks = iterator_to_array($handler->stream($chatId, $messages));
    
    expect($chunks)->toBe(['Hello', ' world', '!']);
});
```

---

## 9. Implementation Phases (Revised)

### Phase 1: Foundation (3-4 days)
- [x] Migration plan (this document)
- [ ] Project setup (composer.json, config)
- [ ] Database schema (combined auth + app tables)
- [ ] Domain models (Chat, Message, Document, Vote)
- [ ] Repository implementations (SQLite)
- [ ] Basic Swoole/Mezzio setup (copy from timeline)
- [ ] SSE infrastructure (EventBus, SseListener)
- [ ] Pest test setup

### Phase 2: Authentication (2-3 days)
- [ ] PHP-Auth integration
- [ ] Session handling with Swoole
- [ ] Guest user auto-creation
- [ ] Login/Register modals (Open Props)
- [ ] Auth middleware
- [ ] Auth tests

### Phase 3: Core Chat (4-5 days)
- [ ] Chat CRUD operations
- [ ] Message sending (without AI first)
- [ ] Basic UI with Datastar + Open Props
- [ ] Chat history sidebar
- [ ] Model selector
- [ ] Chat tests

### Phase 4: AI Integration (4-5 days)
- [ ] LLPhant setup with Anthropic
- [ ] Streaming response handler
- [ ] Message streaming via SSE
- [ ] Stop generation feature
- [ ] Auto-title generation
- [ ] AI streaming tests

### Phase 5: Artifacts (5-6 days)
- [ ] Document model and repository
- [ ] AI tool calling (createDocument, updateDocument)
- [ ] Document versioning
- [ ] Text artifact UI (Markdown viewer/editor)
- [ ] Code artifact UI + Pyodide execution
- [ ] Sheet artifact UI (CSV table)
- [ ] Image artifact UI
- [ ] Artifact tests

### Phase 6: Polish & Features (3-4 days)
- [ ] Rate limiting (guest: 20/day, registered: 50/day)
- [ ] Message voting
- [ ] Suggestions system
- [ ] Error handling & toasts
- [ ] Keyboard shortcuts
- [ ] Mobile responsiveness

### Phase 7: Testing & Comparison (2-3 days)
- [ ] Complete test coverage
- [ ] Performance benchmarks
- [ ] Complexity comparison metrics
- [ ] Documentation
- [ ] Caddyfile & systemd service

**Total Estimated Time: ~4 weeks**

---

## 10. Test Plan (Pest PHP)

### Unit Tests

```php
// tests/Unit/Domain/MessageTest.php
<?php

use App\Domain\Model\Message;
use Ramsey\Uuid\Uuid;

it('creates a user message', function () {
    $message = new Message(
        id: Uuid::uuid4()->toString(),
        chatId: Uuid::uuid4()->toString(),
        role: 'user',
        content: 'Hello',
    );
    
    expect($message->role)->toBe('user')
        ->and($message->content)->toBe('Hello');
});

it('creates an assistant message with parts', function () {
    $message = Message::assistant(
        chatId: Uuid::uuid4()->toString(),
        parts: [
            ['type' => 'text', 'text' => 'Hello!'],
            ['type' => 'tool-invocation', 'toolName' => 'createDocument', 'args' => ['title' => 'Test']],
        ]
    );
    
    expect($message->role)->toBe('assistant')
        ->and($message->getParts())->toHaveCount(2);
});

it('serializes message to JSON', function () {
    $message = new Message(
        id: 'test-id',
        chatId: 'chat-id',
        role: 'user',
        content: 'Test message',
    );
    
    $json = $message->toArray();
    
    expect($json)->toHaveKey('id', 'test-id')
        ->toHaveKey('role', 'user')
        ->toHaveKey('content', 'Test message');
});
```

### Feature Tests

```php
// tests/Feature/SendMessageTest.php
<?php

use App\Application\Command\SendMessage\SendMessageCommand;
use App\Application\Command\SendMessage\SendMessageHandler;
use App\Infrastructure\EventBus\SwooleEventBus;
use App\Infrastructure\Persistence\SqliteChatRepository;
use App\Infrastructure\Persistence\SqliteMessageRepository;

beforeEach(function () {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));
    $this->eventBus = new SwooleEventBus();
    $this->chatRepo = new SqliteChatRepository($this->pdo);
    $this->messageRepo = new SqliteMessageRepository($this->pdo);
});

it('sends a message and creates it in database', function () {
    // Create a chat first
    $chat = $this->chatRepo->create(userId: 1, model: 'claude-3-5-sonnet');
    
    $handler = new SendMessageHandler(
        $this->chatRepo,
        $this->messageRepo,
        $this->eventBus
    );
    
    $command = new SendMessageCommand(
        chatId: $chat->id,
        content: 'Hello, AI!',
    );
    
    $message = ($handler)($command);
    
    expect($message)->not->toBeNull()
        ->and($message->role)->toBe('user')
        ->and($message->content)->toBe('Hello, AI!');
});

it('emits event when message is created', function () {
    $chat = $this->chatRepo->create(userId: 1, model: 'claude-3-5-sonnet');
    $eventFired = false;
    
    $this->eventBus->subscribe(function ($event) use (&$eventFired) {
        $eventFired = true;
    });
    
    $handler = new SendMessageHandler(
        $this->chatRepo,
        $this->messageRepo,
        $this->eventBus
    );
    
    ($handler)(new SendMessageCommand($chat->id, 'Test'));
    
    expect($eventFired)->toBeTrue();
});
```

```php
// tests/Feature/AuthenticationTest.php
<?php

use Delight\Auth\Auth;

beforeEach(function () {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));
});

it('registers a new user', function () {
    $auth = new Auth($this->pdo);
    
    $userId = $auth->register('test@example.com', 'password123', null);
    
    expect($userId)->toBeInt()
        ->and($userId)->toBeGreaterThan(0);
});

it('prevents duplicate email registration', function () {
    $auth = new Auth($this->pdo);
    
    $auth->register('test@example.com', 'password123', null);
    
    expect(fn() => $auth->register('test@example.com', 'different', null))
        ->toThrow(\Delight\Auth\UserAlreadyExistsException::class);
});

it('logs in with valid credentials', function () {
    $auth = new Auth($this->pdo);
    $auth->register('test@example.com', 'password123', null);
    
    // Login (in test environment, just verify no exception)
    expect(fn() => $auth->login('test@example.com', 'password123'))
        ->not->toThrow(Exception::class);
});
```

```php
// tests/Feature/RateLimitTest.php
<?php

use App\Infrastructure\Persistence\SqliteRateLimitRepository;

beforeEach(function () {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->exec(file_get_contents(__DIR__ . '/../../data/schema.sql'));
    $this->repo = new SqliteRateLimitRepository($this->pdo);
});

it('tracks message count per user per day', function () {
    $userId = 1;
    
    $this->repo->incrementMessageCount($userId);
    $this->repo->incrementMessageCount($userId);
    
    expect($this->repo->getMessageCountToday($userId))->toBe(2);
});

it('returns true when under limit', function () {
    $userId = 1;
    $limit = 20;
    
    for ($i = 0; $i < 10; $i++) {
        $this->repo->incrementMessageCount($userId);
    }
    
    expect($this->repo->isUnderLimit($userId, $limit))->toBeTrue();
});

it('returns false when at limit', function () {
    $userId = 1;
    $limit = 5;
    
    for ($i = 0; $i < 5; $i++) {
        $this->repo->incrementMessageCount($userId);
    }
    
    expect($this->repo->isUnderLimit($userId, $limit))->toBeFalse();
});
```

---

## 11. Caddyfile

```caddyfile
# chatbot.caddyfile
chatbot.local {
    # Reverse proxy to Swoole
    reverse_proxy localhost:9502 {
        # SSE settings
        flush_interval -1
        transport http {
            read_timeout 0
            write_timeout 0
        }
    }
    
    # Static files (optional - Swoole can serve these too)
    @static {
        path /js/* /css/* /images/*
    }
    handle @static {
        root * /var/www/ai-chatbot/public
        file_server
    }
    
    # Logs
    log {
        output file /var/log/caddy/chatbot.log
    }
}
```

---

## 12. Next Steps

1. **Review this plan** - any adjustments needed?
2. **Start Phase 1** - I'll create the project structure and initial files
3. **Iterative development** - build feature by feature with tests

Ready to proceed with implementation?

---

## 6. Complexity Comparison Metrics

After implementation, we'll compare:

| Metric | Next.js/Vercel | PHP/Swoole/Datastar |
|--------|----------------|---------------------|
| Lines of Code (server) | TBD | TBD |
| Lines of Code (client JS) | TBD | TBD |
| Number of dependencies | 78+ npm packages | ~18 Composer packages |
| Build step required | Yes (Next.js) | No |
| Bundle size (JS) | TBD | ~50KB (Datastar + Pyodide loader) |
| Memory usage | TBD | TBD |
| Cold start time | TBD | N/A (long-running) |
| P95 response latency | TBD | TBD |
| SSE connection handling | Multiple streams | Single connection |

### composer.json

```json
{
    "name": "ai-chatbot/app",
    "description": "AI Chatbot - PHP/Mezzio/Swoole/Datastar",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "ext-pdo": "*",
        "ext-pdo_sqlite": "*",
        "ext-swoole": "*",
        "delight-im/auth": "^9.0",
        "laminas/laminas-config-aggregator": "^1.15",
        "laminas/laminas-diactoros": "^3.3",
        "laminas/laminas-servicemanager": "^4.0",
        "mezzio/mezzio": "^3.19",
        "mezzio/mezzio-fastroute": "^3.11",
        "mezzio/mezzio-helpers": "^5.16",
        "mezzio/mezzio-swoole": "^4.9",
        "ramsey/uuid": "^4.7",
        "starfederation/datastar-php": "^1.0.0-RC.5",
        "theodo-group/llphant": "^0.11"
    },
    "require-dev": {
        "ext-inotify": "*",
        "friendsofphp/php-cs-fixer": "^3.67",
        "mockery/mockery": "^1.6",
        "pestphp/pest": "^4.0",
        "phpstan/extension-installer": "^v1.4",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "rector/type-perfect": "^v2.0",
        "roave/security-advisories": "dev-latest",
        "tomasvotruba/type-coverage": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/App/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "pest",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "cs:fix": "php-cs-fixer fix",
        "stan": "phpstan analyse",
        "serve": "./vendor/bin/laminas mezzio:swoole:start",
        "stop": "./vendor/bin/laminas mezzio:swoole:stop",
        "db:init": "php bin/init-db.php",
        "db:seed": "php bin/seed.php"
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "phpstan/extension-installer": true
        },
        "sort-packages": true,
        "optimize-autoloader": true
    },
    "minimum-stability": "RC",
    "prefer-stable": true
}
```

---

## 7. Routes Configuration

```php
// config/routes.php
return static function (Application $app, MiddlewareFactory $factory, ContainerInterface $container): void {
    // Pages
    $app->get('/', HomeHandler::class, 'home');
    $app->get('/chat/{id:[a-f0-9-]+}', ChatHandler::class, 'chat.view');

    // SSE Updates (long-running connection)
    $app->get('/updates', UpdatesHandler::class, 'updates');

    // Authentication
    $app->post('/auth/login', AuthCommandHandler::class . ':login', 'auth.login');
    $app->post('/auth/register', AuthCommandHandler::class . ':register', 'auth.register');
    $app->post('/auth/logout', AuthCommandHandler::class . ':logout', 'auth.logout');

    // Queries
    $app->get('/query/chat/{id:[a-f0-9-]+}', ChatQueryHandler::class, 'query.chat');
    $app->get('/query/history', HistoryQueryHandler::class, 'query.history');
    $app->get('/query/document/{id:[a-f0-9-]+}', DocumentQueryHandler::class, 'query.document');
    $app->get('/query/models', ModelsQueryHandler::class, 'query.models');

    // Commands - Chat
    $app->post('/cmd/chat', ChatCommandHandler::class . ':create', 'cmd.chat.create');
    $app->post('/cmd/chat/{id:[a-f0-9-]+}/send', MessageCommandHandler::class . ':send', 'cmd.chat.send');
    $app->delete('/cmd/chat/{id:[a-f0-9-]+}', ChatCommandHandler::class . ':delete', 'cmd.chat.delete');
    $app->delete('/cmd/chat', ChatCommandHandler::class . ':deleteAll', 'cmd.chat.deleteAll');

    // Commands - Documents
    $app->post('/cmd/document', DocumentCommandHandler::class . ':create', 'cmd.document.create');
    $app->put('/cmd/document/{id:[a-f0-9-]+}', DocumentCommandHandler::class . ':update', 'cmd.document.update');
    $app->delete('/cmd/document/{id:[a-f0-9-]+}', DocumentCommandHandler::class . ':delete', 'cmd.document.delete');

    // Commands - Votes
    $app->patch('/cmd/vote/{chatId:[a-f0-9-]+}/{messageId:[a-f0-9-]+}', VoteCommandHandler::class . ':vote', 'cmd.vote');
};
```

---

## 8. UI Layout with Open Props

```html
<!-- templates/layout.php -->
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Chatbot</title>
    
    <!-- Open Props CSS -->
    <link rel="stylesheet" href="https://unpkg.com/open-props">
    <link rel="stylesheet" href="https://unpkg.com/open-props/normalize.min.css">
    <link rel="stylesheet" href="https://unpkg.com/open-props/buttons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Datastar -->
    <script type="module" src="/js/datastar.js"></script>
    
    <!-- Pyodide (lazy loaded) -->
    <script src="https://cdn.jsdelivr.net/pyodide/v0.26.4/full/pyodide.js" defer></script>
    
    <!-- App JS -->
    <script type="module" src="/js/app.js"></script>
    
    <!-- Custom styles -->
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <div id="app" 
         class="app-container" 
         style="height: 100vh; display: flex;"
         data-signals='{
            "_sidebarOpen": true,
            "_currentChatId": null,
            "_model": "claude-3-5-sonnet",
            "_artifactOpen": false,
            "_artifactId": null,
            "_message": "",
            "_isGenerating": false,
            "_showLoginModal": false,
            "_showRegisterModal": false
         }'
         data-indicator="_connected"
         data-init="@get('/updates')">
        
        <!-- Sidebar -->
        <aside class="sidebar" style="width: 280px; background: var(--surface-1); padding: var(--size-4);" data-show="$_sidebarOpen">
            <?php include 'partials/sidebar.php'; ?>
        </aside>
        
        <!-- Main Content -->
        <main style="flex-grow: 1; display: flex; flex-direction: column;">
            <!-- Header -->
            <nav class="header" style="background: var(--surface-2); padding: var(--size-3); display: flex; align-items: center; gap: var(--size-3);">
                <button class="btn" data-on:click="$_sidebarOpen = !$_sidebarOpen">
                    <i class="fas fa-bars"></i>
                </button>
                <span style="color: var(--text-1);">AI Chatbot</span>
                <div style="margin-left: auto; display: flex; gap: var(--size-2);">
                    <?php include 'partials/model-selector.php'; ?>
                    <!-- Auth buttons -->
                </div>
            </nav>
            
            <!-- Chat Area -->
            <div style="display: flex; flex-grow: 1; overflow: hidden;">
                <!-- Messages -->
                <div style="flex-grow: 1; padding: var(--size-4); overflow-y: auto;">
                    <div id="messages">
                        <?php include 'partials/chat.php'; ?>
                    </div>
                </div>
                
                <!-- Artifact Panel (slide-in) -->
                <div class="artifact-panel" data-show="$_artifactOpen" style="width: 50%; background: var(--surface-2);">
                    <?php include 'partials/artifact.php'; ?>
                </div>
            </div>
            
            <!-- Input Area -->
            <div style="padding: var(--size-4); background: var(--surface-2);">
                <?php include 'partials/input.php'; ?>
            </div>
        </main>
    </div>
    
    <!-- Modals -->
    <?php include 'partials/modals/login.php'; ?>
    <?php include 'partials/modals/register.php'; ?>
</body>
</html>
```

---

## 9. Implementation Phases (Revised)
