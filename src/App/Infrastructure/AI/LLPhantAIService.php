<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use LLPhant\AnthropicConfig;
use LLPhant\Chat\AnthropicChat;
use LLPhant\Chat\ChatInterface;
use LLPhant\Chat\FunctionInfo\FunctionBuilder;
use LLPhant\Chat\Message;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;

/**
 * AI Service implementation using LLPhant library for OpenAI
 * and custom AnthropicStreamingClient for Anthropic.
 *
 * Model IDs are defined here directly to support newer models
 * that may not yet be in LLPhant.
 */
final class LLPhantAIService implements AIServiceInterface {
    /**
     * Anthropic Claude models (current + previous generation).
     *
     * Opus: Maximum intelligence (premium)
     * - 4.5: $5/$25 per MTok (newest, best value for Opus)
     * - 4.1/4: $15/$75 per MTok
     *
     * Sonnet: Best balance of speed and intelligence
     * - 4.5/4: $3/$15 per MTok
     *
     * Haiku: Fastest, most cost-effective
     * - 4.5: $1/$5 per MTok
     * - 3.5: $0.80/$4 per MTok
     * - 3: $0.25/$1.25 per MTok (cheapest!)
     */
    private const array ANTHROPIC_MODELS = [
        // Claude 4.5 (current)
        'claude-opus-4-5-20250501' => 'Claude Opus 4.5',
        'claude-sonnet-4-5-20250514' => 'Claude Sonnet 4.5',
        'claude-haiku-4-5-20250501' => 'Claude Haiku 4.5',
        // Claude 4.x
        'claude-opus-4-1-20250415' => 'Claude Opus 4.1',
        'claude-opus-4-20250401' => 'Claude Opus 4',
        'claude-sonnet-4-20250401' => 'Claude Sonnet 4',
        // Claude 3.x (still available, Haiku 3 is cheapest)
        'claude-3-5-haiku-20241022' => 'Claude Haiku 3.5',
        'claude-3-haiku-20240307' => 'Claude Haiku 3',
    ];

    /**
     * Production-allowed Anthropic models (cost-effective Haiku only).
     * Sorted by cost: Haiku 3 < 3.5 < 4.5.
     */
    private const array ANTHROPIC_MODELS_PROD = [
        'claude-3-haiku-20240307' => 'Claude Haiku 3',       // $0.25/$1.25 - cheapest
        'claude-3-5-haiku-20241022' => 'Claude Haiku 3.5',   // $0.80/$4
        'claude-haiku-4-5-20250501' => 'Claude Haiku 4.5',   // $1/$5
    ];

    /**
     * OpenAI GPT models (current + previous generation).
     *
     * GPT-5.x (current generation - 2025):
     * - gpt-5.2/5.1/5: Full capability ($1.25-1.75 input / $10-14 output)
     * - gpt-5-mini: Balanced ($0.25 input / $2 output)
     * - gpt-5-nano: Cheapest ($0.05 input / $0.40 output)
     *
     * GPT-4.x (previous generation):
     * - gpt-4.1/mini/nano: Latest GPT-4 series
     * - gpt-4o/mini: Omni models
     */
    private const array OPENAI_MODELS = [
        // GPT-5.x (current)
        'gpt-5.2' => 'GPT-5.2',
        'gpt-5.1' => 'GPT-5.1',
        'gpt-5' => 'GPT-5',
        'gpt-5-mini' => 'GPT-5 Mini',
        'gpt-5-nano' => 'GPT-5 Nano',
        // GPT-4.x (previous gen)
        'gpt-4.1' => 'GPT-4.1',
        'gpt-4.1-mini' => 'GPT-4.1 Mini',
        'gpt-4.1-nano' => 'GPT-4.1 Nano',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o Mini',
    ];

