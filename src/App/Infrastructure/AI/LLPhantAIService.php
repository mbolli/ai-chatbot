<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Service\AIServiceInterface;
use App\Infrastructure\AI\Tools\CreateDocumentTool;
use App\Infrastructure\AI\Tools\UpdateDocumentTool;
use LLPhant\AnthropicConfig;
use LLPhant\Chat\AnthropicChat;
use LLPhant\Chat\ChatInterface;
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
     * Default model to use when requested model is not found.
     * Haiku 4.5 is the cheapest Anthropic model still served.
     */
    public const string DEFAULT_MODEL = 'claude-haiku-4-5';

    /**
     * Anthropic Claude models (prices per MTok, input/output).
     */
    private const array ANTHROPIC_MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',     // $4/$20
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5', // $2/$10
        'claude-haiku-4-5' => 'Claude Haiku 4.5',   // $1/$5
    ];

    /**
     * Production-allowed Anthropic models (cost-effective only).
     */
    private const array ANTHROPIC_MODELS_PROD = [
        'claude-haiku-4-5' => 'Claude Haiku 4.5',   // $1/$5
    ];

    /**
     * OpenAI GPT models (prices per MTok, input/output).
     */
    private const array OPENAI_MODELS = [
        'gpt-6-sol' => 'GPT-6 Sol',           // $2/$10
        'gpt-5.6-terra' => 'GPT-5.6 Terra',   // $2/$12
        'gpt-6-luna' => 'GPT-6 Luna',         // $0.10/$0.50
        'gpt-5.6-luna' => 'GPT-5.6 Luna',     // $0.20/$1.20
        'gpt-4.1-mini' => 'GPT-4.1 Mini',     // $0.40/$1.60
        'gpt-4o-mini' => 'GPT-4o Mini',       // $0.15/$0.60
    ];

    /**
     * Production-allowed OpenAI models (cost-effective only), cheapest first.
     */
    private const array OPENAI_MODELS_PROD = [
        'gpt-6-luna' => 'GPT-6 Luna',         // $0.10/$0.50
        'gpt-4o-mini' => 'GPT-4o Mini',       // $0.15/$0.60
        'gpt-5.6-luna' => 'GPT-5.6 Luna',     // $0.20/$1.20
        'gpt-4.1-mini' => 'GPT-4.1 Mini',     // $0.40/$1.60
    ];

    /**
     * Fast model for title generation (prefer cheapest for speed/cost).
     */
    private const string TITLE_MODEL_ANTHROPIC = 'claude-haiku-4-5';
    private const string TITLE_MODEL_OPENAI = 'gpt-6-luna';

    public function __construct(
        private readonly ?string $anthropicApiKey = null,
        private readonly ?string $openaiApiKey = null,
        private readonly ?DocumentRepositoryInterface $documentRepository = null,
        private readonly int $maxTokens = 2048,
        private readonly ?string $defaultModel = null,
        private readonly bool $productionMode = false,
        private readonly string $responseFormat = 'markdown',
    ) {}

    public function streamChat(array $messages, string $model, ?string $chatId = null, ?string $messageId = null): \Generator {
        // Stored chats may reference retired models, and the model command accepts any string
        if (!($this->getAvailableModels()[$model]['available'] ?? false)) {
            $model = $this->getDefaultModel();
        }

        // Clients and tools are created per call: this service is shared by all coroutines in the worker
        $createTool = null;
        $updateTool = null;
        if ($this->documentRepository !== null && $chatId !== null) {
            $createTool = new CreateDocumentTool($this->documentRepository);
            $createTool->setChatContext($chatId, $messageId);
            $updateTool = new UpdateDocumentTool($this->documentRepository, $chatId);
        }

        if ($this->getProvider($model) === 'openai') {
            if ($this->openaiApiKey === null) {
                throw new \RuntimeException('OpenAI API key not configured');
            }

            $client = new OpenAIStreamingClient($this->openaiApiKey, $this->maxTokens);
        } else {
            if ($this->anthropicApiKey === null) {
                throw new \RuntimeException('Anthropic API key not configured');
            }

            $client = new AnthropicStreamingClient($this->anthropicApiKey, $this->maxTokens);
        }

        $client->setTools($createTool, $updateTool);

        yield from $client->streamChatRealtime($messages, $model, $this->getSystemPrompt());
    }

    public function getDefaultModel(): string {
        $models = $this->getAvailableModels();

        // Configured default if selectable, else the cheap built-in default, else first available
        foreach ([$this->defaultModel, self::DEFAULT_MODEL] as $candidate) {
            if ($candidate !== null && ($models[$candidate]['available'] ?? false)) {
                return $candidate;
            }
        }

        foreach ($models as $id => $info) {
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

    private function getSystemPrompt(): string {
        $formatInstructions = $this->responseFormat === 'plain'
            ? "- Keep responses as plain text without markdown formatting\n- Use simple line breaks for structure\n- Avoid headers, bullet points, and code blocks unless specifically requested"
            : "- Use markdown formatting when helpful (headers, lists, code blocks)\n- Format code with appropriate language syntax highlighting";

        return <<<PROMPT
You are a helpful AI assistant. You provide clear, accurate, and helpful responses.

Guidelines:
- Be concise but thorough
{$formatInstructions}
- If you're unsure, say so
- Be friendly and professional

## Document/Artifact Creation

When the user asks you to create, write, or generate content that would benefit from being in a separate document (code, articles, data, etc.), use the createDocument tool:

- **text**: For articles, essays, documentation, markdown content. Use markdown syntax (# headers, **bold**, etc.), NOT raw HTML tags.
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
