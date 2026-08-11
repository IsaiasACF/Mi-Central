<?php
declare(strict_types=1);

namespace Modules\Video;

use RuntimeException;

final class WhisperService
{
    private const LANGUAGES = ['auto', 'es', 'en'];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?VideoStorage $storage = null,
    ) {
    }

    public function whisperBinary(): string
    {
        return $this->stringConfig('whisper_bin', '/usr/local/bin/whisper-cli');
    }

    public function ffmpegBinary(): string
    {
        return $this->stringConfig('ffmpeg_bin', '/usr/bin/ffmpeg');
    }

    public function ffprobeBinary(): string
    {
        return $this->stringConfig('ffprobe_bin', '/usr/bin/ffprobe');
    }

    public function modelsDirectory(): string
    {
        return rtrim($this->stringConfig('whisper_models_path', dirname(__DIR__, 2) . '/storage/models/whisper'), DIRECTORY_SEPARATOR);
    }

    public function configuredModelPath(): string
    {
        return $this->stringConfig('whisper_model_path', $this->modelsDirectory() . '/ggml-base.bin');
    }

    public function configuredModelName(): string
    {
        $basename = basename($this->configuredModelPath());

        if (preg_match('/\Aggml-([A-Za-z0-9._-]+)\.bin\z/', $basename, $matches) === 1) {
            return substr($matches[1], 0, 64);
        }

        return substr($basename !== '' ? $basename : 'configured', 0, 64);
    }

    public function ensureModelsDirectory(): void
    {
        $directory = $this->modelsDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el directorio de modelos Whisper.');
        }
    }

    public function binaryIsExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path) && $this->runProcess([$path, '--help'])['exit_code'] === 0;
    }

    public function modelIsInstalled(): bool
    {
        $model = realpath($this->configuredModelPath());

        return is_string($model)
            && is_file($model)
            && is_readable($model)
            && $this->pathIsInside($model, $this->modelsDirectory());
    }

    public function modelsDirectoryIsAccessible(): bool
    {
        $this->ensureModelsDirectory();

        return is_dir($this->modelsDirectory()) && is_readable($this->modelsDirectory());
    }

    public function tempDirectoryIsWritable(): bool
    {
        $directory = $this->storage()->tempDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        if (!is_writable($directory)) {
            return false;
        }

        $probe = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.transcription-write-test-' . bin2hex(random_bytes(6));

        if (file_put_contents($probe, 'ok') === false) {
            return false;
        }

        unlink($probe);

        return true;
    }

    public function safeTempAudioPath(int $sourceId, string $token): string
    {
        if ($sourceId <= 0 || preg_match('/\A[0-9a-f]{16}\z/', $token) !== 1) {
            throw new RuntimeException('Ruta temporal de audio invalida.');
        }

        $directory = realpath($this->storage()->tempDirectory());

        if (!is_string($directory)) {
            throw new RuntimeException('Directorio temporal de video no disponible.');
        }

        return $directory . DIRECTORY_SEPARATOR . 'transcription_' . $sourceId . '_' . $token . '.wav';
    }

    public function safeTempOutputBasePath(int $sourceId, string $token): string
    {
        if ($sourceId <= 0 || preg_match('/\A[0-9a-f]{16}\z/', $token) !== 1) {
            throw new RuntimeException('Ruta temporal de salida Whisper invalida.');
        }

        $directory = realpath($this->storage()->tempDirectory());

        if (!is_string($directory)) {
            throw new RuntimeException('Directorio temporal de video no disponible.');
        }

        return $directory . DIRECTORY_SEPARATOR . 'transcription_' . $sourceId . '_' . $token;
    }

    public function buildAudioExtractionCommand(string $videoPath, string $audioPath): array
    {
        $input = realpath($videoPath);

        if (!is_string($input) || !is_file($input)) {
            throw new RuntimeException('Video de entrada no disponible para transcripcion.');
        }

        $this->assertWritablePathInside($audioPath, $this->storage()->tempDirectory());

        return [
            $this->ffmpegBinary(),
            '-hide_banner',
            '-y',
            '-i',
            $input,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $audioPath,
        ];
    }

    public function extractAudioToWav(string $videoPath, string $audioPath): void
    {
        $result = $this->runProcess($this->buildAudioExtractionCommand($videoPath, $audioPath));

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('FFmpeg no pudo preparar audio para transcripcion.');
        }
    }

    public function buildTranscriptionCommand(string $audioPath, string $outputBasePath, string $language = 'auto'): array
    {
        $language = $this->normalizeLanguage($language);
        $audio = realpath($audioPath);

        if (!is_string($audio) || !is_file($audio) || !$this->pathIsInside($audio, $this->storage()->tempDirectory())) {
            throw new RuntimeException('Audio temporal de transcripcion invalido.');
        }

        $model = realpath($this->configuredModelPath());

        if (!is_string($model) || !is_file($model) || !$this->pathIsInside($model, $this->modelsDirectory())) {
            throw new RuntimeException('Modelo Whisper no instalado o fuera del directorio permitido.');
        }

        $this->assertWritablePathInside($outputBasePath . '.json', $this->storage()->tempDirectory());

        return [
            $this->whisperBinary(),
            '-m',
            $model,
            '-f',
            $audio,
            '-l',
            $language,
            '-oj',
            '-of',
            $outputBasePath,
            '-np',
            '-ng',
        ];
    }

    public function transcribeAudioToJson(string $audioPath, string $outputBasePath, string $language = 'auto'): string
    {
        $result = $this->runProcess($this->buildTranscriptionCommand($audioPath, $outputBasePath, $language));

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Whisper no pudo transcribir el audio.');
        }

        $jsonPath = $outputBasePath . '.json';
        $json = is_file($jsonPath) ? file_get_contents($jsonPath) : false;

        if (!is_string($json) || trim($json) === '') {
            throw new RuntimeException('Whisper no genero salida JSON.');
        }

        return $json;
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function runProcess(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso de transcripcion.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));

        if (!in_array($language, self::LANGUAGES, true)) {
            throw new RuntimeException('Idioma de transcripcion no permitido.');
        }

        return $language;
    }

    private function assertWritablePathInside(string $path, string $directory): void
    {
        $base = realpath($directory);
        $parent = realpath(dirname($path));

        if (!is_string($base) || !is_string($parent) || $parent !== $base) {
            throw new RuntimeException('Ruta temporal de transcripcion fuera del directorio permitido.');
        }
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $base = realpath($directory);
        $real = realpath($path);

        return is_string($base)
            && is_string($real)
            && ($real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR));
    }

    private function storage(): VideoStorage
    {
        return $this->storage ?? new VideoStorage($this->config);
    }

    private function stringConfig(string $key, string $fallback): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
