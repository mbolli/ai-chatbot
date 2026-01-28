<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Document;
use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\AI\StreamingSessionManager;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine;

final class MessageCommandHandler implements RequestHandlerInterface {
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
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.send') => $this->send($request),
            str_ends_with($routeName, '.stop') => $this->stop($request),
            str_ends_with($routeName, '.generate') => $this->generate($request),
            default => new EmptyResponse(404),
        };
    }

    public function send(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('chatId');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $data = $this->getRequestData($request);
        $content = $data['_message'] ?? $data['message'] ?? $data['content'] ?? '';

        if (empty(mb_trim($content))) {
            return new EmptyResponse(400);
        }

        // Check if there's already an active streaming session
        if ($this->sessionManager->hasActiveSession($chatId, $userId)) {
            return new EmptyResponse(409); // Conflict - already streaming
        }

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

        // Check if localhost for test commands
        $isLocalhost = $this->isLocalhost($request);

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $userMessage, $assistantMessage, $isLocalhost): void {
            $this->streamAiResponse($userId, $chatId, $chat, $userMessage, $assistantMessage, $isLocalhost);
        });

        return new EmptyResponse(204);
    }

    /**
     * Stop an active AI generation stream.
     */
    public function stop(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('chatId');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
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

        return new EmptyResponse($stopped ? 204 : 404);
    }

    /**
     * Generate AI response for the last user message in a chat.
     * Used when page loads with a pending user message (e.g., after new chat creation).
     */
    public function generate(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('chatId');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        // Check if there's already an active streaming session
        if ($this->sessionManager->hasActiveSession($chatId, $userId)) {
            return new EmptyResponse(409); // Conflict - already streaming
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
            return new EmptyResponse(400); // No user message to respond to
        }

        // Check if there's already an assistant response after this user message
        $foundUserMessage = false;
        foreach ($messages as $message) {
            if ($message->id === $lastUserMessage->id) {
                $foundUserMessage = true;

                continue;
            }
            if ($foundUserMessage && $message->role === 'assistant' && !empty($message->content)) {
                return new EmptyResponse(204); // Already has a response
            }
        }

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

        // Check if localhost for test commands
        $isLocalhost = $this->isLocalhost($request);

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $lastUserMessage, $assistantMessage, $isLocalhost): void {
            $this->streamAiResponse($userId, $chatId, $chat, $lastUserMessage, $assistantMessage, $isLocalhost);
        });

        return new EmptyResponse(204);
    }

    /**
     * Check if request is from localhost (for test commands).
     */
    private function isLocalhost(ServerRequestInterface $request): bool {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? '';
        $host = $request->getUri()->getHost();

        return \in_array($remoteAddr, ['127.0.0.1', '::1'], true)
            || \in_array($host, ['localhost', '127.0.0.1'], true);
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
        try {
            // Check for test commands first
            $testCommand = $this->getTestCommand($userMessage->content ?? '', $isLocalhost);
            if ($testCommand !== null) {
                $this->executeTestCommand($testCommand, $userId, $chatId, $assistantMessage);

                return;
            }

            // Get conversation history
            $messages = $this->messageRepository->findByChat($chatId);
            $history = $this->buildConversationHistory($messages);

            // Stream AI response
            $fullContent = '';
            $wasStopped = false;

            foreach ($this->aiService->streamChat($history, $chat->model, $chatId, $assistantMessage->id) as $chunk) {
                // Check if stop was requested
                if ($this->sessionManager->isStopRequested($chatId, $userId)) {
                    $wasStopped = true;

                    break;
                }

                $fullContent .= $chunk;

                $this->eventBus->emit($userId, new MessageStreamingEvent(
                    chatId: $chatId,
                    messageId: $assistantMessage->id,
                    userId: $userId,
                    chunk: $chunk,
                    isComplete: false,
                ));
            }

            // Update message with full content
            $updatedMessage = $assistantMessage->appendContent($fullContent);
            $this->messageRepository->update($updatedMessage);

            // Signal completion
            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $wasStopped ? ' ⏹' : '',
                isComplete: true,
            ));

            // Generate title if this is the first message exchange (and not stopped early)
            if (!$wasStopped && $chat->title === null && \count($messages) <= 2) {
                $this->generateChatTitle($userId, $chat, $userMessage->content ?? '');
            }
        } catch (\Throwable $e) {
            // Log error and send error message to user
            error_log('AI streaming error: ' . $e->getMessage());

            // Provide a user-friendly error message
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'API key not configured')) {
                $errorContent = '⚠️ AI service is not configured. Please set up your API keys in the environment.';
            } elseif (str_contains($errorMessage, 'rate limit')) {
                $errorContent = '⚠️ Rate limit reached. Please wait a moment and try again.';
            } else {
                $errorContent = '⚠️ Sorry, I encountered an error while generating a response. Please try again.';
            }

            $updatedMessage = $assistantMessage->appendContent($errorContent);
            $this->messageRepository->update($updatedMessage);

            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $assistantMessage->id,
                userId: $userId,
                chunk: $errorContent,
                isComplete: true,
            ));
        } finally {
            // Always clean up the session
            $this->sessionManager->endSession($chatId, $userId);
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

    /**
     * Build conversation history for AI context.
     *
     * @param list<Message> $messages
     *
     * @return array<array{role: string, content: string}>
     */
    private function buildConversationHistory(array $messages): array {
        $history = [];

        foreach ($messages as $msg) {
            // Skip messages without content (like newly created assistant placeholders)
            if (empty($msg->content)) {
                continue;
            }

            $history[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        return $history;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestData(ServerRequestInterface $request): array {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?? [];

            if (isset($data['datastar'])) {
                $data = $data['datastar'];
            }

            return $data;
        }

        return $request->getParsedBody() ?? [];
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
            ));

            Coroutine::sleep(0.05); // 50ms between chunks
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
            ));

            Coroutine::sleep(0.5); // 500ms between words
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
            ));
            Coroutine::sleep(0.1);
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
            ));

            Coroutine::sleep(0.02); // 20ms between chunks
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
        ));
    }
}
