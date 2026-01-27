# AI Chatbot - PHP/Swoole/Mezzio/Datastar

A real-time AI chatbot built with PHP, Swoole, Mezzio, and Datastar. This is a migration/comparison project from the Next.js Vercel AI Chatbot.

## Requirements

- PHP 8.2+
- Swoole extension
- SQLite3

## Installation

```bash
# Install dependencies
composer install

# Initialize database
php bin/server.php
# The database is auto-created on first run

# Or manually create it:
sqlite3 data/db.sqlite < data/schema.sql
```

## Configuration

Copy the local config template:

```bash
cp config/autoload/app.local.php.dist config/autoload/app.local.php
```

Edit `config/autoload/app.local.php` and add your API keys:

```php
return [
    'debug' => true,
    'ai' => [
        'api_key' => 'your-anthropic-api-key',
    ],
];
```

## Running

```bash
# Start the Swoole server
composer dev
# or
php bin/server.php

# Server runs at http://localhost:8080
```

## Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage
```

## Project Structure

```
├── bin/
│   └── server.php          # Swoole entry point
├── config/
│   ├── config.php          # Config aggregator
│   ├── container.php       # DI container
│   ├── routes.php          # Route definitions
│   ├── pipeline.php        # Middleware pipeline
│   └── autoload/           # Environment configs
├── data/
│   ├── schema.sql          # Database schema
│   └── db.sqlite           # SQLite database
├── public/
│   ├── css/app.css         # Styles (Open Props)
│   └── js/app.js           # Client JS
├── src/App/
│   ├── ConfigProvider.php  # DI configuration
│   ├── Domain/             # Domain models, events, repository interfaces
│   └── Infrastructure/     # Implementations (HTTP handlers, persistence, etc.)
├── templates/              # PHP templates
└── tests/                  # Pest tests
```

## Architecture

This project uses CQRS (Command Query Responsibility Segregation) pattern:

- **Commands**: POST/PUT/DELETE operations that modify state
- **Queries**: GET operations that read state
- **Events**: Emitted when state changes, broadcast via SSE

Real-time updates use Server-Sent Events (SSE) with Datastar for DOM patching.

## Development Progress

- [x] Phase 1: Foundation (database, models, repositories, Swoole setup, tests)
- [ ] Phase 2: Authentication (PHP-Auth integration)
- [ ] Phase 3: Core Chat (CRUD, UI)
- [ ] Phase 4: AI Integration (LLPhant, streaming)
- [ ] Phase 5: Artifacts (documents, code execution)
- [ ] Phase 6: Polish (rate limiting, voting, suggestions)

## License

MIT
