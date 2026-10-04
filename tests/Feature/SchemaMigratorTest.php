<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Persistence\SchemaMigrator;

/**
 * @return array<string, mixed>
 */
function describeSchema(\PDO $pdo): array {
    $schema = [];
    $objects = $pdo->query("SELECT type, name, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($objects as $object) {
        $schema[$object['type'] . ':' . $object['name']] = $object['type'] === 'table'
            ? $pdo->query('PRAGMA table_xinfo("' . $object['name'] . '")')->fetchAll(\PDO::FETCH_ASSOC)
            : $pdo->query('PRAGMA index_xinfo("' . $object['name'] . '")')->fetchAll(\PDO::FETCH_ASSOC);
    }

    foreach (array_keys($schema) as $key) {
        if (str_starts_with($key, 'table:')) {
            $schema['fk:' . substr($key, 6)] = $pdo->query('PRAGMA foreign_key_list("' . substr($key, 6) . '")')->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    return $schema;
}

function legacyDatabase(): \PDO {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents(__DIR__ . '/../Fixtures/schema-v0.sql'));

    $pdo->exec("INSERT INTO users (id, email, is_guest) VALUES (1, 'a@example.com', 0)");
    $pdo->exec("INSERT INTO chats (id, user_id, title, created_at, updated_at) VALUES ('c1', 1, 'Old chat', 1, 1)");
    $pdo->exec("INSERT INTO messages (id, chat_id, role, content, created_at) VALUES ('m1', 'c1', 'user', 'Hello', 1)");
    $pdo->exec("INSERT INTO rate_limits (user_id, date, message_count) VALUES (1, '2026-01-01', 7)");

    return $pdo;
}

it('upgrades a database created from the previous schema', function (): void {
    $pdo = legacyDatabase();
    $migrator = new SchemaMigrator($pdo);

    expect($migrator->currentVersion())->toBe(0);
    expect($migrator->migrate())->toBe([1, 2]);
    expect($migrator->currentVersion())->toBe(SchemaMigrator::latestVersion());

    expect($pdo->query('SELECT message_count, token_count FROM rate_limits WHERE user_id = 1')->fetch(\PDO::FETCH_ASSOC))
        ->toBe(['message_count' => 7, 'token_count' => 0])
    ;
    expect($pdo->query("SELECT content FROM messages WHERE id = 'm1'")->fetchColumn())->toBe('Hello');
    expect($pdo->query("SELECT title FROM chats WHERE id = 'c1'")->fetchColumn())->toBe('Old chat');

    $pdo->exec("INSERT INTO message_usage (message_id, user_id, model, stop_reason, created_at) VALUES ('m1', 1, 'claude-haiku-4-5', 'end_turn', 1)");
    $pdo->exec('INSERT INTO rate_limit_requests (user_id, created_at) VALUES (1, 1)');
    expect((int) $pdo->query('SELECT COUNT(*) FROM message_usage')->fetchColumn())->toBe(1);
});

it('is a no-op when run twice', function (): void {
    $pdo = legacyDatabase();
    $migrator = new SchemaMigrator($pdo);
    $migrator->migrate();
    $before = describeSchema($pdo);

    expect($migrator->migrate())->toBe([]);
    expect(describeSchema($pdo))->toBe($before);
});

it('is a no-op on a fresh database', function (): void {
    $pdo = createTestPdo();
    $migrator = new SchemaMigrator($pdo);

    expect($migrator->currentVersion())->toBe(SchemaMigrator::latestVersion());
    expect($migrator->migrate())->toBe([]);
});

it('produces the same schema as a fresh install', function (): void {
    $migrated = legacyDatabase();
    (new SchemaMigrator($migrated))->migrate();

    expect(describeSchema($migrated))->toBe(describeSchema(createTestPdo()));
});

it('rolls back a failing step', function (): void {
    $pdo = legacyDatabase();
    $pdo->exec('CREATE TABLE message_usage (id INTEGER)');

    expect(fn () => (new SchemaMigrator($pdo))->migrate())->toThrow(\RuntimeException::class);
    expect((new SchemaMigrator($pdo))->currentVersion())->toBe(0);
    expect(array_column($pdo->query('PRAGMA table_info(rate_limits)')->fetchAll(\PDO::FETCH_ASSOC), 'name'))->not->toContain('token_count');
});
