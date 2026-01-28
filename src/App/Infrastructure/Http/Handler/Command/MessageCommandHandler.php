<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Model\Chat;
use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
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
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
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

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $userMessage, $assistantMessage): void {
            $this->streamAiResponse($userId, $chatId, $chat, $userMessage, $assistantMessage);
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

        // Stream AI response in a coroutine
        Coroutine::create(function () use ($userId, $chatId, $chat, $lastUserMessage, $assistantMessage): void {
            $this->streamAiResponse($userId, $chatId, $chat, $lastUserMessage, $assistantMessage);
        });

        return new EmptyResponse(204);
    }

    /**
     * Stream AI response to the user via SSE.
     */
    private function streamAiResponse(int $userId, string $chatId, Chat $chat, Message $userMessage, Message $assistantMessage): void {
        try {
            // Get conversation history
            $messages = $this->messageRepository->findByChat($chatId);
            $history = $this->buildConversationHistory($messages);

            // Stream AI response
            $fullContent = '';
            $wasStopped = false;

            foreach ($this->aiService->streamChat($history, $chat->model) as $chunk) {
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
}
