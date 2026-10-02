<?php

declare(strict_types=1);

namespace App\Web;

use Mbolli\PhpVia\Scope;

/**
 * Broadcast scopes. Only components join them, so a broadcast re-renders just the affected part of a page.
 */
final class Scopes {
    /** Sidebar and toasts of every tab of one user */
    public static function user(int $userId): string {
        return Scope::build('user', (string) $userId);
    }

    /** Message list, votes and documents of everyone viewing a chat */
    public static function chat(string $chatId): string {
        return Scope::build('chat', $chatId);
    }

    /** The reply being streamed in a chat, re-rendered per token */
    public static function stream(string $chatId): string {
        return Scope::build('chatstream', $chatId);
    }
}
