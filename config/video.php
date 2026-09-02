<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);
$env = static function (string $key, string $default): string {
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

return [
    'ffmpeg_bin' => $env('FFMPEG_BIN', '/usr/bin/ffmpeg'),
    'ffprobe_bin' => $env('FFPROBE_BIN', '/usr/bin/ffprobe'),
    'max_upload_mb' => (int) $env('VIDEO_MAX_UPLOAD_MB', '1024'),
    'export_retention_days' => max(1, (int) $env('VIDEO_EXPORT_RETENTION_DAYS', '30')),
    'storage_path' => $basePath . '/storage/video',
    'uploads_path' => $basePath . '/storage/video/uploads',
    'jobs_path' => $basePath . '/storage/video/jobs',
    'exports_path' => $basePath . '/storage/video/exports',
    'temp_path' => $basePath . '/storage/video/temp',
];
