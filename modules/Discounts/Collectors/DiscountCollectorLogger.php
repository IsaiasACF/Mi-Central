<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class DiscountCollectorLogger
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, mixed> $run
     */
    public function logRun(array $run): void
    {
        $parts = [
            gmdate('c'),
            'collector=' . $this->value($run['collector_key'] ?? ''),
            'trigger=' . $this->value($run['trigger_type'] ?? ''),
            'status=' . $this->value($run['status'] ?? ''),
            'collected=' . (int) ($run['collected_count'] ?? 0),
            'created=' . (int) ($run['created_count'] ?? 0),
            'updated=' . (int) ($run['updated_count'] ?? 0),
            'duplicates=' . (int) ($run['duplicate_count'] ?? 0),
            'skipped=' . (int) ($run['skipped_count'] ?? 0),
            'warnings=' . (int) ($run['warning_count'] ?? 0),
            'errors=' . (int) ($run['error_count'] ?? 0),
            'duration_ms=' . (int) ($run['duration_ms'] ?? 0),
        ];

        if (is_string($run['error_type'] ?? null) && $run['error_type'] !== '') {
            $parts[] = 'error_type=' . $this->value($run['error_type']);
        }

        if (is_string($run['error_message'] ?? null) && $run['error_message'] !== '') {
            $parts[] = 'message="' . $this->quoted($run['error_message']) . '"';
        }

        $this->write(implode(' ', $parts));
    }

    public function logMessage(string $message): void
    {
        $this->write(gmdate('c') . ' ' . DiscountCollectorErrorSanitizer::sanitize($message, 500));
    }

    private function write(string $line): void
    {
        try {
            $directory = dirname($this->path);

            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            error_log($line . PHP_EOL, 3, $this->path);
        } catch (\Throwable) {
        }
    }

    private function value(mixed $value): string
    {
        return str_replace(["\t", "\n", "\r", ' '], '_', DiscountCollectorErrorSanitizer::sanitize((string) $value, 120));
    }

    private function quoted(string $value): string
    {
        return str_replace('"', "'", DiscountCollectorErrorSanitizer::sanitize($value, 500));
    }
}
