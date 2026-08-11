<?php
declare(strict_types=1);

use Modules\Video\WhisperService;
use Modules\Video\WhisperTranscriptParser;
use Modules\Video\VideoStorage;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$video = is_array($config['video'] ?? null) ? $config['video'] : [];
$service = new WhisperService($video, new VideoStorage($video));
$parser = new WhisperTranscriptParser();
$created = [];
$generatedAudio = null;
$exitCode = 1;

function video_transcription_infra_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $basePath = dirname(__DIR__, 2);
    $publicPath = realpath($basePath . '/public');
    $modelsPath = $service->modelsDirectory();
    $tempPath = is_string($video['temp_path'] ?? null) ? (string) $video['temp_path'] : $basePath . '/storage/video/temp';

    $service->ensureModelsDirectory();

    video_transcription_infra_assert(is_dir($modelsPath), 'Whisper models directory was not created.');
    video_transcription_infra_assert(!str_starts_with(realpath($modelsPath) ?: $modelsPath, (string) $publicPath), 'Whisper models are exposed under public.');
    video_transcription_infra_assert(is_dir($tempPath) && is_writable($tempPath), 'Video temp directory is not writable.');

    $audioPath = $service->safeTempAudioPath(123, '0123456789abcdef');
    video_transcription_infra_assert(str_starts_with($audioPath, realpath($tempPath) . DIRECTORY_SEPARATOR), 'Safe temp audio path is outside storage/video/temp.');

    $badAudioRejected = false;

    try {
        $service->safeTempAudioPath(123, '../not-safe');
    } catch (RuntimeException) {
        $badAudioRejected = true;
    }

    video_transcription_infra_assert($badAudioRejected, 'Unsafe temp audio token was accepted.');

    $fakeAudio = $audioPath;
    file_put_contents($fakeAudio, 'RIFF');
    $created[] = $fakeAudio;

    $fakeModel = realpath($modelsPath) . DIRECTORY_SEPARATOR . 'ggml-test.bin';
    file_put_contents($fakeModel, 'fake-model-for-command-validation');
    $created[] = $fakeModel;

    $testService = new WhisperService(array_merge($video, [
        'whisper_bin' => '/bin/echo',
        'whisper_model_path' => $fakeModel,
    ]), new VideoStorage($video));
    $outputBase = realpath($tempPath) . DIRECTORY_SEPARATOR . 'transcription_test_' . bin2hex(random_bytes(6));
    $command = $testService->buildTranscriptionCommand($fakeAudio, $outputBase, 'es');

    video_transcription_infra_assert($command[0] === '/bin/echo' && in_array('-oj', $command, true) && in_array('-ng', $command, true), 'Whisper command does not use expected safe arguments.');
    video_transcription_infra_assert(!in_array('; touch injected', $command, true), 'Whisper command accepted shell content.');

    $invalidLanguageRejected = false;

    try {
        $testService->buildTranscriptionCommand($fakeAudio, $outputBase, 'es; touch injected');
    } catch (RuntimeException) {
        $invalidLanguageRejected = true;
    }

    video_transcription_infra_assert($invalidLanguageRejected, 'Invalid transcription language was accepted.');

    $fixture = json_encode([
        'transcription' => [
            [
                'timestamps' => [
                    'from' => '00:00:00.000',
                    'to' => '00:00:01.000',
                ],
                'text' => 'Prueba local',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    video_transcription_infra_assert(is_string($fixture) && $parser->parseJson($fixture)[0]['text'] === 'Prueba local', 'Parser fixture did not produce structured segments.');

    $serviceSource = file_get_contents($basePath . '/modules/Video/WhisperService.php');
    $installerSource = file_get_contents($basePath . '/workers/install-whisper-model.php');
    video_transcription_infra_assert(is_string($serviceSource) && str_contains($serviceSource, 'proc_open($command') && !str_contains($serviceSource, 'shell_exec') && !str_contains($serviceSource, 'system('), 'WhisperService does not use controlled process execution.');
    video_transcription_infra_assert(is_string($installerSource) && str_contains($installerSource, 'array_key_exists($model, $models)') && !str_contains($installerSource, 'shell_exec') && !str_contains($installerSource, 'system('), 'Whisper model installer does not restrict model input.');

    if ($service->binaryIsExecutable($service->ffmpegBinary())) {
        $generatedAudio = realpath($tempPath) . DIRECTORY_SEPARATOR . 'transcription_ffmpeg_' . bin2hex(random_bytes(6)) . '.wav';
        $result = $service->runProcess([
            $service->ffmpegBinary(),
            '-hide_banner',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=16000:cl=mono',
            '-t',
            '0.2',
            '-c:a',
            'pcm_s16le',
            $generatedAudio,
        ]);
        $created[] = $generatedAudio;
        video_transcription_infra_assert($result['exit_code'] === 0 && is_file($generatedAudio), 'FFmpeg did not generate a small technical WAV fixture.');
    }

    if ($service->binaryIsExecutable($service->whisperBinary()) && $service->modelIsInstalled()) {
        $jsonBase = realpath($tempPath) . DIRECTORY_SEPARATOR . 'transcription_whisper_' . bin2hex(random_bytes(6));
        $jsonPath = $jsonBase . '.json';
        $created[] = $jsonPath;

        if (is_string($generatedAudio) && is_file($generatedAudio)) {
            $json = $service->transcribeAudioToJson($generatedAudio, $jsonBase, 'auto');
            $parser->parseJson($json);
        }
    }

    $workerCrontab = file_get_contents($basePath . '/docker/worker/crontab');
    video_transcription_infra_assert(is_string($workerCrontab) && str_contains($workerCrontab, 'process-video-transcriptions.php'), 'Transcription worker was not added to cron.');
    video_transcription_infra_assert(is_string($workerCrontab) && str_contains($workerCrontab, 'process-reminders.php') && str_contains($workerCrontab, 'process-video-metadata.php') && str_contains($workerCrontab, 'process-video-exports.php') && str_contains($workerCrontab, 'cleanup-video-files.php'), 'Existing worker cron entries were changed.');

    echo "Video transcription infrastructure: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video transcription infrastructure: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
} finally {
    foreach ($created as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}

exit($exitCode);
