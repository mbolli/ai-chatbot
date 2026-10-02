<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler\Command;

use App\Domain\Event\VoteUpdatedEvent;
use App\Domain\Model\Vote;
use App\Domain\Repository\ChatRepositoryInterface;
use App\Domain\Repository\MessageRepositoryInterface;
use App\Domain\Repository\VoteRepositoryInterface;
use App\Infrastructure\Auth\AuthMiddleware;
use App\Infrastructure\EventBus\EventBusInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class VoteCommandHandler implements RequestHandlerInterface {
    public function __construct(
        private readonly VoteRepositoryInterface $voteRepository,
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly EventBusInterface $eventBus,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $chatId = $request->getAttribute('chatId');
        $messageId = $request->getAttribute('messageId');

        /** @var int $userId */
        $userId = $request->getAttribute(AuthMiddleware::ATTR_USER_ID);

        // Validate chat exists and user owns it
        $chat = $this->chatRepository->find($chatId);
        if ($chat === null) {
            return new EmptyResponse(404);
        }

        if (!$chat->isOwnedBy($userId)) {
            return new EmptyResponse(403);
        }

        // Validate message exists and belongs to this chat
        $message = $this->messageRepository->find($messageId);
        if ($message === null || $message->chatId !== $chatId) {
            return new EmptyResponse(404);
        }

        // Only allow voting on assistant messages
        if ($message->role !== 'assistant') {
            return new EmptyResponse(400);
        }

        // Get vote data from request
        $data = $this->getRequestData($request);
        $isUpvote = $data['isUpvote'] ?? null;

        if ($isUpvote === null) {
            return new EmptyResponse(400);
        }

        // Convert to boolean
        $isUpvote = filter_var($isUpvote, FILTER_VALIDATE_BOOLEAN);

        // Check if user already voted on this message
        $existingVote = $this->voteRepository->find($messageId, $userId);

        $newVoteState = null;

        if ($existingVote !== null) {
            // If same vote, remove it (toggle off)
            if ($existingVote->isUpvote === $isUpvote) {
                $this->voteRepository->delete($messageId, $userId);
                $newVoteState = null;
            } else {
                // Different vote - update to new vote
                $vote = $isUpvote
                    ? Vote::upvote($chatId, $messageId, $userId)
                    : Vote::downvote($chatId, $messageId, $userId);

                $this->voteRepository->save($vote);
                $newVoteState = $isUpvote;
            }
        } else {
            // No existing vote - create new one
            $vote = $isUpvote
                ? Vote::upvote($chatId, $messageId, $userId)
                : Vote::downvote($chatId, $messageId, $userId);

            $this->voteRepository->save($vote);
            $newVoteState = $isUpvote;
        }

        // Emit event for SSE handler to update UI
        $this->eventBus->emit($userId, new VoteUpdatedEvent(
            $chatId,
            $messageId,
            $userId,
            $newVoteState,
        ));

        return new EmptyResponse(204);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestData(ServerRequestInterface $request): array {
        $contentType = $request->getHeaderLine('Content-Type');

        // Handle JSON
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?? [];

            // Datastar wraps data in 'datastar' key
            if (isset($data['datastar'])) {
                return $data['datastar'];
            }

            return $data;
        }

        // Handle form data
        $parsed = $request->getParsedBody();

        return \is_array($parsed) ? $parsed : [];
    }
}
