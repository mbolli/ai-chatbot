<?php

declare(strict_types=1);

use App\Infrastructure\Template\TemplateRenderer;

beforeEach(function (): void {
    $this->renderer = new TemplateRenderer(['partials' => [__DIR__ . '/../../../templates/partials']]);
});

describe('reasoning block', function (): void {
    it('is part of a streaming assistant message with an empty text element', function (): void {
        $html = $this->renderer->render('partials::message', [
            'id' => 'm1',
            'role' => 'assistant',
            'content' => '',
            'chatId' => 'c1',
            'streaming' => true,
            'e' => TemplateRenderer::escape(...),
            'md' => TemplateRenderer::md(...),
        ]);

        expect($html)->toContain('<details class="message-reasoning" id="message-m1-reasoning">')
            ->toContain('<div class="message-reasoning-text markdown-content" id="message-m1-reasoning-text"></div>')
            ->toContain('Thinking…')
            ->not->toContain('<details class="message-reasoning" id="message-m1-reasoning" open')
        ;
    });

    it('is absent from completed and user messages', function (string $role, bool $streaming): void {
        $html = $this->renderer->render('partials::message', [
            'id' => 'm1',
            'role' => $role,
            'content' => 'Hi',
            'chatId' => 'c1',
            'streaming' => $streaming,
            'e' => TemplateRenderer::escape(...),
            'md' => TemplateRenderer::md(...),
        ]);

        expect($html)->not->toContain('message-reasoning');
    })->with([['assistant', false], ['user', false]]);

    it('renders reasoning text as escaped markdown for the SSE patch', function (): void {
        $html = $this->renderer->partial('message-reasoning-text', [
            'id' => 'm1',
            'thinking' => '**Plan** <script>alert(1)</script>',
            'e' => TemplateRenderer::escape(...),
        ]);

        expect($html)->toStartWith('<div class="message-reasoning-text markdown-content" id="message-m1-reasoning-text">')
            ->toContain('<strong>Plan</strong>')
            ->not->toContain('<script>')
        ;
    });
});
