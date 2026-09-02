<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountValidationException;
use PDOException;
use Throwable;

final class DiscountCollectorErrorSanitizer
{
    public static function typeFromThrowable(Throwable $exception): string
    {
        if ($exception instanceof CollectorConfigurationException) {
            return 'configuration';
        }

        if ($exception instanceof CollectorHttpException) {
            return self::messageSuggestsTimeout($exception->getMessage()) ? 'timeout' : 'http';
        }

        if ($exception instanceof CollectorParseException) {
            return 'parse';
        }

        if ($exception instanceof DiscountValidationException) {
            return 'validation';
        }

        if ($exception instanceof PDOException) {
            return 'persistence';
        }

        return self::typeFromName($exception::class, $exception->getMessage());
    }

    public static function typeFromName(?string $type, ?string $message = null): string
    {
        $type = strtolower((string) $type);
        $message = (string) $message;

        if (self::messageSuggestsTimeout($message) || str_contains($type, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($type, 'configuration')) {
            return 'configuration';
        }

        if (str_contains($type, 'http') || str_contains($message, 'HTTP')) {
            return 'http';
        }

        if (str_contains($type, 'parse')) {
            return 'parse';
        }

        if (str_contains($type, 'validation')) {
            return 'validation';
        }

        if (str_contains($type, 'pdo') || str_contains($type, 'database')) {
            return 'persistence';
        }

        if (str_contains($type, 'normalization') || str_contains($type, 'normalizer')) {
            return 'normalization';
        }

        return 'unknown';
    }

    public static function sanitize(?string $message, int $maxLength = 1200): string
    {
        $message = trim(strip_tags((string) $message));
        $message = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $message);
        $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $message);
        $message = (string) preg_replace('/\s+/', ' ', $message);

        $patterns = [
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '/\b(authorization|cookie|set-cookie|x-api-key)\s*[:=]\s*("[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '/\b(password|passwd|token|secret|api_key|apikey|access_token|refresh_token|db_password)\s*=\s*[^&\s]+/i',
        ];

        foreach ($patterns as $pattern) {
            $message = (string) preg_replace($pattern, '$1=[redacted]', $message);
        }

        if ($message === '') {
            $message = 'Error de collector.';
        }

        return substr($message, 0, max(1, $maxLength));
    }

    /**
     * @param array<int, string> $items
     */
    public static function summarize(array $items, int $limit = 5): ?string
    {
        if ($items === []) {
            return null;
        }

        $limit = max(1, min(10, $limit));
        $summary = [];

        foreach (array_slice($items, 0, $limit) as $item) {
            $summary[] = self::sanitize($item, 300);
        }

        $remaining = count($items) - count($summary);

        if ($remaining > 0) {
            $summary[] = $remaining . ' adicionales.';
        }

        return implode("\n", $summary);
    }

    private static function messageSuggestsTimeout(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'time-out')
            || str_contains($message, 'tiempo de espera');
    }
}
