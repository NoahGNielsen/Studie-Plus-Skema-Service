<?php

declare(strict_types=1);

namespace SkemaService\Web;

/** Renderer en skabelon i src/Web/views inde i layoutet. Al output escapes med $h(). */
final class View
{
    /** @param array<string, mixed> $vars */
    public static function render(string $template, array $vars = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo self::capture($template, $vars);
    }

    /** @param array<string, mixed> $vars */
    public static function capture(string $template, array $vars = []): string
    {
        $file = __DIR__ . '/views/' . basename($template) . '.php';
        $vars['h'] = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $vars['csrf'] ??= Csrf::token();
        $content = self::renderFile($file, $vars);

        return self::renderFile(__DIR__ . '/views/layout.php', $vars + ['content' => $content]);
    }

    /** @param array<string, mixed> $vars */
    private static function renderFile(string $file, array $vars): string
    {
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
