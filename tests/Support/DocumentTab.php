<?php

declare(strict_types=1);

namespace Tests\Support;

use Dom\HTMLDocument;
use Mbolli\PhpVia\Testing\TestTab;

/**
 * A chat page's tab, seen through its artifact panel: the panel as the browser last received it.
 */
final class DocumentTab {
    private string $panel = '';
    private ?string $panelSelector = null;
    private int $updates = 0;

    /** @var array<string, string> signals the next action sends, as typing in the editor sets them */
    private array $edits = [];

    public function __construct(public readonly TestTab $tab) {
        $this->read();
    }

    /**
     * The panel's HTML; before any update, as the page load rendered it.
     */
    public function render(): string {
        $this->read();

        return $this->panel;
    }

    /**
     * @param array<string, string> $query
     */
    public function act(string $action, array $query = []): void {
        $this->tab->action('artifact.' . $action, $query, $this->edits);
        $this->edits = [];
    }

    public function edit(string $content): void {
        $this->edits['artifact.content'] = $content;
    }

    /**
     * How many times the server re-rendered the panel for this tab since the last call.
     */
    public function updates(): int {
        $this->read();
        $updates = $this->updates;
        $this->updates = 0;

        return $updates;
    }

    public function title(): string {
        preg_match('#<span id="artifact-title">(.*?)</span>#', $this->render(), $match);

        return $match[1] ?? '';
    }

    public function command(): string {
        // The page load's panel went through the DOM serializer, which writes hidden=""
        preg_match('#<span hidden(?:="")? id="(artifact-command-\d+)" data-init="([^"]*)"#', $this->render(), $match);

        return $match[1] . ': ' . html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
    }

    private function read(): void {
        foreach ($this->tab->patches() as $patch) {
            if ($patch['type'] !== 'elements') {
                continue;
            }
            if ($patch['selector'] === null && str_starts_with($patch['html'], '<!DOCTYPE')) {
                $this->fromPage($patch['html']);
            } elseif ($patch['selector'] !== null && $patch['selector'] === $this->panelSelector) {
                $this->panel = $patch['html'];
                ++$this->updates;
            }
        }
    }

    /**
     * Take the panel out of the page: the component wrapper around its open or close command.
     */
    private function fromPage(string $html): void {
        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $panel = $page->querySelector('span[id^="artifact-command-"]')?->parentElement
            ?? throw new \RuntimeException('The page has no artifact panel.');
        $this->panelSelector = '#' . $panel->id;
        $this->panel = $page->saveHtml($panel);
    }
}
