<?php
declare(strict_types=1);

use Modules\Video\WhisperService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$video = is_array($config['video'] ?? null) ? $config['video'] : [];
$service = new WhisperService($video);
$model = strtolower(trim((string) ($argv[1] ?? 'base')));
$models = [
    'tiny' => 'bd577a113a864445d4c299885e0cb97d4ba92b5f',
    'base' => '465707469ff3a37a2b9b8d8f89f2f99de7299dac',
    'small' => '55356645c2b361a969dfd0ef2c5a50d530afd8d5',
    'medium' => 'fd9727b6e1217c2f614f9b698455c4ffd82463b4',
];

if (!array_key_exists($model, $models)) {
    fwrite(STDERR, "Modelo no permitido. Usa: tiny, base, small o medium.\n");
    exit(2);
}

$service->ensureModelsDirectory();
$directory = realpath($service->modelsDirectory());

if (!is_string($directory) || !is_dir($directory) || !is_writable($directory)) {
    fwrite(STDERR, "No se puede escribir en storage/models/whisper.\n");
    exit(1);
}

$filename = 'ggml-' . $model . '.bin';
$target = $directory . DIRECTORY_SEPARATOR . $filename;
$expectedSha1 = $models[$model];

if (is_file($target)) {
    if (sha1_file($target) === $expectedSha1) {
        echo "Model: already installed at {$target}\n";
        exit(0);
    }

    fwrite(STDERR, "Existe un modelo {$filename}, pero su SHA1 no coincide.\n");
    exit(1);
}

$partial = $target . '.partial';
$url = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/' . $filename;

if (is_file($partial)) {
    unlink($partial);
}

$result = run_process([
    '/usr/bin/curl',
    '-fL',
    '--retry',
    '3',
    '--connect-timeout',
    '20',
    '-o',
    $partial,
    $url,
]);

if ($result['exit_code'] !== 0) {
    if (is_file($partial)) {
        unlink($partial);
    }

    fwrite(STDERR, "No se pudo descargar el modelo Whisper. Revisa la conexion a Internet.\n");
    exit(1);
}

if (!is_file($partial) || filesize($partial) === 0 || sha1_file($partial) !== $expectedSha1) {
    if (is_file($partial)) {
        unlink($partial);
    }

    fwrite(STDERR, "La descarga del modelo Whisper no paso la verificacion SHA1.\n");
    exit(1);
}

if (!rename($partial, $target)) {
    if (is_file($partial)) {
        unlink($partial);
    }

    fwrite(STDERR, "No se pudo guardar el modelo Whisper.\n");
    exit(1);
}

echo "Model: installed {$model} at {$target}\n";
exit(0);

/**
 * @param array<int, string> $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_process(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar curl.');
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
