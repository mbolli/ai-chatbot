<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Token usage of one assistant message, summed over its tool continuations.
 */
final readonly class MessageUsage {
    public const string STOP_REASON_USER = 'stopped_by_user';

    /**
     * @param string $stopReason A StopReason value, or STOP_REASON_USER
     * @param bool   $estimated  True when the provider reported no usage and the tokens were estimated from text length
     */
    public function __construct(
        public string $messageId,
        public int $userId,
        public string $model,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public bool $estimated,
        public \DateTimeImmutable $createdAt,
    ) {}

    public function totalTokens(): int {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            messageId: (string) $data['message_id'],
            userId: (int) $data['user_id'],
            model: (string) $data['model'],
            stopReason: (string) $data['stop_reason'],
            inputTokens: (int) $data['input_tokens'],
            outputTokens: (int) $data['output_tokens'],
            cacheReadTokens: (int) $data['cache_read_tokens'],
            cacheWriteTokens: (int) $data['cache_write_tokens'],
            estimated: (bool) $data['estimated'],
            createdAt: (new \DateTimeImmutable())->setTimestamp((int) $data['created_at']),
        );
    }

    /**
     * @return array{message_id: string, user_id: int, model: string, stop_reason: string, input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, estimated: int, created_at: int}
     */
    public function toArray(): array {
        return [
            'message_id' => $this->messageId,
            'user_id' => $this->userId,
            'model' => $this->model,
            'stop_reason' => $this->stopReason,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'estimated' => $this->estimated ? 1 : 0,
            'created_at' => $this->createdAt->getTimestamp(),
        ];
    }
}
