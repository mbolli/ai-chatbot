<?php

declare(strict_types=1);

use App\Domain\Service\AIServiceInterface;

describe('AIServiceInterface', function (): void {
    it('defines required methods', function (): void {
        $reflection = new ReflectionClass(AIServiceInterface::class);

        expect($reflection->hasMethod('streamChat'))->toBeTrue();
        expect($reflection->hasMethod('generateTitle'))->toBeTrue();
        expect($reflection->hasMethod('getAvailableModels'))->toBeTrue();
    });

    it('streamChat returns a Generator', function (): void {
        $mock = Mockery::mock(AIServiceInterface::class);
        $mock->shouldReceive('streamChat')
            ->with([['role' => 'user', 'content' => 'Hello']], 'test-model')
            ->andReturnUsing(function (): Generator {
                yield 'Hello ';

                yield 'World!';
            })
        ;

        $result = $mock->streamChat([['role' => 'user', 'content' => 'Hello']], 'test-model');

        expect($result)->toBeInstanceOf(Generator::class);

        $chunks = [];
        foreach ($result as $chunk) {
            $chunks[] = $chunk;
        }

        expect($chunks)->toBe(['Hello ', 'World!']);
    });

    it('generateTitle returns a string', function (): void {
        $mock = Mockery::mock(AIServiceInterface::class);
        $mock->shouldReceive('generateTitle')
            ->with('How do I write a PHP function?')
            ->andReturn('PHP Function Writing')
        ;

        $result = $mock->generateTitle('How do I write a PHP function?');

        expect($result)->toBe('PHP Function Writing');
    });

    it('getAvailableModels returns model configuration', function (): void {
        $mock = Mockery::mock(AIServiceInterface::class);
        $mock->shouldReceive('getAvailableModels')
            ->andReturn([
                'model-1' => ['name' => 'Model 1', 'provider' => 'test'],
                'model-2' => ['name' => 'Model 2', 'provider' => 'test'],
            ])
        ;

        $result = $mock->getAvailableModels();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('model-1');
        expect($result['model-1']['name'])->toBe('Model 1');
    });
});
