<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoStorage
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function uploadsDirectory(): string
    {
        $directory = $this->config['uploads_path'] ?? null;

        return is_string($directory) && $directory !== '' ? rtrim($directory, DIRECTORY_SEPARATOR) : dirname(__DIR__, 2) . '/storage/video/uploads';
    }

    public function exportsDirectory(): string
    {
        $directory = $this->config['exports_path'] ?? null;

        return is_string($directory) && $directory !== '' ? rtrim($directory, DIRECTORY_SEPARATOR) : dirname(__DIR__, 2) . '/storage/video/exports';
    }

    public function tempDirectory(): string
    {
        $directory = $this->config['temp_path'] ?? null;

        return is_string($directory) && $directory !== '' ? rtrim($directory, DIRECTORY_SEPARATOR) : dirname(__DIR__, 2) . '/storage/video/temp';
    }

    public function ensureUploadsDirectory(): void
    {
        $directory = $this->uploadsDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new VideoValidationException('No se pudo preparar el almacenamiento de videos.');
        }

        if (!is_writable($directory)) {
            throw new VideoValidationException('No se pudo preparar el almacenamiento de videos.');
        }
    }

    public function ensureExportDirectories(): void
    {
        foreach ([$this->exportsDirectory(), $this->tempDirectory()] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new VideoValidationException('No se pudo preparar el almacenamiento de exportaciones.');
            }

            if (!is_writable($directory)) {
                throw new VideoValidationException('No se pudo preparar el almacenamiento de exportaciones.');
            }
        }
    }

    public function destinationForStoredName(string $storedName): string
    {
        return $this->uploadsDirectory() . DIRECTORY_SEPARATOR . $storedName;
    }

    public function relativePathForStoredName(string $storedName): string
    {
        return 'storage/video/uploads/' . $storedName;
    }

    public function isUploadDestinationSafe(string $path): bool
    {
        $directory = realpath($this->uploadsDirectory());

        if (!is_string($directory)) {
            return false;
        }

        $parent = realpath(dirname($path));

        return is_string($parent) && $parent === $directory && basename($path) === pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * @param array<string, mixed> $video
     */
    public function pathForStoredVideo(array $video): ?string
    {
        $storedName = is_string($video['stored_name'] ?? null) ? (string) $video['stored_name'] : '';

        if (preg_match('/\A[0-9a-f]{32}\.(mp4|mov|m4v|webm|mkv)\z/', $storedName) !== 1) {
            return null;
        }

        $storagePath = is_string($video['storage_path'] ?? null) ? (string) $video['storage_path'] : '';

        if ($storagePath !== $this->relativePathForStoredName($storedName)) {
            return null;
        }

        $path = $this->destinationForStoredName($storedName);

        if (!is_file($path)) {
            return null;
        }

        $directory = realpath($this->uploadsDirectory());
        $realPath = realpath($path);

        if (!is_string($directory) || !is_string($realPath)) {
            return null;
        }

        return str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR) ? $realPath : null;
    }

    public function safeExportPath(string $storedName): ?string
    {
        if (preg_match('/\Aexport_[0-9a-f]{32}\.mp4\z/', $storedName) !== 1) {
            return null;
        }

        $directory = realpath($this->exportsDirectory());

        if (!is_string($directory)) {
            return null;
        }

        return $directory . DIRECTORY_SEPARATOR . $storedName;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function pathForExportJob(array $job): ?string
    {
        $storedName = is_string($job['output_stored_name'] ?? null) ? (string) $job['output_stored_name'] : '';
        $path = $this->safeExportPath($storedName);

        if ($path === null || !is_file($path)) {
            return null;
        }

        $outputPath = is_string($job['output_path'] ?? null) ? (string) $job['output_path'] : '';

        if ($outputPath !== $this->relativeExportPath($storedName)) {
            return null;
        }

        $directory = realpath($this->exportsDirectory());
        $realPath = realpath($path);

        if (!is_string($directory) || !is_string($realPath)) {
            return null;
        }

        return str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR) ? $realPath : null;
    }

    public function safeTempExportPath(int $jobId, string $token): ?string
    {
        if ($jobId <= 0 || preg_match('/\A[0-9a-f]{16}\z/', $token) !== 1) {
            return null;
        }

        $directory = realpath($this->tempDirectory());

        if (!is_string($directory)) {
            return null;
        }

        return $directory . DIRECTORY_SEPARATOR . 'export_' . $jobId . '_' . $token . '.tmp.mp4';
    }

    public function relativeExportPath(string $storedName): string
    {
        return 'storage/video/exports/' . $storedName;
    }
}
