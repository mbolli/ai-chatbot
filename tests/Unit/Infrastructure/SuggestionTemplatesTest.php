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
    beforeEach(function (): void {
        $this->content = fn (Document $document, array $suggestions = [], bool $canEdit = true): string => $this->renderer->partial('artifact-content', [
            'document' => $document,
            'latest' => true,
            'suggestions' => $suggestions,
            'canEdit' => $canEdit,
            'actions' => ['accept' => '/_action/artifact-accept', 'dismiss' => '/_action/artifact-dismiss', 'save' => '/_action/artifact-save'],
            'contentSignal' => 'artifact_content____n',
            'renderer' => $this->renderer,
            'e' => TemplateRenderer::escape(...),
        ]);
    });

    it('renders suggestions with accept and dismiss actions and highlights them', function (): void {
        $document = Document::text('chat', 'Essay', "# Title\n\nIt's a error. Fine sentence.");
        $suggestion = Suggestion::create($document->id, "It's a error.", "It's an error.", 'Use "an" before vowels');

        $html = ($this->content)($document, [$suggestion]);

        expect($html)->toContain('id="artifact-suggestions"')
            ->toContain('1 suggestion<')
            ->toContain("@post('/_action/artifact-accept?id={$suggestion->id}')")
            ->toContain("@post('/_action/artifact-dismiss?id={$suggestion->id}')")
            ->toContain('Use &quot;an&quot; before vowels')
            ->toContain('<mark class="suggestion-highlight" id="suggestion-' . $suggestion->id . '-highlight"')
            ->toContain(">It's a error.</mark> Fine sentence.")
        ;
    });

    it('renders no suggestion block without suggestions', function (): void {
        $html = ($this->content)(Document::text('chat', 'Essay', 'Plain.'));

        expect($html)->not->toContain('artifact-suggestions')->toContain('Plain.');
    });
});

describe('artifact editor', function (): void {
    beforeEach(function (): void {
        $this->content = fn (Document $document, bool $canEdit = true): string => $this->renderer->partial('artifact-content', [
            'document' => $document,
            'latest' => true,
            'suggestions' => [],
            'canEdit' => $canEdit,
            'actions' => ['save' => '/_action/artifact-save'],
            'contentSignal' => 'artifact_content____n',
            'renderer' => $this->renderer,
            'e' => TemplateRenderer::escape(...),
        ]);
    });

    it('binds an empty textarea to the content signal that the edit button fills', function (): void {
        $html = ($this->content)(Document::code('chat', 'Script', 'echo "$total";'));

        expect($html)->toContain('data-bind="artifact_content____n"')
            ->toMatch('#<textarea[^>]*>\s*</textarea>#')
            ->toContain('$artifact_content____n = &quot;echo \\&quot;$total\\&quot;;&quot;; $_artifactEditing = true')
            ->toContain("@post('/_action/artifact-save')")
        ;
    });

    it('offers no editor to visitors and none for a raster image', function (): void {
        expect(($this->content)(Document::sheet('chat', 'Data', 'a,b'), canEdit: false))->not->toContain('artifact-edit')
            ->and(($this->content)(Document::image('chat', 'Photo', 'data:image/png;base64,AAAA')))->not->toContain('artifact-edit')
            ->and(($this->content)(Document::image('chat', 'Logo', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')))->toContain('Edit SVG')
        ;
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
