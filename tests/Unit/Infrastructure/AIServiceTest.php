<?php

declare(strict_types=1);

use App\Infrastructure\AI\AIService;

it('falls back to the cheap default model when the configured default is retired', function (): void {
    $service = new AIService(anthropicApiKey: 'key', defaultModel: 'claude-3-haiku-20240307');

    expect($service->getDefaultModel())->toBe(AIService::DEFAULT_MODEL)
        ->and($service->getAvailableModels())->not->toHaveKey('claude-3-haiku-20240307')
    ;
});

it('ignores a configured default whose provider has no API key', function (): void {
    $service = new AIService(anthropicApiKey: 'key', defaultModel: 'gpt-6-luna');

    expect($service->getDefaultModel())->toBe(AIService::DEFAULT_MODEL);
});

it('falls back to an OpenAI model when only an OpenAI key is configured', function (): void {
    $service = new AIService(openaiApiKey: 'key');

    expect($service->getAvailableModels()[$service->getDefaultModel()]['provider'])->toBe('openai');
});

it('offers only cheap models in production mode', function (): void {
    $service = new AIService(anthropicApiKey: 'key', openaiApiKey: 'key', productionMode: true);

    expect(array_keys($service->getAvailableModels()))
        ->toContain('claude-haiku-4-5', 'gpt-6-luna')
        ->not->toContain('claude-opus-5-5', 'claude-sonnet-5-5', 'gpt-6-sol')
    ;
});
