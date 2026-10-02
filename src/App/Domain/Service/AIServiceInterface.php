<?php

declare(strict_types=1);

namespace App\Domain\Service;

/**
 * Interface for AI chat services.
 */
interface AIServiceInterface {
    /**
     * Generate a streaming AI response.
     *
     * @param array<array{role: string, content: string}> $messages  Conversation history
     * @param string                                      $model     The model to use
     * @param null|string                                 $chatId    Chat ID for tool context
     * @param null|string                                 $messageId Message ID for tool context
     *
     * @return \Generator<int, Stream\StreamEnd|Stream\TextDelta|Stream\ThinkingDelta|Stream\ToolCall|Stream\ToolResult> Ends with exactly one StreamEnd
     */
    public function streamChat(array $messages, string $model, ?string $chatId = null, ?string $messageId = null): \Generator;

    /**
     * Generate a chat title based on the first message.
     *
     * @param string $firstMessage The user's first message
     *
     * @return string A short title for the chat
     */
    public function generateTitle(string $firstMessage): string;

    /**
     * Get available models.
     *
     * @return array<string, array{name: string, provider: string, available: bool}>
     */
    public function getAvailableModels(): array;

    /**
     * Get the configured default model.
     *
     * @return string Model ID
     */
    public function getDefaultModel(): string;
}
