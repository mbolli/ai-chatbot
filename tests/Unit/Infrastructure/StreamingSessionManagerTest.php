<?php

declare(strict_types=1);

use App\Infrastructure\AI\StreamingSessionManager;

beforeEach(function (): void {
    $this->manager = new StreamingSessionManager();
});

describe('StreamingSessionManager', function (): void {
    it('starts and tracks a session', function (): void {
        $chatId = 'chat-123';
        $userId = 1;
        $messageId = 'msg-456';

        $sessionId = $this->manager->startSession($chatId, $userId, $messageId);

        expect($sessionId)->toBeString();
        expect($this->manager->hasActiveSession($chatId, $userId))->toBeTrue();
    });

    it('retrieves session information', function (): void {
        $chatId = 'chat-123';
        $userId = 1;
        $messageId = 'msg-456';

        $this->manager->startSession($chatId, $userId, $messageId);
        $session = $this->manager->getSession($chatId, $userId);

        expect($session)->not->toBeNull();
        expect($session['chat_id'])->toBe($chatId);
        expect($session['user_id'])->toBe($userId);
        expect($session['message_id'])->toBe($messageId);
        expect($session['stop_requested'])->toBe(0);
    });

    it('handles stop request', function (): void {
        $chatId = 'chat-123';
        $userId = 1;
        $messageId = 'msg-456';

        $this->manager->startSession($chatId, $userId, $messageId);

        expect($this->manager->isStopRequested($chatId, $userId))->toBeFalse();

        $result = $this->manager->requestStop($chatId, $userId);

        expect($result)->toBeTrue();
        expect($this->manager->isStopRequested($chatId, $userId))->toBeTrue();
    });

    it('returns false when stopping non-existent session', function (): void {
        $result = $this->manager->requestStop('nonexistent', 999);

        expect($result)->toBeFalse();
    });

    it('ends a session', function (): void {
        $chatId = 'chat-123';
        $userId = 1;
        $messageId = 'msg-456';

        $this->manager->startSession($chatId, $userId, $messageId);
        expect($this->manager->hasActiveSession($chatId, $userId))->toBeTrue();

        $this->manager->endSession($chatId, $userId);
        expect($this->manager->hasActiveSession($chatId, $userId))->toBeFalse();
    });

    it('handles multiple users with same chat', function (): void {
        $chatId = 'chat-123';
        $userId1 = 1;
        $userId2 = 2;

        $this->manager->startSession($chatId, $userId1, 'msg-1');
        $this->manager->startSession($chatId, $userId2, 'msg-2');

        expect($this->manager->hasActiveSession($chatId, $userId1))->toBeTrue();
        expect($this->manager->hasActiveSession($chatId, $userId2))->toBeTrue();

        // Stop one user's session shouldn't affect the other
        $this->manager->requestStop($chatId, $userId1);
        expect($this->manager->isStopRequested($chatId, $userId1))->toBeTrue();
        expect($this->manager->isStopRequested($chatId, $userId2))->toBeFalse();
    });

    it('cleans up stale sessions', function (): void {
        // We can't easily test time-based cleanup without mocking time,
        // but we can verify the method runs without error
        $cleaned = $this->manager->cleanupStaleSessions();

        expect($cleaned)->toBeInt();
    });
});
