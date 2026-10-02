<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Event\MessageThinkingEvent;
use App\Domain\Event\RateLimitExceededEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Message;
use App\Domain\Model\MessageUsage;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Domain\Service\AssistantResponse;
use App\Domain\Service\ConversationHistoryBuilder;
use App\Domain\Service\RateLimitService;
use App\Domain\Service\Stream\StopReason;
use App\Domain\Service\Stream\StreamEnd;
use App\Domain\Service\Stream\ThinkingDelta;
use App\Domain\Service\Stream\ToolCall;
use App\Domain\Service\Stream\ToolResult;
use App\Domain\Service\Stream\Usage;
use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\EventBus\EventBusInterface;
use OpenSwoole\Coroutine;

/**
 * Sending, generating and stopping assistant replies. Methods return an HTTP-like status code.
 */
final class MessageCommands {
    /**
     * Special test commands that work without AI service.
     * These are only available on localhost for development/testing.
     */
    private const array TEST_COMMANDS = [
        '{longStream}' => 'testLongStream',
        '{error}' => 'testError',
        '{artifact:text}' => 'testArtifactText',
        '{artifact:code}' => 'testArtifactCode',
        '{artifact:sheet}' => 'testArtifactSheet',
        '{slow}' => 'testSlowStream',
        '{markdown}' => 'testMarkdown',
        '{help}' => 'testHelp',
    ];

    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly EventBusInterface $eventBus,
        private readonly AIServiceInterface $aiService,
        private readonly StreamingSessionManager $sessionManager,
        private readonly RateLimitService $rateLimitService,
        private readonly int $contextMaxTokens = 8000,
        private readonly bool $testCommandsEnabled = false,
    ) {}

    public function send(int $userId, string $chatId, string $content): int {
        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return 404;
        }

        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        if (empty(mb_trim($content))) {
            return 400;
        }

        // Check if there's already an active streaming session
        if ($this->sessionManager->hasActiveSession($chatId, $userId)) {
            return 409; // Conflict - already streaming
        }

        if ($this->isRateLimited($userId, $chatId)) {
            return 429;
        }

        $this->rateLimitService->recordMessage($userId);

        // Create user message
        $userMessage = Message::user($chatId, $content);
        $this->messageRepository->save($userMessage);

        // Emit event to update UI with user message and clear the input
        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'message_added',
            messageId: $userMessage->id,
            messageRole: $userMessage->role,
            messageContent: $userMessage->content,
            clearMessage: true,
        ));

        // Create placeholder for assistant message
        $assistantMessage = Message::assistant($chatId);
        $this->messageRepository->save($assistantMessage);

        // Emit event for assistant placeholder (streaming will update content)
        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'assistant_started',
            messageId: $assistantMessage->id,
            messageRole: $assistantMessage->role,
            messageContent: '',
        ));

        // Start streaming session
        $this->sessionManager->startSession($chatId, $userId, $assistantMessage->id);

        $isLocalhost = $this->testCommandsEnabled;

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $userMessage, $assistantMessage, $isLocalhost): void {
            $this->streamAiResponse($userId, $chatId, $chat, $userMessage, $assistantMessage, $isLocalhost);
        });

        return 204;
    }

    /**
     * Stop an active AI generation stream.
     */
    public function stop(int $userId, string $chatId): int {
        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return 404;
        }

        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        $stopped = $this->sessionManager->requestStop($chatId, $userId);

        if ($stopped) {
            // Emit event to reset _isGenerating via SSE
            $this->eventBus->emit($userId, new ChatUpdatedEvent(
                chatId: $chatId,
                userId: $userId,
                action: 'generation_stopped',
            ));
        }

        return $stopped ? 204 : 404;
    }

    /**
     * Generate AI response for the last user message in a chat.
     * Used when page loads with a pending user message (e.g., after new chat creation).
     */
    public function generate(int $userId, string $chatId): int {
        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return 404;
        }

        if (!$chat->isOwnedBy($userId)) {
            return 403;
        }

        // Check if there's already an active streaming session
        if ($this->sessionManager->hasActiveSession($chatId, $userId)) {
            return 409; // Conflict - already streaming
        }

        // Get the last user message
        $messages = $this->messageRepository->findByChat($chatId);
        $lastUserMessage = null;

        foreach (array_reverse($messages) as $message) {
            if ($message->role === 'user') {
                $lastUserMessage = $message;

                break;
            }
        }

        if ($lastUserMessage === null) {
            return 400; // No user message to respond to
        }

        // Check if there's already an assistant response after this user message
        $foundUserMessage = false;
        foreach ($messages as $message) {
            if ($message->id === $lastUserMessage->id) {
                $foundUserMessage = true;

                continue;
            }
            if ($foundUserMessage && $message->role === 'assistant' && !empty($message->content)) {
                return 204; // Already has a response
            }
        }

        // The first message of a new chat is answered here, not in send()
        if ($this->isRateLimited($userId, $chatId)) {
            return 429;
        }

        $this->rateLimitService->recordMessage($userId);

        // Create placeholder for assistant message
        $assistantMessage = Message::assistant($chatId);
        $this->messageRepository->save($assistantMessage);

        // Emit event for assistant placeholder (streaming will update content)
        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'assistant_started',
            messageId: $assistantMessage->id,
            messageRole: $assistantMessage->role,
            messageContent: '',
        ));

        // Start streaming session
        $this->sessionManager->startSession($chatId, $userId, $assistantMessage->id);

        $isLocalhost = $this->testCommandsEnabled;

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $lastUserMessage, $assistantMessage, $isLocalhost): void {
            $this->streamAiResponse($userId, $chatId, $chat, $lastUserMessage, $assistantMessage, $isLocalhost);
        });

        return 204;
    }

    /**
     * Emit RateLimitExceededEvent when the user has reached a limit.
     */
    /**
     * True, after notifying the user, when any of their limits is used up.
     */
    public function isRateLimited(int $userId, string $chatId = ''): bool {
        $exceeded = $this->rateLimitService->exceededLimit($userId);

        if ($exceeded === null) {
            return false;
        }

        $this->eventBus->emit($userId, new RateLimitExceededEvent(
            userId: $userId,
            chatId: $chatId,
            used: $exceeded->used,
            limit: $exceeded->limit,
            isGuest: $exceeded->isGuest,
            type: $exceeded->type,
        ));

        return true;
    }

    /**
     * Check if message is a test command and return the method name if so.
     */
    private function getTestCommand(string $message, bool $isLocalhost): ?string {
        if (!$isLocalhost) {
            return null;
        }

        $trimmed = mb_trim($message);

        return self::TEST_COMMANDS[$trimmed] ?? null;
    }

    /**
     * Stream AI response to the user via SSE.
     */
    private function streamAiResponse(int $userId, string $chatId, Chat $chat, Message $userMessage, Message $assistantMessage, bool $isLocalhost = false): void {
        $response = new AssistantResponse();
        $history = [];
        $model = $chat->model;
        $fullThinking = '';
        $stopReason = null;
        $usage = null;
        $wasStopped = false;
        $failed = false;
        $calledAi = false;
        $generateTitle = false;
        $startedAt = hrtime(true);
        $firstTokenAt = null;

        try {
            // Check for test commands first
            $testCommand = $this->getTestCommand($userMessage->content ?? '', $isLocalhost);
            if ($testCommand !== null) {
                $this->executeTestCommand($testCommand, $userId, $chatId, $assistantMessage);

                return;
            }

            $messages = $this->messageRepository->findByChat($chatId);
            $history = (new ConversationHistoryBuilder($this->contextMaxTokens))->build($messages);
            $model = $this->servedModel($chat->model);
            $calledAi = true;

            foreach ($this->aiService->streamChat($history, $chat->model, $chatId, $assistantMessage->id) as $event) {
                if ($this->sessionManager->isStopRequested($chatId, $userId)) {
                    $wasStopped = true;

                    break;
                }

                if ($event instanceof StreamEnd) {
                    $stopReason = $event->stopReason;
                    $usage = $event->usage;

                    continue;
                }

                if ($event instanceof ToolCall) {
                    $response->addToolCall($event);

                    continue;
                }

                if ($event instanceof ToolResult) {
                    $response->addToolResult($event);
                    $this->emitDocumentUpdate($userId, $chatId, $event, $response);

                    continue;
                }

                $firstTokenAt ??= hrtime(true);

                if ($event instanceof ThinkingDelta) {
                    $fullThinking .= $event->text;
                    $this->eventBus->emit($userId, new MessageThinkingEvent(
                        chatId: $chatId,
                        messageId: $assistantMessage->id,
                        userId: $userId,
                        fullThinking: $fullThinking,
                    ));

                    continue;
                }

                $response->addText($event->text);

                $this->eventBus->emit($userId, new MessageStreamingEvent(
                    chatId: $chatId,
                    messageId: $assistantMessage->id,
                    userId: $userId,
                    chunk: $event->text,
                    isComplete: false,
                    fullContent: $response->text(),
                ));
            }

            $createdDocument = $this->documentRepository->findByMessageId($assistantMessage->id);

            // A response that only ran tools (created or updated a document) is not empty
            $isEmpty = mb_trim($response->text()) === '' && !$wasStopped && $createdDocument === null && !$response->hasSuccessfulToolResult();

            $notice = null;
            if ($stopReason !== null && !$wasStopped) {
                $notice = AssistantResponse::noticeFor($stopReason);
                if ($notice !== null) {
                    $response->addNotice($stopReason->value, $notice);
                }
            }
            if ($notice === null && $isEmpty) {
                error_log("AI returned empty response for chat {$chatId}, model: {$model}");
                $notice = '⚠️ The AI returned an empty response. This could be due to content filtering or a temporary issue. Please try rephrasing your message or try again.';
                $response->addNotice(AssistantResponse::NOTICE_EMPTY, $notice);
            }

            $this->messageRepository->update($assistantMessage->appendContent($response->content())->withParts($response->parts()));

            if ($createdDocument !== null) {
                $this->eventBus->emit($userId, new DocumentUpdatedEvent(
                    documentId: $createdDocument->id,
                    chatId: $chatId,
                    userId: $userId,
                    action: 'created',
                    version: $createdDocument->currentVersion,
                    kind: $createdDocument->kind,
                    language: $createdDocument->language,
                ));
            }

            // The stop marker is shown but not stored
            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $notice ?? ($wasStopped ? ' ⏹' : ''),
                isComplete: true,
                fullContent: $response->content() . ($wasStopped ? ' ⏹' : ''),
            ));

            // Title only for the first exchange; generated after the session is released (see below)
            $generateTitle = !$wasStopped && !$isEmpty && $chat->title === null && \count($messages) <= 2;
        } catch (\Throwable $e) {
            $failed = true;

            // Log error with more context for debugging
            error_log(\sprintf(
                'AI streaming error in chat %s (model: %s): %s | Trace: %s',
                $chatId,
                $model,
                $e->getMessage(),
                $e->getTraceAsString()
            ));

            // Provide a user-friendly error message based on error type
            $errorMessage = $e->getMessage();
            $errorContent = match (true) {
                str_contains($errorMessage, 'API key not configured') => '⚠️ AI service is not configured. Please set up your API keys in the environment.',
                str_contains($errorMessage, 'rate limit') || str_contains($errorMessage, '429') => '⚠️ Rate limit reached. Please wait a moment and try again.',
                str_contains($errorMessage, 'overloaded') || str_contains($errorMessage, '529') => '⚠️ The AI service is currently overloaded. Please try again in a few moments.',
                str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'timed out') => '⚠️ The request timed out. Please try a shorter message or try again.',
                str_contains($errorMessage, 'invalid_api_key') || str_contains($errorMessage, '401') => '⚠️ Invalid API key. Please check your API configuration.',
                str_contains($errorMessage, 'content') && str_contains($errorMessage, 'filter') => '⚠️ Your message was filtered by content moderation. Please rephrase and try again.',
                default => '⚠️ Sorry, I encountered an error while generating a response. Please try again.',
            };

            // Keep whatever text arrived before the error
            $response->addNotice(AssistantResponse::NOTICE_ERROR, $errorContent);
            $this->messageRepository->update($assistantMessage->appendContent($response->content())->withParts($response->parts()));

            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $errorContent,
                isComplete: true,
                fullContent: $response->content(),
            ));
        } finally {
            if ($calledAi) {
                $this->recordResponse(
                    userId: $userId,
                    chatId: $chatId,
                    messageId: $assistantMessage->id,
                    model: $model,
                    response: $response,
                    history: $history,
                    thinking: $fullThinking,
                    stopReason: $stopReason,
                    usage: $usage,
                    wasStopped: $wasStopped,
                    failed: $failed,
                    startedAt: $startedAt,
                    firstTokenAt: $firstTokenAt,
                );
            }

            // Always clean up the session
            $this->sessionManager->endSession($chatId, $userId);
        }

        // After endSession: a follow-up message sent while the title is generated must not get a 409
        if ($generateTitle) {
            $this->generateChatTitle($userId, $chat, $userMessage->content ?? '');
        }
    }

    /**
     * Refresh the artifact panel after the model updated a document of this chat.
     */
    private function emitDocumentUpdate(int $userId, string $chatId, ToolResult $result, AssistantResponse $response): void {
        if ($result->isError || $result->name !== 'updateDocument') {
            return;
        }

        $documentId = $response->toolInput($result->id)['documentId'] ?? null;
        $document = \is_string($documentId) ? $this->documentRepository->findWithContent($documentId) : null;

        if ($document === null || $document->chatId !== $chatId) {
            return;
        }

        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $chatId,
            userId: $userId,
            action: 'updated',
            version: $document->currentVersion,
            kind: $document->kind,
            language: $document->language,
        ));
    }

    /**
     * Mirrors the fallback in AIServiceInterface::streamChat(), which serves the default model for unknown or retired ones.
     */
    private function servedModel(string $model): string {
        return ($this->aiService->getAvailableModels()[$model]['available'] ?? false) ? $model : $this->aiService->getDefaultModel();
    }

    /**
     * Persist usage, add it to the daily token tally and write one telemetry line. Never throws.
     *
     * @param list<array{role: string, content: string}> $history
     */
    private function recordResponse(
        int $userId,
        string $chatId,
        string $messageId,
        string $model,
        AssistantResponse $response,
        array $history,
        string $thinking,
        ?StopReason $stopReason,
        ?Usage $usage,
        bool $wasStopped,
        bool $failed,
        int $startedAt,
        ?int $firstTokenAt,
    ): void {
        try {
            $usage ??= new Usage();
            $reported = $usage->inputTokens + $usage->outputTokens + $usage->cacheReadTokens + $usage->cacheWriteTokens;

            // Stopped streams never see StreamEnd; estimate so stopping early cannot dodge the token limit
            $estimated = !$failed && $reported === 0;
            if ($estimated) {
                $usage = new Usage(
                    inputTokens: ConversationHistoryBuilder::estimateTokens(implode("\n", array_column($history, 'content'))),
                    outputTokens: ConversationHistoryBuilder::estimateTokens($thinking . $response->text()),
                );
            }

            $stop = match (true) {
                $wasStopped => MessageUsage::STOP_REASON_USER,
                $failed => 'error',
                default => ($stopReason ?? StopReason::Unknown)->value,
            };

            $record = new MessageUsage(
                messageId: $messageId,
                userId: $userId,
                model: $model,
                stopReason: $stop,
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                cacheReadTokens: $usage->cacheReadTokens,
                cacheWriteTokens: $usage->cacheWriteTokens,
                estimated: $estimated,
                createdAt: new \DateTimeImmutable(),
            );

            if (!$failed) {
                $this->messageRepository->saveUsage($record);
                $this->rateLimitService->recordTokens($userId, $record->totalTokens());
            }

            $now = hrtime(true);
            error_log('ai_response ' . json_encode([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'model' => $model,
                'ttft_ms' => $firstTokenAt === null ? null : intdiv($firstTokenAt - $startedAt, 1_000_000),
                'total_ms' => intdiv($now - $startedAt, 1_000_000),
                'stop_reason' => $stop,
                'tool_calls' => $response->toolCallCount(),
                'input_tokens' => $record->inputTokens,
                'output_tokens' => $record->outputTokens,
                'cache_read_tokens' => $record->cacheReadTokens,
                'cache_write_tokens' => $record->cacheWriteTokens,
                'usage_estimated' => $estimated,
                'stopped_by_user' => $wasStopped,
                'error' => $failed,
            ], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            error_log("Recording AI usage failed for message {$messageId}: " . $e->getMessage());
        }
    }

    /**
     * Generate a title for the chat based on the first message.
     */
    private function generateChatTitle(int $userId, Chat $chat, string $firstMessage): void {
        try {
            $title = $this->aiService->generateTitle($firstMessage);

            if (!empty($title)) {
                $updatedChat = $chat->updateTitle($title);
                $this->chatRepository->update($updatedChat);

                // Emit event to update sidebar with new title
                $this->eventBus->emit($userId, new ChatUpdatedEvent(
                    chatId: $chat->id,
                    userId: $userId,
                    action: 'title_updated',
                    title: $title,
                ));
            }
        } catch (\Throwable $e) {
            // Title generation is non-critical, just log the error
            error_log('Title generation error: ' . $e->getMessage());
        }
    }

    // ==========================================
    // Test Command Implementations
    // ==========================================

    /**
     * Execute a test command.
     */
    private function executeTestCommand(string $command, int $userId, string $chatId, Message $assistantMessage): void {
        try {
            $this->{$command}($userId, $chatId, $assistantMessage);
        } finally {
            $this->sessionManager->endSession($chatId, $userId);
        }
    }

    /**
     * {longStream} - Produces a long stream of text to test streaming performance.
     */
    private function testLongStream(int $userId, string $chatId, Message $assistantMessage): void {
        $paragraphs = [
            'This is a test of the streaming functionality. ',
            'The quick brown fox jumps over the lazy dog. ',
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ',
            'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. ',
            'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris. ',
            'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum. ',
            'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia. ',
            'Testing chunk delivery and UI responsiveness during long streams. ',
        ];

        $fullContent = '';

        // Stream 50 chunks with small delays
        for ($i = 0; $i < 50; ++$i) {
            if ($this->sessionManager->isStopRequested($chatId, $userId)) {
                $fullContent .= ' ⏹';

                break;
            }

            $chunk = $paragraphs[$i % \count($paragraphs)];
            $fullContent .= $chunk;

            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $chunk,
                isComplete: false,
                fullContent: $fullContent,
            ));

            Coroutine::usleep(50000); // 50ms between chunks
        }

        $this->finalizeTestMessage($userId, $chatId, $assistantMessage, $fullContent);
    }

    /**
     * {slow} - Produces a slow stream to test patience and stop functionality.
     */
    private function testSlowStream(int $userId, string $chatId, Message $assistantMessage): void {
        $words = ['This', ' is', ' a', ' very', ' slow', ' response...', ' each', ' word', ' takes', ' time', ' to', ' appear.', ' You', ' can', ' test', ' the', ' stop', ' button', ' now.'];

        $fullContent = '';

        foreach ($words as $word) {
            if ($this->sessionManager->isStopRequested($chatId, $userId)) {
                $fullContent .= ' ⏹';

                break;
            }

            $fullContent .= $word;

            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $word,
                isComplete: false,
                fullContent: $fullContent,
            ));

            Coroutine::usleep(500000); // 500ms between words
        }

        $this->finalizeTestMessage($userId, $chatId, $assistantMessage, $fullContent);
    }

    /**
     * {error} - Simulates an error during streaming.
     */
    private function testError(int $userId, string $chatId, Message $assistantMessage): void {
        // Send a few chunks first
        $chunks = ['Starting response...', ' Processing...', ' '];
        $fullContent = '';

        foreach ($chunks as $chunk) {
            $fullContent .= $chunk;
            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $chunk,
                isComplete: false,
                fullContent: $fullContent,
            ));
            Coroutine::usleep(100000); // 100ms
        }

        // Then emit an error
        $errorContent = '⚠️ Simulated error: This is a test error to verify error handling in the UI.';
        $fullContent .= $errorContent;

        $this->finalizeTestMessage($userId, $chatId, $assistantMessage, $fullContent);
    }

    /**
     * {markdown} - Tests markdown rendering with various elements.
     */
    private function testMarkdown(int $userId, string $chatId, Message $assistantMessage): void {
        $markdown = <<<'MD'
# Markdown Test

This tests **bold**, *italic*, and `inline code`.

## Code Block

```python
def hello():
    print("Hello, World!")
```

## List

1. First item
2. Second item
3. Third item

- Bullet one
- Bullet two

## Table

| Feature | Status |
|---------|--------|
| Streaming | ✅ |
| Markdown | ✅ |
| Code | ✅ |

> This is a blockquote for testing.

[Link test](https://example.com)
MD;

        $this->streamTestContent($userId, $chatId, $assistantMessage, $markdown);
    }

    /**
     * {artifact:text} - Creates a test text artifact.
     */
    private function testArtifactText(int $userId, string $chatId, Message $assistantMessage): void {
        // Create a real test document
        $document = Document::text(
            chatId: $chatId,
            title: 'Sample Text Document',
            content: "# Welcome to the Artifact Panel\n\nThis is a **sample text document** created by the `{artifact:text}` test command.\n\n## Features\n\n- Rich markdown formatting\n- Version history\n- Real-time collaboration\n\n## Usage\n\nYou can edit this document and the changes will be saved automatically.",
            messageId: $assistantMessage->id,
        );
        $this->documentRepository->save($document);

        // Emit event to open the artifact panel
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $chatId,
            userId: $userId,
            action: 'created',
            kind: $document->kind,
        ));

        $response = "I've created a sample text document for you. Check the artifact panel on the right!";
        $this->streamTestContent($userId, $chatId, $assistantMessage, $response);
    }

    /**
     * {artifact:code} - Creates a test code artifact (with Pyodide loading).
     */
    private function testArtifactCode(int $userId, string $chatId, Message $assistantMessage): void {
        // Create a real Python code document
        $document = Document::code(
            chatId: $chatId,
            title: 'Hello World',
            content: "# A simple Python program\n\ndef greet(name: str) -> str:\n    \"\"\"Return a greeting message.\"\"\"\n    return f\"Hello, {name}!\"\n\n# Test the function\nif __name__ == \"__main__\":\n    print(greet(\"World\"))\n    print(greet(\"AI Chatbot\"))\n",
            language: 'python',
            messageId: $assistantMessage->id,
        );
        $this->documentRepository->save($document);

        // Emit event - this will trigger Pyodide loading via SSE
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $chatId,
            userId: $userId,
            action: 'created',
            kind: $document->kind,
            language: $document->language,
        ));

        $response = "I've created a Python code artifact. The Pyodide runtime will be loaded so you can run it in your browser!";
        $this->streamTestContent($userId, $chatId, $assistantMessage, $response);
    }

    /**
     * {artifact:sheet} - Creates a test spreadsheet artifact.
     */
    private function testArtifactSheet(int $userId, string $chatId, Message $assistantMessage): void {
        // Create a real spreadsheet document
        $document = Document::sheet(
            chatId: $chatId,
            title: 'Sample Spreadsheet',
            content: "Name,Age,City,Occupation\nAlice,28,New York,Engineer\nBob,35,San Francisco,Designer\nCarol,42,Chicago,Manager\nDavid,31,Boston,Developer",
            messageId: $assistantMessage->id,
        );
        $this->documentRepository->save($document);

        // Emit event to open the artifact panel
        $this->eventBus->emit($userId, new DocumentUpdatedEvent(
            documentId: $document->id,
            chatId: $chatId,
            userId: $userId,
            action: 'created',
            kind: $document->kind,
        ));

        $response = "I've created a sample CSV spreadsheet artifact for you. Check the artifact panel!";
        $this->streamTestContent($userId, $chatId, $assistantMessage, $response);
    }

    /**
     * {help} - Shows all available test commands.
     */
    private function testHelp(int $userId, string $chatId, Message $assistantMessage): void {
        $help = <<<'HELP'
## 🧪 Test Commands (localhost only)

| Command | Description |
|---------|-------------|
| `{longStream}` | Streams 50 paragraphs to test streaming performance |
| `{slow}` | Very slow word-by-word streaming (test stop button) |
| `{error}` | Simulates an error during streaming |
| `{markdown}` | Tests markdown rendering (headers, code, lists, tables) |
| `{artifact:text}` | Creates a test text document |
| `{artifact:code}` | Creates a test Python code artifact |
| `{artifact:sheet}` | Creates a test CSV spreadsheet |
| `{help}` | Shows this help message |

These commands work without an AI service configured and are only available on localhost.
HELP;

        $this->streamTestContent($userId, $chatId, $assistantMessage, $help);
    }

    /**
     * Stream test content character by character for realistic effect.
     */
    private function streamTestContent(int $userId, string $chatId, Message $assistantMessage, string $content): void {
        $fullContent = '';
        $chunks = mb_str_split($content, 5); // 5 chars per chunk

        foreach ($chunks as $chunk) {
            if ($this->sessionManager->isStopRequested($chatId, $userId)) {
                $fullContent .= ' ⏹';

                break;
            }

            $fullContent .= $chunk;

            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $chunk,
                isComplete: false,
                fullContent: $fullContent,
            ));

            Coroutine::usleep(20000); // 20ms between chunks
        }

        $this->finalizeTestMessage($userId, $chatId, $assistantMessage, $fullContent);
    }

    /**
     * Finalize a test message by updating the database and signaling completion.
     */
    private function finalizeTestMessage(int $userId, string $chatId, Message $assistantMessage, string $content): void {
        $updatedMessage = $assistantMessage->appendContent($content);
        $this->messageRepository->update($updatedMessage);

        $this->eventBus->emit($userId, new MessageStreamingEvent(
            chatId: $chatId,
            messageId: $assistantMessage->id,
            userId: $userId,
            chunk: '',
            isComplete: true,
            fullContent: $content,
        ));
    }
}
