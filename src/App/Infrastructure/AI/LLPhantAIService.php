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
use LLPhant\Chat\Enums\OpenAIChatModel;
use LLPhant\Chat\FunctionInfo\FunctionBuilder;
use LLPhant\Chat\Message;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;

/**
 * AI Service implementation using LLPhant library.
 *
 * Models are sourced from LLPhant's constants and enums,
 * filtered by which API keys are configured.
 */
final class LLPhantAIService implements AIServiceInterface {
    /**
     * Anthropic models from LLPhant's AnthropicConfig constants.
     * We select only the ones we want to expose in this app.
     */
    private const array ANTHROPIC_MODELS = [
        AnthropicConfig::CLAUDE_3_5_SONNET_20241022 => 'Claude 3.5 Sonnet',
        AnthropicConfig::CLAUDE_3_5_SONNET => 'Claude 3.5 Sonnet (June)',
        AnthropicConfig::CLAUDE_3_OPUS => 'Claude 3 Opus',
        AnthropicConfig::CLAUDE_3_SONNET => 'Claude 3 Sonnet',
        AnthropicConfig::CLAUDE_3_HAIKU => 'Claude 3 Haiku',
    ];

    /**
     * OpenAI models from LLPhant's OpenAIChatModel enum.
     * We select only the ones we want to expose in this app.
     */
    private const array OPENAI_MODELS = [
        OpenAIChatModel::Gpt4Omni->value => 'GPT-4o',
        OpenAIChatModel::Gpt4Omini->value => 'GPT-4o Mini',
        OpenAIChatModel::Gpt4Turbo->value => 'GPT-4 Turbo',
        OpenAIChatModel::Gpt4->value => 'GPT-4',
    ];

    /**
     * Default model to use when requested model is not found.
     */
    private const string DEFAULT_MODEL = AnthropicConfig::CLAUDE_3_HAIKU;

    /**
     * Fast model for title generation (prefer Haiku for speed/cost).
     */
    private const string TITLE_MODEL_ANTHROPIC = AnthropicConfig::CLAUDE_3_HAIKU;
    private const string TITLE_MODEL_OPENAI = OpenAIChatModel::Gpt4Omini->value;

    private CreateDocumentTool $createDocumentTool;
    private UpdateDocumentTool $updateDocumentTool;
    private ?AnthropicStreamingClient $anthropicClient = null;

    /** @var list<Document> */
    private array $createdDocuments = [];

    public function __construct(
        private readonly ?string $anthropicApiKey = null,
        private readonly ?string $openaiApiKey = null,
        ?DocumentRepositoryInterface $documentRepository = null,
        private readonly int $maxTokens = 2048,
        private readonly ?string $defaultModel = null,
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
    }

    public function streamChat(array $messages, string $model, ?string $chatId = null, ?string $messageId = null): \Generator {
        $this->createdDocuments = [];

        $provider = $this->getProvider($model);

        // Use custom streaming client for Anthropic (true streaming)
        if ($provider === 'anthropic' && $this->anthropicClient !== null) {
            yield from $this->streamAnthropicChat($messages, $model);

            return;
        }

        // Fallback to LLPhant for OpenAI (which has better streaming support)
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

        // Add all Anthropic models, marking availability
        foreach (self::ANTHROPIC_MODELS as $id => $name) {
            $models[$id] = [
                'name' => $name,
                'provider' => 'anthropic',
                'available' => $this->anthropicApiKey !== null,
            ];
        }

        // Add all OpenAI models, marking availability
        foreach (self::OPENAI_MODELS as $id => $name) {
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
        if (\array_key_exists($model, self::ANTHROPIC_MODELS)) {
            return 'anthropic';
        }

        if (\array_key_exists($model, self::OPENAI_MODELS)) {
            return 'openai';
        }

        // Default to anthropic for unknown models (will use default model)
        return 'anthropic';
    }

    private function createChat(string $model): ChatInterface {
        $provider = $this->getProvider($model);

        // If model not found in our lists, use configured default or fallback
        if (!\array_key_exists($model, self::ANTHROPIC_MODELS) && !\array_key_exists($model, self::OPENAI_MODELS)) {
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