    /**
     * Production-allowed OpenAI models (cost-effective only).
     * Sorted by cost (cheapest first): nano < mini < 4o-mini < 4.1-nano.
     */
    private const array OPENAI_MODELS_PROD = [
        'gpt-5-nano' => 'GPT-5 Nano',       // $0.05/$0.40 - cheapest
        'gpt-5-mini' => 'GPT-5 Mini',       // $0.25/$2.00
        'gpt-4o-mini' => 'GPT-4o Mini',     // $0.15/$0.60
        'gpt-4.1-nano' => 'GPT-4.1 Nano',   // $0.10/$0.40
        'gpt-4.1-mini' => 'GPT-4.1 Mini',   // $0.40/$1.60
    ];

    /**
     * Default model to use when requested model is not found.
     * Using Haiku 3 as it's the cheapest option.
     */
    public const string DEFAULT_MODEL = 'claude-3-haiku-20240307';

    /**
     * Fast model for title generation (prefer cheapest for speed/cost).
     */
    private const string TITLE_MODEL_ANTHROPIC = 'claude-3-haiku-20240307';
    private const string TITLE_MODEL_OPENAI = 'gpt-5-nano';

    private CreateDocumentTool $createDocumentTool;
    private UpdateDocumentTool $updateDocumentTool;
    private ?AnthropicStreamingClient $anthropicClient = null;
    private ?OpenAIStreamingClient $openaiClient = null;

    /** @var list<Document> */
    private array $createdDocuments = [];

    public function __construct(
        private readonly ?string $anthropicApiKey = null,
        private readonly ?string $openaiApiKey = null,
        ?DocumentRepositoryInterface $documentRepository = null,
        private readonly int $maxTokens = 2048,
        private readonly ?string $defaultModel = null,
        private readonly bool $productionMode = false,
    ) {
        // Initialize tools if repository is provided
        if ($documentRepository !== null) {
            $this->createDocumentTool = new CreateDocumentTool($documentRepository);
            $this->updateDocumentTool = new UpdateDocumentTool($documentRepository);
        }

        // Initialize custom Anthropic client for true streaming
        if ($this->anthropicApiKey !== null) {
            $this->anthropicClient = new AnthropicStreamingClient(
                $this->anthropicApiKey,
                $this->maxTokens,
            );
        }

        // Initialize custom OpenAI client for true streaming
        if ($this->openaiApiKey !== null) {
            $this->openaiClient = new OpenAIStreamingClient(
                $this->openaiApiKey,
                $this->maxTokens,
            );
        }
    }

    public function streamChat(array $messages, string $model, ?string $chatId = null, ?string $messageId = null): \Generator {
        $this->createdDocuments = [];

        $provider = $this->getProvider($model);

        // Use custom streaming client for Anthropic (true streaming)
        if ($provider === 'anthropic' && $this->anthropicClient !== null) {
            // Configure tools on the streaming client
            if (isset($this->createDocumentTool) && $chatId !== null) {
                $this->createDocumentTool->setChatContext($chatId, $messageId);
                $this->anthropicClient->setTools($this->createDocumentTool, $this->updateDocumentTool);
            } else {
                $this->anthropicClient->setTools(null, null);
            }

            yield from $this->streamAnthropicChat($messages, $model);

            // Collect any created documents
            if (isset($this->createDocumentTool)) {
                $doc = $this->createDocumentTool->getLastCreatedDocument();
                if ($doc !== null) {
                    $this->createdDocuments[] = $doc;
                }
            }

            return;
        }

        // Use custom streaming client for OpenAI (true streaming)
        if ($provider === 'openai' && $this->openaiClient !== null) {
            // Configure tools on the streaming client
            if (isset($this->createDocumentTool) && $chatId !== null) {
                $this->createDocumentTool->setChatContext($chatId, $messageId);
                $this->openaiClient->setTools($this->createDocumentTool, $this->updateDocumentTool);
            } else {
                $this->openaiClient->setTools(null, null);
            }

            yield from $this->streamOpenAIChat($messages, $model);

            // Collect any created documents
            if (isset($this->createDocumentTool)) {
                $doc = $this->createDocumentTool->getLastCreatedDocument();
                if ($doc !== null) {
                    $this->createdDocuments[] = $doc;
                }
            }

            return;
        }

        // Fallback to LLPhant (when custom clients unavailable)
        $chat = $this->createChat($model);
        $llMessages = $this->convertMessages($messages);

        // Set system message with tool instructions
        $chat->setSystemMessage($this->getSystemPrompt());

        // Configure tools if available and chat ID is provided
        if (isset($this->createDocumentTool) && $chatId !== null) {
            $this->createDocumentTool->setChatContext($chatId, $messageId);
            $this->configureTools($chat);
        }

        // Stream the response using generateChatStream
        $stream = $chat->generateChatStream($llMessages);

        // Read chunks from the PSR-7 stream
        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            if ($chunk !== '') {
                yield $chunk;
            }
        }

