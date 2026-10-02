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
        private readonly string $responseFormat = 'markdown',
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

        // Stored chats may reference retired models, and the model command accepts any string
        if (!($this->getAvailableModels()[$model]['available'] ?? false)) {
            $model = $this->getDefaultModel();
        }

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
