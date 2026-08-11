<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\VideoMetadataService;
use Modules\Video\VideoRepository;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$repository = new VideoRepository($pdo);
$username = 'test_vmtest_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$createdFiles = [];
$exitCode = 1;

function vmtest_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function vmtest_column_exists(PDO $pdo, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $statement->execute([
        'table' => 'video_files',
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function vmtest_config(array $videoConfig, string $key, string $fallback): string
{
    $value = $videoConfig[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $fallback;
}

function vmtest_exec(string $command): array
{
    $output = [];
    $code = 1;
    exec($command . ' 2>&1', $output, $code);

    return [
        'code' => $code,
        'output' => implode("\n", $output),
    ];
}

function vmtest_stored_name(string $extension = 'mp4'): string
{
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

function vmtest_path(array $videoConfig, string $storedName): string
{
    return rtrim(vmtest_config($videoConfig, 'uploads_path', dirname(__DIR__, 2) . '/storage/video/uploads'), '/') . '/' . $storedName;
}

function vmtest_create_video(VideoRepository $repository, int $userId, array $videoConfig, string $originalName, string $contents = ''): array
{
    $storedName = vmtest_stored_name('mp4');
    $path = vmtest_path($videoConfig, $storedName);

    if ($contents !== '') {
        file_put_contents($path, $contents);
    }

    $id = $repository->create($userId, [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => is_file($path) ? filesize($path) : 0,
        'status' => 'pending_metadata',
    ]);

    return [$id, $path];
}

function vmtest_worker_run(): array
{
    $basePath = dirname(__DIR__, 2);

    return vmtest_exec('cd ' . escapeshellarg($basePath) . ' && php workers/process-video-metadata.php');
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080009_create_video_files.php',
        '202608080010_add_video_file_metadata.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    foreach (['duration_seconds', 'width', 'height', 'fps', 'video_codec', 'audio_codec', 'container_format', 'bitrate', 'metadata_status', 'metadata_error', 'analyzed_at'] as $column) {
        vmtest_assert(vmtest_column_exists($pdo, $column), 'Missing metadata column: ' . $column);
    }

    $parser = new VideoMetadataService($videoConfig);
    $parsed = $parser->metadataFromJson(json_encode([
        'format' => [
            'duration' => '277.42',
            'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
            'bit_rate' => '1234567',
        ],
        'streams' => [
            [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'avg_frame_rate' => '30000/1001',
            ],
            [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    vmtest_assert(abs((float) $parsed['duration_seconds'] - 277.42) < 0.01, 'FFprobe parser did not keep duration.');
    vmtest_assert((int) $parsed['width'] === 1920 && (int) $parsed['height'] === 1080, 'FFprobe parser did not keep resolution.');
    vmtest_assert(abs((float) $parsed['fps'] - 29.97) < 0.01, 'FFprobe parser did not parse fractional FPS.');
    vmtest_assert($parsed['video_codec'] === 'h264' && $parsed['audio_codec'] === 'aac', 'FFprobe parser did not keep codecs.');
    vmtest_assert(str_contains((string) $parsed['container_format'], 'mp4'), 'FFprobe parser did not keep container format.');

    $userId = $auth->createUser($username, $password);
    $ffmpeg = vmtest_config($videoConfig, 'ffmpeg_bin', '/usr/bin/ffmpeg');
    $validStoredName = vmtest_stored_name('mp4');
    $validPath = vmtest_path($videoConfig, $validStoredName);
    $createdFiles[] = $validPath;
    $generate = vmtest_exec(
        escapeshellarg($ffmpeg)
        . ' -y -hide_banner -loglevel error'
        . ' -f lavfi -i ' . escapeshellarg('testsrc=size=160x90:rate=30000/1001')
        . ' -f lavfi -i ' . escapeshellarg('anullsrc=channel_layout=stereo:sample_rate=44100')
        . ' -t 2 -shortest -c:v mpeg4 -c:a aac -pix_fmt yuv420p '
        . escapeshellarg($validPath)
    );
    vmtest_assert($generate['code'] === 0 && is_file($validPath), 'Could not generate FFmpeg test video: ' . $generate['output']);

    $validId = $repository->create($userId, [
        'original_name' => 'metadata-ready.mp4',
        'stored_name' => $validStoredName,
        'storage_path' => 'storage/video/uploads/' . $validStoredName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($validPath),
        'status' => 'pending_metadata',
    ]);

    [$invalidId, $invalidPath] = vmtest_create_video($repository, $userId, $videoConfig, 'metadata-invalid.mp4', 'not a valid video');
    $createdFiles[] = $invalidPath;

    $readyStoredName = vmtest_stored_name('mp4');
    $readyPath = vmtest_path($videoConfig, $readyStoredName);
    file_put_contents($readyPath, 'already ready');
    $createdFiles[] = $readyPath;
    $readyId = $repository->create($userId, [
        'original_name' => 'metadata-already-ready.mp4',
        'stored_name' => $readyStoredName,
        'storage_path' => 'storage/video/uploads/' . $readyStoredName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($readyPath),
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);
    $pdo->prepare("UPDATE video_files SET metadata_status = 'ready', duration_seconds = 9, analyzed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $readyId]);

    $legacyStoredName = vmtest_stored_name('mp4');
    $legacyPath = vmtest_path($videoConfig, $legacyStoredName);
    copy($validPath, $legacyPath);
    $createdFiles[] = $legacyPath;
    $pdo->prepare(
        'INSERT INTO video_files (user_id, original_name, stored_name, storage_path, extension, mime_type, size_bytes, status)
         VALUES (:user_id, :original_name, :stored_name, :storage_path, :extension, :mime_type, :size_bytes, :status)'
    )->execute([
        'user_id' => $userId,
        'original_name' => 'metadata-legacy.mp4',
        'stored_name' => $legacyStoredName,
        'storage_path' => 'storage/video/uploads/' . $legacyStoredName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($legacyPath),
        'status' => 'pending_metadata',
    ]);
    $legacyId = (int) $pdo->lastInsertId();

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $run = vmtest_worker_run();
        vmtest_assert($run['code'] === 0 && str_contains($run['output'], 'Processed:'), 'Video metadata worker failed: ' . $run['output']);

        $valid = $repository->findByIdForUser($userId, $validId);
        $invalid = $repository->findByIdForUser($userId, $invalidId);
        $legacy = $repository->findByIdForUser($userId, $legacyId);

        if (($valid['metadata_status'] ?? '') === 'ready' && ($invalid['metadata_status'] ?? '') === 'failed' && ($legacy['metadata_status'] ?? '') === 'ready') {
            break;
        }
    }

    $valid = $repository->findByIdForUser($userId, $validId);
    $invalid = $repository->findByIdForUser($userId, $invalidId);
    $ready = $repository->findByIdForUser($userId, $readyId);
    $legacy = $repository->findByIdForUser($userId, $legacyId);

    vmtest_assert(($valid['metadata_status'] ?? '') === 'ready', 'Pending video did not become ready.');
    vmtest_assert((float) ($valid['duration_seconds'] ?? 0) > 0, 'Ready video did not store duration.');
    vmtest_assert((int) ($valid['width'] ?? 0) === 160 && (int) ($valid['height'] ?? 0) === 90, 'Ready video did not store resolution.');
    vmtest_assert((float) ($valid['fps'] ?? 0) > 29 && (float) ($valid['fps'] ?? 0) < 30, 'Ready video did not store fractional FPS.');
    vmtest_assert(($valid['video_codec'] ?? '') !== '' && ($valid['audio_codec'] ?? '') !== '', 'Ready video did not store codecs.');
    vmtest_assert(($valid['container_format'] ?? '') !== '', 'Ready video did not store container.');
    vmtest_assert(($invalid['metadata_status'] ?? '') === 'failed' && is_file($invalidPath), 'Invalid video did not fail while keeping original.');
    vmtest_assert(($ready['metadata_status'] ?? '') === 'ready' && (float) ($ready['duration_seconds'] ?? 0) === 9.0, 'Ready video was reprocessed.');
    vmtest_assert(($legacy['metadata_status'] ?? '') === 'ready', 'Video inserted without metadata_status was not analyzable.');

    $crontab = file_get_contents(dirname(__DIR__, 2) . '/docker/worker/crontab');
    vmtest_assert(is_string($crontab) && str_contains($crontab, 'process-reminders.php') && str_contains($crontab, 'process-video-metadata.php') && str_contains($crontab, 'process-video-exports.php') && str_contains($crontab, 'cleanup-video-files.php'), 'Worker crontab did not keep video workers, cleanup, and reminders.');

    $loadedCrontab = vmtest_exec('crontab -l');
    vmtest_assert($loadedCrontab['code'] === 0 && str_contains($loadedCrontab['output'], 'process-reminders.php') && str_contains($loadedCrontab['output'], 'process-video-metadata.php') && str_contains($loadedCrontab['output'], 'process-video-exports.php') && str_contains($loadedCrontab['output'], 'cleanup-video-files.php'), 'Loaded worker crontab does not include video workers, cleanup, and reminders.');

    echo "Video metadata: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video metadata: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($createdFiles as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $statement->execute(['username' => $username]);
    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username');
    $statement->execute(['username' => $username]);
}

exit($exitCode);
