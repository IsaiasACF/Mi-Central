<?php
declare(strict_types=1);

namespace Modules\Video;

use Closure;
use Throwable;

final class VideoService
{
    private const ORIGINAL_NAME_MAX_LENGTH = 180;
    private const DEFAULT_MAX_UPLOAD_MB = 1024;
    private const STATUS_PENDING_METADATA = 'pending_metadata';
    private const ALLOWED_MIME_TYPES = [
        'mp4' => ['video/mp4', 'application/mp4'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'm4v' => ['video/x-m4v', 'video/mp4'],
        'webm' => ['video/webm'],
        'mkv' => ['video/x-matroska', 'application/x-matroska'],
    ];
    private const DANGEROUS_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'html',
        'htm',
        'svg',
        'js',
        'exe',
        'sh',
        'bat',
        'cmd',
        'com',
        'msi',
    ];

    private readonly Closure $mover;
    private readonly VideoStorage $storage;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly array $config,
        ?callable $mover = null,
    ) {
        $this->mover = Closure::fromCallable($mover ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to));
        $this->storage = new VideoStorage($config);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return $this->videos->listForUser($userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $videoId): ?array
    {
        return $this->videos->findByIdForUser($userId, $this->positiveId($videoId, 'id'));
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function upload(int $userId, array $file): array
    {
        $this->storage->ensureUploadsDirectory();
        $upload = $this->validatedUpload($file);
        $storedName = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
        $destination = $this->storage->destinationForStoredName($storedName);
        $relativePath = $this->storage->relativePathForStoredName($storedName);

        if (!$this->storage->isUploadDestinationSafe($destination)) {
            throw new VideoValidationException('No se pudo completar la subida.');
        }

        $mover = $this->mover;

        if (!$mover($upload['tmp_name'], $destination)) {
            throw new VideoValidationException('No se pudo completar la subida.');
        }

        try {
            $videoId = $this->videos->create($userId, [
                'original_name' => $upload['original_name'],
                'stored_name' => $storedName,
                'storage_path' => $relativePath,
                'extension' => $upload['extension'],
                'mime_type' => $upload['mime_type'],
                'size_bytes' => $upload['size_bytes'],
                'status' => self::STATUS_PENDING_METADATA,
                'metadata_status' => 'pending',
            ]);
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                unlink($destination);
            }

            throw $exception;
        }

        return $this->videos->findByIdForUser($userId, $videoId) ?? [];
    }

    public function delete(int $userId, int $videoId): bool
    {
        $video = $this->videos->findByIdForUser($userId, $this->positiveId($videoId, 'id'));

        if ($video === null) {
            return false;
        }

        $path = $this->storage->pathForStoredVideo($video);

        if ($path !== null && is_file($path)) {
            if (!unlink($path)) {
                throw new VideoValidationException('No se pudo eliminar el archivo de video.');
            }

            clearstatcache(true, $path);

            if (is_file($path)) {
                throw new VideoValidationException('No se pudo eliminar el archivo de video.');
            }
        }

        return $this->videos->delete($userId, (int) $video['id']);
    }

    public function maxUploadMb(): int
    {
        $value = (int) ($this->config['max_upload_mb'] ?? self::DEFAULT_MAX_UPLOAD_MB);

        return $value > 0 ? $value : self::DEFAULT_MAX_UPLOAD_MB;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function retryMetadata(int $userId, int $videoId): ?array
    {
        $videoId = $this->positiveId($videoId, 'id');

        if (!$this->videos->retryMetadata($userId, $videoId)) {
            return null;
        }

        return $this->videos->findByIdForUser($userId, $videoId);
    }

    /**
     * @param array<string, mixed> $file
     * @return array{original_name: string, extension: string, mime_type: string, size_bytes: int, tmp_name: string}
     */
    private function validatedUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new VideoValidationException($this->uploadErrorMessage($error));
        }

        $tmpName = is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';

        if ($tmpName === '' || !is_file($tmpName)) {
            throw new VideoValidationException('No se pudo completar la subida.');
        }

        $originalName = $this->originalName($file['name'] ?? '');
        $extension = $this->extension($originalName);
        $sizeBytes = $this->sizeBytes($file['size'] ?? null, $tmpName);
        $maxBytes = $this->maxUploadMb() * 1024 * 1024;

        if ($sizeBytes <= 0) {
            throw new VideoValidationException('El archivo no parece ser un video valido.');
        }

        if ($sizeBytes > $maxBytes) {
            throw new VideoValidationException('El archivo supera el limite de ' . $this->maxUploadLabel() . '.');
        }

        $mimeType = $this->mimeType($tmpName);

        if (!$this->isMimeAllowedForExtension($extension, $mimeType)) {
            throw new VideoValidationException(str_starts_with($mimeType, 'video/')
                ? 'Formato de video no permitido.'
                : 'El archivo no parece ser un video valido.');
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'tmp_name' => $tmpName,
        ];
    }

    private function originalName(mixed $value): string
    {
        $name = str_replace('\\', '/', (string) $value);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new VideoValidationException('Nombre de archivo invalido.');
        }

        if (strlen($name) > self::ORIGINAL_NAME_MAX_LENGTH) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $available = self::ORIGINAL_NAME_MAX_LENGTH - ($extension === '' ? 0 : strlen($extension) + 1);
            $name = substr($base, 0, max(1, $available)) . ($extension === '' ? '' : '.' . $extension);
        }

        return $name;
    }

    private function extension(string $name): string
    {
        $parts = explode('.', strtolower($name));

        if (count($parts) < 2) {
            throw new VideoValidationException('Formato de video no permitido.');
        }

        $extension = array_pop($parts);

        if (!array_key_exists($extension, self::ALLOWED_MIME_TYPES)) {
            throw new VideoValidationException('Formato de video no permitido.');
        }

        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new VideoValidationException('Formato de video no permitido.');
            }
        }

        return $extension;
    }

    private function sizeBytes(mixed $reportedSize, string $tmpName): int
    {
        $reported = is_int($reportedSize) || (is_string($reportedSize) && preg_match('/\A[0-9]+\z/', $reportedSize) === 1)
            ? (int) $reportedSize
            : 0;
        $actual = filesize($tmpName);

        return max($reported, is_int($actual) ? $actual : 0);
    }

    private function mimeType(string $tmpName): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    private function isMimeAllowedForExtension(string $extension, string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES[$extension] ?? [], true);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el limite de ' . $this->maxUploadLabel() . '.',
            UPLOAD_ERR_PARTIAL => 'No se pudo completar la subida.',
            UPLOAD_ERR_NO_FILE => 'Selecciona un video para subir.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'No se pudo completar la subida.',
            default => 'No se pudo completar la subida.',
        };
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new VideoValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }

    private function maxUploadLabel(): string
    {
        $mb = $this->maxUploadMb();

        if ($mb >= 1024 && $mb % 1024 === 0) {
            return (int) ($mb / 1024) . ' GB';
        }

        return $mb . ' MB';
    }
}
