<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Model\Chat;
use App\Domain\Model\User;
use App\Web\DocumentFeature;
use Mbolli\PhpVia\Context;

/**
 * A chat page's tab: its context and the artifact panel DocumentFeature registered on it.
 */
final class DocumentTab {
    public readonly string $openUrl;

    /** @var callable(): string */
    private $panel;

    public function __construct(public readonly Context $context, DocumentFeature $feature, User $user, Chat $chat) {
        $registered = $feature->register($context, $user, $chat);
        $this->panel = $registered['slots']['artifact'];
        $this->openUrl = $registered['actions']['openDocument'];
    }

    public function render(): string {
        return ($this->panel)();
    }

    /**
     * @param array<string, string> $query
     */
    public function act(string $action, array $query = []): void {
        $this->context->setRequestInput($query, []);
        $this->context->executeAction('artifact-' . $action);
    }

    public function edit(string $content): void {
        $component = array_values($this->context->getComponentRegistry())[0];
        $component->getSignal('content')->setValue($content);
    }

    public function title(): string {
        preg_match('#<span id="artifact-title">(.*?)</span>#', $this->render(), $match);

        return $match[1] ?? '';
    }

    public function command(): string {
        preg_match('#<span hidden id="(artifact-command-\d+)" data-init="([^"]*)"#', $this->render(), $match);

        return $match[1] . ': ' . html_entity_decode($match[2]);
    }
}
