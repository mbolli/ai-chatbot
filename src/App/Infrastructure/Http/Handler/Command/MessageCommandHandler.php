<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Model\Message;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MessageCommandHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.send') => $this->send($request),
            default => new EmptyResponse(404),
        };
    }

    public function send(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('chatId');
        $userId = 1; // TODO: Get from auth

        $chat = $this->chatRepository->find($chatId);

        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        $data = $this->getRequestData($request);
        $content = $data['message'] ?? $data['content'] ?? '';

        if (empty(mb_trim($content))) {
            return new EmptyResponse(400);
        }

        // Create user message
        $userMessage = Message::user($chatId, $content);
        $this->messageRepository->save($userMessage);

        // Emit event to update UI with user message
        $this->eventBus->emit($userId, new ChatUpdatedEvent(
            chatId: $chatId,
            userId: $userId,
            action: 'message_added',
        ));

        // Create placeholder for assistant message
        $assistantMessage = Message::assistant($chatId);
        $this->messageRepository->save($assistantMessage);

        // TODO: In Phase 4, this is where we'll trigger AI streaming
        // For now, just add a placeholder response
        $this->simulateAiResponse($userId, $chatId, $assistantMessage);

        return new EmptyResponse(204);
    }

    /**
     * Temporary placeholder until AI integration in Phase 4.
     */
    private function simulateAiResponse(int $userId, string $chatId, Message $message): void {
        // Simulate streaming response
        $response = "Hello! I'm an AI assistant. AI integration will be added in Phase 4.";

        // In Phase 4, this will be replaced with actual AI streaming
        $chunks = mb_str_split($response, 5);

        foreach ($chunks as $chunk) {
            $this->eventBus->emit($userId, new MessageStreamingEvent(
                chatId: $chatId,
                messageId: $message->id,
                userId: $userId,
                chunk: $chunk,
                isComplete: false,
            ));
        }

        // Update message with full content
        $updatedMessage = $message->appendContent($response);
        $this->messageRepository->update($updatedMessage);

        // Signal completion
        $this->eventBus->emit($userId, new MessageStreamingEvent(
            chatId: $chatId,
            messageId: $message->id,
            userId: $userId,
            chunk: '',
            isComplete: true,
        ));
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
                return $data['datastar'];
            }

            return $data;
        }

        return $request->getParsedBody() ?? [];
    }
}
