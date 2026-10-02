<?php

declare(strict_types=1);

use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Template\SuggestionHighlighter;
use App\Infrastructure\Template\TemplateRenderer;

beforeEach(function (): void {
    $this->renderer = new TemplateRenderer(['partials' => [__DIR__ . '/../../../templates/partials']]);
});

describe('artifact suggestions', function (): void {
    it('renders applicable suggestions with accept and dismiss commands and highlights them', function (): void {
        $document = Document::text('chat', 'Essay', "# Title\n\nIt's a error. Fine sentence.");
        $applicable = Suggestion::create($document->id, "It's a error.", "It's an error.", 'Use "an" before vowels');
        $stale = Suggestion::create($document->id, 'Deleted sentence.', 'Gone.', 'Stale');

        $html = $this->renderer->partial('artifact-content', [
            'document' => $document,
            'suggestions' => [$applicable, $stale],
            'renderer' => $this->renderer,
        ]);

        expect($html)->toContain('id="artifact-suggestions"')
            ->toContain('1 suggestion<')
            ->toContain("@post('/cmd/suggestion/{$applicable->id}/accept')")
            ->toContain("@post('/cmd/suggestion/{$applicable->id}/dismiss')")
            ->toContain('Use &quot;an&quot; before vowels')
            ->toContain('<mark class="suggestion-highlight" id="suggestion-' . $applicable->id . '-highlight"')
            ->toContain(">It's a error.</mark> Fine sentence.")
            ->not->toContain($stale->id)
        ;
    });

    it('renders no suggestion block without suggestions', function (): void {
        $document = Document::text('chat', 'Essay', 'Plain.');

        $html = $this->renderer->partial('artifact-content', ['document' => $document, 'renderer' => $this->renderer]);

        expect($html)->not->toContain('artifact-suggestions')->toContain('Plain.');
    });
});

describe('SuggestionHighlighter', function (): void {
    it('never marks text inside tags or attributes', function (): void {
        $suggestion = Suggestion::create('doc', 'example', 'sample', 'd');

        $html = SuggestionHighlighter::highlight('<p><a href="https://example.com">link</a> an example</p>', [$suggestion]);

        expect($html)->toBe('<p><a href="https://example.com">link</a> an <mark class="suggestion-highlight" id="suggestion-' . $suggestion->id . '-highlight" title="d">example</mark></p>');
    });

    it('leaves sentences spanning markup unmarked', function (): void {
        $suggestion = Suggestion::create('doc', 'A **bold** move.', 'A brave move.', 'd');
        $html = TemplateRenderer::md('A **bold** move.');

        expect(SuggestionHighlighter::highlight($html, [$suggestion]))->toBe($html);
    });

    it('matches text that markdown escapes', function (): void {
        $suggestion = Suggestion::create('doc', 'Fish & chips <3 taste "great".', 'Fish and chips taste great.', 'd');

        expect(SuggestionHighlighter::highlight(TemplateRenderer::md('Fish & chips <3 taste "great".'), [$suggestion]))
            ->toContain('<mark class="suggestion-highlight"')
        ;
    });
});
