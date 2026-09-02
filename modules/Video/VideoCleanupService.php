<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoCleanupService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly VideoExportJobRepository $jobs,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    /**
     * @return array{expired_exports_removed: int, temporary_files_removed: int, errors: int}
     */
    public function cleanup(): array
    {
        $storage = $this->storage ?? new VideoStorage($this->config);
        $storage->ensureExportDirectories();
        $summary = [
            'expired_exports_removed' => 0,
            'temporary_files_removed' => 0,
            'errors' => 0,
        ];

        foreach ($this->expiredJobs() as $job) {
            try {
                $path = $storage->pathForExportJob($job);

                if ($path !== null && is_file($path) && !$this->deleteFileInside($path, $storage->exportsDirectory())) {
                    $summary['errors']++;
                    continue;
                }

                $this->jobs->deleteById((int) $job['id']);
                $summary['expired_exports_removed']++;
            } catch (\Throwable) {
                $summary['errors']++;
            }
        }

        foreach ($this->oldTempFiles($storage) as $file) {
            try {
                if ($this->deleteFileInside($file, $storage->tempDirectory())) {
                    $summary['temporary_files_removed']++;
                } else {
                    $summary['errors']++;
                }
            } catch (\Throwable) {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expiredJobs(): array
    {
        return $this->jobs->expiredCompletedJobs();
    }

    /**
     * @return array<int, string>
     */
    private function oldTempFiles(VideoStorage $storage): array
    {
        $directory = realpath($storage->tempDirectory());

        if (!is_string($directory)) {
            return [];
        }

        $processingExportIds = $this->jobs->processingJobIds();
        $cutoff = time() - 86400;
        $files = [];
        $iterator = new \DirectoryIterator($directory);

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $name = $item->getFilename();

            if (preg_match('/\Aexport_([1-9][0-9]*)_[0-9a-f]{16}\.tmp\.mp4\z/', $name, $matches) === 1) {
                if (in_array((int) $matches[1], $processingExportIds, true) || $item->getMTime() >= $cutoff) {
                    continue;
                }
            } else {
                continue;
            }

            $path = $item->getPathname();
            $realPath = realpath($path);

            if (is_string($realPath) && str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR)) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    private function deleteFileInside(string $path, string $directory): bool
    {
        $base = realpath($directory);
        $realPath = realpath($path);

        if (!is_string($base) || !is_string($realPath) || !str_starts_with($realPath, $base . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
            return false;
        }

        return unlink($realPath);
    }
}
