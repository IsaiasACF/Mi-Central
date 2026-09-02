<?php
declare(strict_types=1);

namespace App\Support;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $path = dirname(__DIR__) . '/Views/' . $template . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException('View not found.');
        }

        extract($data, EXTR_SKIP);
        require $path;
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
