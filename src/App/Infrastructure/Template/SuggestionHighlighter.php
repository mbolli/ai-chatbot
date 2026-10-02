<?php

declare(strict_types=1);

namespace App\Infrastructure\Template;

use App\Domain\Model\Suggestion;

/**
 * Marks the sentences that suggestions would replace in rendered markdown.
 */
final class SuggestionHighlighter {
    /**
     * Wraps the first occurrence of each original sentence in a <mark>. Sentences that span markup
     * (bold, links, line breaks) stay unmarked; the suggestion list still shows them.
     *
     * @param list<Suggestion> $suggestions
     */
    public static function highlight(string $html, array $suggestions): string {
        foreach ($suggestions as $suggestion) {
            // Parsedown escapes text nodes the same way
            $needle = htmlspecialchars($suggestion->originalText, ENT_NOQUOTES, 'UTF-8');
            $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

            foreach ($parts as $index => $part) {
                $position = str_starts_with($part, '<') ? false : strpos($part, $needle);

                if ($position !== false) {
                    $mark = '<mark class="suggestion-highlight" id="suggestion-' . TemplateRenderer::escape($suggestion->id) . '-highlight" title="'
                        . TemplateRenderer::escape($suggestion->description) . '">' . $needle . '</mark>';
                    $parts[$index] = substr_replace($part, $mark, $position, \strlen($needle));
                    $html = implode('', $parts);

                    break;
                }
            }
        }

        return $html;
    }
}