        // Collect any created documents
        if (isset($this->createDocumentTool)) {
            $doc = $this->createDocumentTool->getLastCreatedDocument();
            if ($doc !== null) {
                $this->createdDocuments[] = $doc;
            }
        }
    }

    public function getCreatedDocuments(): array {
        return $this->createdDocuments;
    }

    public function getDefaultModel(): string {
        // Return configured default, or first available model, or fallback
        if ($this->defaultModel !== null) {
            return $this->defaultModel;
        }

        // Pick first available
        foreach ($this->getAvailableModels() as $id => $info) {
            if ($info['available']) {
                return $id;
            }
        }

        return self::DEFAULT_MODEL;
    }

    public function generateTitle(string $firstMessage): string {
        // Use a fast model for title generation
        $model = $this->anthropicApiKey !== null ? self::TITLE_MODEL_ANTHROPIC : self::TITLE_MODEL_OPENAI;
        $chat = $this->createChat($model);

        $prompt = "Generate a very short title (max 6 words) for a chat that starts with this message. Return only the title, no quotes or explanation:\n\n" . $firstMessage;

        try {
            $response = $chat->generateText($prompt);

            return mb_trim($response);
        } catch (\Throwable $e) {
            // Fallback: use first few words of the message
            $words = explode(' ', $firstMessage);

            return implode(' ', \array_slice($words, 0, 5)) . (\count($words) > 5 ? '...' : '');
        }
    }

    public function getAvailableModels(): array {
        $models = [];

        // Select model list based on production mode
        $anthropicModels = $this->productionMode ? self::ANTHROPIC_MODELS_PROD : self::ANTHROPIC_MODELS;
        $openaiModels = $this->productionMode ? self::OPENAI_MODELS_PROD : self::OPENAI_MODELS;

        // Add Anthropic models, marking availability
        foreach ($anthropicModels as $id => $name) {
            $models[$id] = [
                'name' => $name,
                'provider' => 'anthropic',
                'available' => $this->anthropicApiKey !== null,
            ];
        }

        // Add OpenAI models, marking availability
        foreach ($openaiModels as $id => $name) {
            $models[$id] = [
                'name' => $name,
                'provider' => 'openai',
                'available' => $this->openaiApiKey !== null,
            ];
        }

        return $models;
    }

    /**
     * Stream chat using custom Anthropic client for true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages
     *
     * @return \Generator<string>
     */
    private function streamAnthropicChat(array $messages, string $model): \Generator {
        if ($this->anthropicClient === null) {
            throw new \RuntimeException('Anthropic API key not configured');
        }

        yield from $this->anthropicClient->streamChatRealtime(
            $messages,
            $model,
            $this->getSystemPrompt(),
        );
    }

    /**
     * Stream chat using custom OpenAI client for true real-time streaming.
     *
     * @param array<array{role: string, content: string}> $messages
     *
     * @return \Generator<string>
     */
    private function streamOpenAIChat(array $messages, string $model): \Generator {
        if ($this->openaiClient === null) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        yield from $this->openaiClient->streamChatRealtime(
            $messages,
            $model,
            $this->getSystemPrompt(),
        );
    }

    private function configureTools(ChatInterface $chat): void {
        // Build function info for tools
        $createDocFn = FunctionBuilder::buildFunctionInfo($this->createDocumentTool, 'createDocument');
        $updateDocFn = FunctionBuilder::buildFunctionInfo($this->updateDocumentTool, 'updateDocument');

        // Add tools to the chat
        if ($chat instanceof AnthropicChat) {
            $chat->addTool($createDocFn);
            $chat->addTool($updateDocFn);
        } elseif ($chat instanceof OpenAIChat) {
            $chat->addTool($createDocFn);
            $chat->addTool($updateDocFn);
        }
    }

    private function getProvider(string $model): string {
        // Check all model lists (including prod variants for complete coverage)
        if (\array_key_exists($model, self::ANTHROPIC_MODELS) || \array_key_exists($model, self::ANTHROPIC_MODELS_PROD)) {
            return 'anthropic';
        }

        if (\array_key_exists($model, self::OPENAI_MODELS) || \array_key_exists($model, self::OPENAI_MODELS_PROD)) {
            return 'openai';
        }

        // Default to anthropic for unknown models (will use default model)
        return 'anthropic';
    }

    /**
     * Check if a model ID is valid (exists in any model list).
     */
    private function isValidModel(string $model): bool {
        return \array_key_exists($model, self::ANTHROPIC_MODELS)
            || \array_key_exists($model, self::ANTHROPIC_MODELS_PROD)
            || \array_key_exists($model, self::OPENAI_MODELS)
            || \array_key_exists($model, self::OPENAI_MODELS_PROD);
    }

    private function createChat(string $model): ChatInterface {
        $provider = $this->getProvider($model);

        // If model not found in our lists, use configured default or fallback
        if (!$this->isValidModel($model)) {
            $model = $this->defaultModel ?? self::DEFAULT_MODEL;
            $provider = $this->getProvider($model);
        }

        return match ($provider) {
            'anthropic' => $this->createAnthropicChat($model),
            'openai' => $this->createOpenAIChat($model),
            default => throw new \RuntimeException("Unknown provider: {$provider}"),
        };
    }

    private function createAnthropicChat(string $model): AnthropicChat {
        if ($this->anthropicApiKey === null) {
            throw new \RuntimeException('Anthropic API key not configured');
        }

        $config = new AnthropicConfig(
            model: $model,
            maxTokens: $this->maxTokens,
            apiKey: $this->anthropicApiKey,
        );

        return new AnthropicChat($config);
    }

    private function createOpenAIChat(string $model): OpenAIChat {
        if ($this->openaiApiKey === null) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $config = new OpenAIConfig();
        $config->model = $model;
        $config->apiKey = $this->openaiApiKey;

        return new OpenAIChat($config);
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     *
     * @return Message[]
     */
    private function convertMessages(array $messages): array {
        $llMessages = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'];

            $llMessage = match ($role) {
                'user' => Message::user($content),
                'assistant' => Message::assistant($content),
                'system' => Message::system($content),
                default => Message::user($content),
            };

            $llMessages[] = $llMessage;
        }

        return $llMessages;
    }

    private function getSystemPrompt(): string {
        return <<<'PROMPT'
You are a helpful AI assistant. You provide clear, accurate, and helpful responses.

Guidelines:
- Be concise but thorough
- Use markdown formatting when helpful
- If you're unsure, say so
- Be friendly and professional

## Document/Artifact Creation

When the user asks you to create, write, or generate content that would benefit from being in a separate document (code, articles, data, etc.), use the createDocument tool:

- **text**: For articles, essays, documentation, markdown content
- **code**: For programming code (specify the language: python, javascript, php, etc.)
- **sheet**: For tabular data in CSV format
- **image**: For SVG graphics or image content

When updating existing documents, use the updateDocument tool with the document ID.

Examples of when to create documents:
- "Write me a Python script to..." → create code document
- "Create a README for..." → create text document
- "Generate a CSV with..." → create sheet document
- "Make an SVG icon of..." → create image document
PROMPT;
    }
}
