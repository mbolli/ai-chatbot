<?php

declare(strict_types=1);

namespace App\Web;

use App\Container;
use App\Domain\Model\Chat;
use App\Domain\Model\User;
use App\Infrastructure\Template\TemplateRenderer;
use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;

/**
 * The artifact panel of a chat page: a live component in the chat's scope that shows one document per tab.
 */
final class DocumentFeature {
    public function __construct(private readonly Container $container) {}

    /**
     * @return array{actions: array<string, string>, slots: array<string, callable(): string>}
     */
    public function register(Context $c, User $user, Chat $chat): array {
        $urls = [];
        $panel = $c->component(function (Context $cc) use ($user, $chat, &$urls): void {
            $urls = $this->registerPanel($cc, $user, $chat);
        }, 'artifact');

        return ['actions' => ['openDocument' => $urls['open'] ?? ''], 'slots' => ['artifact' => $panel]];
    }

    /**
     * @return array<string, string> action name => URL
     */
    private function registerPanel(Context $cc, User $user, Chat $chat): array {
        $cc->addScope(Scopes::chat($chat->id));

        // Which document and version this tab shows; the server decides, so a re-render keeps them
        $documentId = $cc->signal('', 'document', clientWritable: false);
        $version = $cc->signal(0, 'version', clientWritable: false);
        $content = $cc->signal('', 'content');
        $panel = new DocumentPanel($documentId, $version);

        $documents = $this->container->documentCommands();
        $suggestions = $this->container->suggestionCommands();

        $actions = [
            'open' => $cc->action(function (Context $ctx) use ($cc, $user, $chat, $documents, $panel): void {
                $id = self::stringInput($ctx, 'id');
                if ($documents->find($user->id, $chat->id, $id) !== null) {
                    $panel->show($id);
                    $cc->sync();
                }
            }, 'open'),
            'version' => $cc->action(function (Context $ctx) use ($cc, $user, $chat, $documents, $panel): void {
                $versions = array_column($documents->versions($user->id, $chat->id, $panel->documentId()), 'version');
                $requested = (int) self::stringInput($ctx, 'version');
                if (\in_array($requested, $versions, true)) {
                    $panel->showVersion($requested === max($versions) ? 0 : $requested);
                    $cc->sync();
                }
            }, 'version'),
            'save' => $cc->action(function () use ($cc, $user, $chat, $documents, $panel, $content): void {
                if ($documents->update($user->id, $chat->id, $panel->documentId(), $content->string()) === 204) {
                    // The broadcast re-renders the panel; the edit buffer need not travel with every request
                    $panel->showVersion(0);
                    $content->setValue('');
                    $cc->syncSignals();
                }
            }, 'save'),
            'delete' => $cc->action(function () use ($user, $chat, $documents, $panel): void {
                $documents->delete($user->id, $chat->id, $panel->documentId());
            }, 'delete'),
            'accept' => $cc->action(function (Context $ctx) use ($user, $chat, $suggestions): void {
                $suggestions->accept($user->id, $chat->id, self::stringInput($ctx, 'id'));
            }, 'accept'),
            'dismiss' => $cc->action(function (Context $ctx) use ($user, $chat, $suggestions): void {
                $suggestions->dismiss($user->id, $chat->id, self::stringInput($ctx, 'id'));
            }, 'dismiss'),
        ];
        $urls = array_map(static fn (Action $action): string => $action->url(), $actions);

        $cc->view(fn (): string => $this->render($user, $chat, $panel, $content, $urls), cacheUpdates: false);

        return $urls;
    }

    /**
     * @param array<string, string> $actions
     */
    private function render(User $user, Chat $chat, DocumentPanel $panel, Signal $content, array $actions): string {
        $documents = $this->container->documentCommands();

        // The reply created a document or asked for suggestions on one: the owner's tabs show it, once per request
        $request = $this->container->liveState()->documentRequest($chat->id);
        if ($request !== null && $chat->isOwnedBy($user->id)) {
            $panel->showRequested($request['documentId'], $request['seq']);
        }

        $document = null;
        if ($panel->documentId() !== '') {
            $document = $documents->find($user->id, $chat->id, $panel->documentId(), $panel->version() ?: null);
            if ($document === null) {
                // Deleted, or the chat is no longer public
                $panel->clear();
            }
        }

        $versions = $document !== null ? $documents->versions($user->id, $chat->id, $document->id) : [];
        $latest = $document !== null && ($versions === [] || $document->currentVersion === $versions[0]['version']);

        return $this->container->renderer()->partial('artifact-panel', [
            'document' => $document,
            'versions' => $versions,
            'latest' => $latest,
            'suggestions' => $document !== null && $latest ? $this->container->suggestionCommands()->pending($user->id, $chat->id, $document) : [],
            'canEdit' => $chat->isOwnedBy($user->id),
            'command' => $panel->command(),
            'actions' => $actions,
            'contentSignal' => $content->id(),
            'renderer' => $this->container->renderer(),
            'e' => TemplateRenderer::escape(...),
        ]);
    }

    private static function stringInput(Context $ctx, string $name): string {
        $value = $ctx->input($name);

        return \is_string($value) ? $value : '';
    }
}
