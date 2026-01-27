<?php

declare(strict_types=1);

namespace App\Infrastructure\Template;

final class TemplateRenderer {
    /** @var array<string, list<string>> */
    private array $paths;

    /**
     * @param array<string, list<string>> $paths
     */
    public function __construct(array $paths = []) {
        $this->paths = $paths;
    }

    /**
     * Render a template with the given data.
     *
     * @param string               $template Template name in format "namespace::template"
     * @param array<string, mixed> $data     Data to pass to the template
     *
     * @return string Rendered HTML
     */
    public function render(string $template, array $data = []): string {
        $templatePath = $this->resolveTemplate($template);

        if ($templatePath === null) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        return $this->renderFile($templatePath, $data);
    }

    /**
     * Render a partial template.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string {
        return $this->render('partials::' . $template, $data);
    }

    /**
     * Escape HTML entities.
     */
    public static function escape(string $string): string {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Shorthand for escape.
     */
    public static function e(string $string): string {
        return self::escape($string);
    }

    private function resolveTemplate(string $template): ?string {
        // Parse namespace::template format
        if (str_contains($template, '::')) {
            [$namespace, $name] = explode('::', $template, 2);
        } else {
            $namespace = 'app';
            $name = $template;
        }

        if (!isset($this->paths[$namespace])) {
            return null;
        }

        foreach ($this->paths[$namespace] as $basePath) {
            $path = $basePath . '/' . $name . '.php';
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $path, array $data): string {
        extract($data);

        ob_start();

        try {
            include $path;

            return ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }
}
