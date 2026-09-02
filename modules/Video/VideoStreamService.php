<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoStreamService
{
    private const CHUNK_SIZE = 8192;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $video
     */
    public function stream(array $video, ?string $rangeHeader, string $method = 'GET'): void
    {
        $path = ($this->storage ?? new VideoStorage($this->config))->pathForStoredVideo($video);

        $this->streamPath($path, $video, $rangeHeader, $method);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function streamExport(array $job, ?string $rangeHeader, string $method = 'GET'): void
    {
        $path = ($this->storage ?? new VideoStorage($this->config))->pathForExportJob($job);
        $resource = [
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
        ];

        $this->streamPath($path, $resource, $rangeHeader, $method);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function streamPath(?string $path, array $resource, ?string $rangeHeader, string $method): void
    {
        if ($path === null) {
            http_response_code(404);
            return;
        }

        $size = filesize($path);

        if (!is_int($size) || $size <= 0) {
            http_response_code(404);
            return;
        }

        $range = $this->range($rangeHeader, $size);

        if ($range === false) {
            http_response_code(416);
            header('Accept-Ranges: bytes');
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            return;
        }

        [$start, $end, $partial] = $range;
        $length = $end - $start + 1;

        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $this->contentType($resource));
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if ($partial) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        if (strtoupper($method) === 'HEAD') {
            return;
        }

        $handle = fopen($path, 'rb');

        if (!is_resource($handle)) {
            http_response_code(404);
            return;
        }

        try {
            fseek($handle, $start);
            $remaining = $length;

            while ($remaining > 0 && !feof($handle)) {
                $bytes = fread($handle, min(self::CHUNK_SIZE, $remaining));

                if (!is_string($bytes) || $bytes === '') {
                    break;
                }

                echo $bytes;
                $remaining -= strlen($bytes);

                if (function_exists('flush')) {
                    flush();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{0: int, 1: int, 2: bool}|false
     */
    public function range(?string $rangeHeader, int $size): array|false
    {
        if ($size <= 0) {
            return [0, 0, false];
        }

        if ($rangeHeader === null || trim($rangeHeader) === '') {
            return [0, $size - 1, false];
        }

        $rangeHeader = trim($rangeHeader);

        if (str_contains($rangeHeader, ',') || preg_match('/\Abytes=(\d*)-(\d*)\z/', $rangeHeader, $matches) !== 1) {
            return false;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return false;
        }

        if ($startRaw === '') {
            $suffixLength = (int) $endRaw;

            if ($suffixLength <= 0) {
                return false;
            }

            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $startRaw;
            $end = $endRaw === '' ? $size - 1 : (int) $endRaw;
        }

        if ($start < 0 || $end < $start || $start >= $size) {
            return false;
        }

        return [$start, min($end, $size - 1), true];
    }

    /**
     * @param array<string, mixed> $video
     */
    private function contentType(array $video): string
    {
        $mimeType = is_string($video['mime_type'] ?? null) ? (string) $video['mime_type'] : '';

        if (str_starts_with($mimeType, 'video/')) {
            return $mimeType;
        }

        return match ((string) ($video['extension'] ?? '')) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }
}
