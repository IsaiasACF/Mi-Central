<?php
declare(strict_types=1);

use Modules\Video\WhisperTranscriptParser;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$parser = new WhisperTranscriptParser();

function video_transcription_parser_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $json = json_encode([
        'transcription' => [
            [
                'timestamps' => [
                    'from' => '00:00:00.000',
                    'to' => '00:00:03.200',
                ],
                'text' => '  Hola   mundo  ',
            ],
            [
                'offsets' => [
                    'from' => 3500,
                    'to' => 7125,
                ],
                'text' => 'Transcripcion en espanol con acentos: cancion, universidad.',
            ],
            [
                'timestamps' => [
                    'from' => '00:01:10,500',
                    'to' => '00:01:13,750',
                ],
                'text' => 'English segment with decimal timestamps.',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    video_transcription_parser_assert(is_string($json), 'Could not create parser fixture.');

    $segments = $parser->parseJson($json);

    video_transcription_parser_assert(count($segments) === 3, 'Parser did not return all valid segments.');
    video_transcription_parser_assert($segments[0] === [
        'start_seconds' => 0.0,
        'end_seconds' => 3.2,
        'text' => 'Hola mundo',
    ], 'Parser did not normalize first segment.');
    video_transcription_parser_assert(abs($segments[1]['start_seconds'] - 3.5) < 0.001 && abs($segments[1]['end_seconds'] - 7.125) < 0.001, 'Parser did not convert offset milliseconds.');
    video_transcription_parser_assert(str_contains($segments[1]['text'], 'acentos'), 'Parser did not preserve UTF-8 text.');
    video_transcription_parser_assert(abs($segments[2]['start_seconds'] - 70.5) < 0.001 && abs($segments[2]['end_seconds'] - 73.75) < 0.001, 'Parser did not parse comma decimal timestamps.');

    try {
        $parser->parseJson('not-json');
        video_transcription_parser_assert(false, 'Parser accepted invalid JSON.');
    } catch (RuntimeException) {
        // Expected.
    }

    echo "Video transcription parser: OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Video transcription parser: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
