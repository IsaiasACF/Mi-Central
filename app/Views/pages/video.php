<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\DateTimeHelper;
use App\Support\View;

$videos = is_array($videos ?? null) ? $videos : [];
$selectedVideo = is_array($selectedVideo ?? null) ? $selectedVideo : null;
$videoNotFound = (bool) ($videoNotFound ?? false);
$videoMode = is_string($videoMode ?? null) ? $videoMode : 'list';
$activeVideoTab = $videoMode === 'processed' ? 'processings' : 'editor';
$videoCutPoints = is_array($videoCutPoints ?? null) ? $videoCutPoints : [];
$videoSegments = is_array($videoSegments ?? null) ? $videoSegments : [];
$videoSegmentSummary = is_array($videoSegmentSummary ?? null) ? $videoSegmentSummary : null;
$videoExportJobs = is_array($videoExportJobs ?? null) ? $videoExportJobs : [];
$videoProcessedExports = is_array($videoProcessedExports ?? null) ? $videoProcessedExports : [];
$videoEditorError = is_string($videoEditorError ?? null) ? $videoEditorError : null;
$videoFlash = is_string($_GET['video_message'] ?? null) ? (string) $_GET['video_message'] : '';
$videoConfig = is_array($videoConfig ?? null) ? $videoConfig : [];
$maxUploadMb = (int) ($videoConfig['max_upload_mb'] ?? 1024);
$maxUploadMb = $maxUploadMb > 0 ? $maxUploadMb : 1024;
$maxUploadLabel = $maxUploadMb >= 1024 && $maxUploadMb % 1024 === 0
    ? (int) ($maxUploadMb / 1024) . ' GB'
    : $maxUploadMb . ' MB';

$sizeLabel = static function (mixed $bytes): string {
    $bytes = max(0, (int) $bytes);
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return ($unit === 0 ? (string) $bytes : rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.')) . ' ' . $units[$unit];
};

$durationLabel = static function (mixed $seconds): string {
    if ($seconds === null || $seconds === '') {
        return 'Pendiente';
    }

    $total = max(0, (int) round((float) $seconds));
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $remainingSeconds = $total % 60;

    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
        : sprintf('%02d:%02d', $minutes, $remainingSeconds);
};

$durationPreciseLabel = static function (mixed $seconds): string {
    if ($seconds === null || $seconds === '') {
        return '00:00:00.000';
    }

    $milliseconds = max(0, (int) round((float) $seconds * 1000));
    $hours = intdiv($milliseconds, 3600000);
    $minutes = intdiv($milliseconds % 3600000, 60000);
    $remainingSeconds = intdiv($milliseconds % 60000, 1000);
    $remainingMilliseconds = $milliseconds % 1000;

    return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $remainingSeconds, $remainingMilliseconds);
};

$durationShortPreciseLabel = static function (mixed $seconds) use ($durationPreciseLabel): string {
    return preg_replace('/^00:/', '', $durationPreciseLabel($seconds)) ?? $durationPreciseLabel($seconds);
};

$fpsLabel = static function (mixed $fps): string {
    if ($fps === null || $fps === '') {
        return 'FPS pendiente';
    }

    return rtrim(rtrim(number_format((float) $fps, 2, '.', ''), '0'), '.') . ' fps';
};

$codecLabel = static function (mixed $codec): string {
    return match (strtolower((string) $codec)) {
        'h264' => 'H.264',
        'hevc', 'h265' => 'H.265',
        'mpeg4' => 'MPEG-4',
        'vp8' => 'VP8',
        'vp9' => 'VP9',
        'av1' => 'AV1',
        'aac' => 'AAC',
        'mp3' => 'MP3',
        'opus' => 'Opus',
        'vorbis' => 'Vorbis',
        '' => 'Sin datos',
        default => strtoupper((string) $codec),
    };
};

$metadataStatusLabel = static function (mixed $status): string {
    return match ((string) $status) {
        'ready' => 'Listo',
        'failed' => 'No se pudo analizar el video.',
        default => 'Analizando video...',
    };
};

$exportStatusLabel = static function (mixed $status): string {
    return match ((string) $status) {
        'pending' => 'En cola',
        'processing' => 'Procesando',
        'completed' => 'Completado',
        'expired' => 'Expirado',
        'failed' => 'Fallido',
        default => 'En cola',
    };
};

$metadataIsLoading = static function (array $video): bool {
    return in_array((string) ($video['metadata_status'] ?? 'pending'), ['pending', 'processing'], true);
};

$metadataIsFailed = static function (array $video): bool {
    return (string) ($video['metadata_status'] ?? 'pending') === 'failed';
};

$metadataCards = static function (array $video) use ($durationLabel, $fpsLabel, $codecLabel, $sizeLabel): array {
    if (($video['metadata_status'] ?? 'pending') !== 'ready') {
        return [
            ['label' => 'Tamano', 'value' => $sizeLabel($video['size_bytes'] ?? 0)],
        ];
    }

    $cards = [
        ['label' => 'Duracion', 'value' => $durationLabel($video['duration_seconds'] ?? null)],
        [
            'label' => 'Resolucion',
            'value' => ($video['width'] ?? null) !== null && ($video['height'] ?? null) !== null
                ? (int) $video['width'] . ' x ' . (int) $video['height']
                : 'Sin datos',
        ],
        ['label' => 'FPS', 'value' => $fpsLabel($video['fps'] ?? null)],
        ['label' => 'Video', 'value' => $codecLabel($video['video_codec'] ?? null)],
        ['label' => 'Audio', 'value' => $codecLabel($video['audio_codec'] ?? null)],
        ['label' => 'Tamano', 'value' => $sizeLabel($video['size_bytes'] ?? 0)],
    ];

    if (($video['container_format'] ?? null) !== null && (string) $video['container_format'] !== '') {
        $cards[] = ['label' => 'Formato', 'value' => (string) $video['container_format']];
    }

    if (($video['bitrate'] ?? null) !== null) {
        $cards[] = ['label' => 'Bitrate', 'value' => $sizeLabel((int) $video['bitrate']) . '/s'];
    }

    return $cards;
};

$metadataSummary = static function (array $video) use ($durationLabel, $fpsLabel, $codecLabel): array {
    if (($video['metadata_status'] ?? 'pending') !== 'ready') {
        return [];
    }

    $resolution = ($video['width'] ?? null) !== null && ($video['height'] ?? null) !== null
        ? (int) $video['width'] . '&times;' . (int) $video['height']
        : 'Resolucion pendiente';

    return [
        $durationLabel($video['duration_seconds'] ?? null) . ' · ' . $resolution . ' · ' . $fpsLabel($video['fps'] ?? null),
        $codecLabel($video['video_codec'] ?? null) . ' / ' . $codecLabel($video['audio_codec'] ?? null),
    ];
};

$canPreview = static function (array $video): bool {
    $extension = strtolower((string) ($video['extension'] ?? ''));
    $metadataStatus = (string) ($video['metadata_status'] ?? 'pending');
    $videoCodec = strtolower((string) ($video['video_codec'] ?? ''));
    $audioCodec = strtolower((string) ($video['audio_codec'] ?? ''));

    if ($metadataStatus !== 'ready') {
        return in_array($extension, ['mp4', 'm4v', 'webm'], true);
    }

    if (in_array($extension, ['mp4', 'm4v'], true)) {
        return $videoCodec === 'h264' && in_array($audioCodec, ['', 'aac', 'mp3'], true);
    }

    if ($extension === 'webm') {
        return in_array($videoCodec, ['vp8', 'vp9', 'av1'], true) && in_array($audioCodec, ['', 'opus', 'vorbis'], true);
    }

    return false;
};

$dateLabel = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Sin fecha';
    }

    return substr($value, 0, 16);
};

$dateTimeLabel = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return '';
    }

    try {
        $date = DateTimeHelper::utcStorageToLocalDateTime($value, 'America/Santiago');
        $months = [
            1 => 'ene',
            2 => 'feb',
            3 => 'mar',
            4 => 'abr',
            5 => 'may',
            6 => 'jun',
            7 => 'jul',
            8 => 'ago',
            9 => 'sept',
            10 => 'oct',
            11 => 'nov',
            12 => 'dic',
        ];

        return $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y') . ' · ' . $date->format('H:i');
    } catch (Throwable) {
        return substr($value, 0, 16);
    }
};

$segmentSequenceLabel = static function (?array $summary): string {
    $sequence = is_array($summary['sequence'] ?? null) ? $summary['sequence'] : [];

    if ($sequence === []) {
        return 'Sin segmentos incluidos';
    }

    return implode(' -> ', array_map(static fn (mixed $value): string => (string) (int) $value, $sequence));
};
?>
<section
    class="video-page"
    aria-labelledby="page-title"
    data-video-page
    data-api-url="/api/video/files.php"
    data-video-exports-api-url="/api/video/exports.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
    data-max-upload-mb="<?= View::escape($maxUploadMb) ?>"
    <?php if ($selectedVideo !== null): ?>
        data-video-detail-id="<?= View::escape($selectedVideo['id'] ?? '') ?>"
        data-metadata-status="<?= View::escape($selectedVideo['metadata_status'] ?? 'pending') ?>"
    <?php endif; ?>
>
    <div class="page-heading tasks-heading">
        <div>
            <p class="eyebrow">Video</p>
            <h1 id="page-title">Video</h1>
            <p class="muted">Sube videos de forma segura para prepararlos para analisis y edicion local.</p>
        </div>
    </div>
    <nav class="quick-filters" aria-label="Secciones de Video">
        <a class="quick-filter <?= $activeVideoTab === 'editor' ? 'is-active' : '' ?>" href="/index.php?section=video" <?= $activeVideoTab === 'editor' ? 'aria-current="page"' : '' ?>>Editor</a>
        <a class="quick-filter <?= $activeVideoTab === 'processings' ? 'is-active' : '' ?>" href="/index.php?section=video&amp;tab=processings" <?= $activeVideoTab === 'processings' ? 'aria-current="page"' : '' ?>>Procesamientos</a>
    </nav>
    <?php if ($videoFlash === 'deleted'): ?>
        <p class="task-message task-message--success" role="status">Video eliminado correctamente.</p>
    <?php endif; ?>

    <?php if ($videoNotFound): ?>
        <section class="tasks-panel">
            <p class="task-message task-message--error">Video no encontrado.</p>
            <div class="task-actions task-actions--start">
                <a class="button button--secondary" href="/index.php?section=video">Volver</a>
            </div>
        </section>
    <?php elseif ($videoMode === 'processed'): ?>
        <section
            class="tasks-panel video-processed-panel"
            aria-labelledby="video-processed-title"
            data-video-processed-panel
            data-video-exports-list
        >
            <div class="tasks-list-header">
                <div>
                    <p class="eyebrow">Procesamientos</p>
                    <h2 id="video-processed-title">Procesamientos</h2>
                </div>
                <span class="task-count" data-video-processed-count><?= count($videoProcessedExports) ?></span>
            </div>

            <div class="video-export-list video-export-list--processed" data-video-processed-list>
                <?php if ($videoProcessedExports === []): ?>
                    <div class="video-processed-empty">
                        <p>Aun no has procesado videos.</p>
                        <p>Edita un video y exportalo para verlo aqui.</p>
                        <a class="button button--primary" href="/index.php?section=video">Ir al editor</a>
                    </div>
                <?php endif; ?>
                <?php foreach ($videoProcessedExports as $job): ?>
                    <?php
                    $jobStatus = (string) ($job['status'] ?? 'pending');
                    $displayStatus = (string) ($job['display_status'] ?? $jobStatus);
                    $progress = max(0.0, min(100.0, (float) ($job['progress_percent'] ?? ($jobStatus === 'completed' ? 100 : 0))));
                    $downloadable = (bool) ($job['is_downloadable'] ?? ($jobStatus === 'completed'));
                    ?>
                    <article class="video-export-item video-processed-item" data-export-job-id="<?= View::escape($job['id'] ?? '') ?>">
                        <div class="video-processed-item__main">
                            <strong><?= View::escape($job['output_name'] ?? 'Exportacion') ?></strong>
                            <?php if (($job['video_original_name'] ?? '') !== ''): ?>
                                <span>Original: <?= View::escape($job['video_original_name']) ?></span>
                            <?php endif; ?>
                            <div class="video-processed-meta">
                                <span class="video-export-status video-export-status--<?= View::escape($displayStatus) ?>"><?= View::escape($exportStatusLabel($displayStatus)) ?></span>
                                <?php if (($job['created_at'] ?? '') !== ''): ?>
                                    <span><?= View::escape($dateTimeLabel($job['created_at'])) ?></span>
                                <?php endif; ?>
                                <?php if (($job['output_duration_seconds'] ?? null) !== null): ?>
                                    <span><?= View::escape($durationLabel($job['output_duration_seconds'])) ?></span>
                                <?php endif; ?>
                                <?php if (($job['output_size_bytes'] ?? null) !== null): ?>
                                    <span><?= View::escape($sizeLabel($job['output_size_bytes'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($displayStatus === 'processing' || $displayStatus === 'pending'): ?>
                                <div class="video-export-progress" aria-label="Progreso <?= View::escape(number_format($progress, 0)) ?>%">
                                    <span style="width: <?= View::escape(number_format($progress, 2, '.', '')) ?>%"></span>
                                </div>
                                <small><?= $displayStatus === 'processing' ? 'Procesando...' : 'En cola' ?><?= $displayStatus === 'processing' ? ' ' . View::escape(number_format($progress, 0)) . '%' : '' ?></small>
                            <?php elseif ($displayStatus === 'failed'): ?>
                                <small>No se pudo completar la exportacion.</small>
                            <?php elseif ($displayStatus === 'expired'): ?>
                                <small>El archivo exportado ya expiro.</small>
                            <?php endif; ?>
                        </div>
                        <div class="task-actions">
                            <?php if ($jobStatus === 'completed' && $downloadable): ?>
                                <button class="button button--secondary" type="button" data-export-action="play">Reproducir</button>
                                <a class="button button--secondary" href="/video/export/download.php?id=<?= View::escape($job['id'] ?? '') ?>">Descargar</a>
                            <?php endif; ?>
                            <?php if ($jobStatus !== 'processing'): ?>
                                <button class="button button--danger" type="button" data-export-action="delete">Eliminar</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($jobStatus === 'completed' && $downloadable): ?>
                            <video class="video-player video-player--inline" controls preload="metadata" src="/video/export/stream.php?id=<?= View::escape($job['id'] ?? '') ?>" data-export-player hidden></video>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="task-message" role="status" aria-live="polite" data-video-message hidden></p>
        </section>
    <?php elseif ($selectedVideo !== null && $videoMode === 'editor'): ?>
        <section
            class="tasks-panel video-editor"
            aria-label="Editor de video"
            data-video-editor
            data-video-id="<?= View::escape($selectedVideo['id'] ?? '') ?>"
            data-duration-seconds="<?= View::escape($selectedVideo['duration_seconds'] ?? '') ?>"
            data-api-url="/api/video/cuts.php"
            data-segments-api-url="/api/video/segments.php"
            data-exports-api-url="/api/video/exports.php"
            data-stream-url="/video/stream.php?id=<?= View::escape($selectedVideo['id'] ?? '') ?>"
            data-csrf-token="<?= View::escape(Csrf::token()) ?>"
        >
            <div class="video-detail__header">
                <div>
                    <p class="eyebrow">Editor de video</p>
                    <h2><?= View::escape($selectedVideo['original_name'] ?? '') ?></h2>
                </div>
                <div class="task-actions">
                    <a class="button button--secondary" href="/index.php?section=video&amp;id=<?= View::escape($selectedVideo['id'] ?? '') ?>">Volver al video</a>
                </div>
            </div>

            <?php if ($videoEditorError !== null): ?>
                <div class="video-metadata-state" data-video-editor-blocked>
                    <strong><?= View::escape($videoEditorError) ?></strong>
                    <?php if (($selectedVideo['metadata_status'] ?? '') === 'failed'): ?>
                        <button class="button button--secondary" type="button" data-video-action="retry-metadata" data-video-id="<?= View::escape($selectedVideo['id'] ?? '') ?>">Reintentar analisis</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <video class="video-player" controls preload="metadata" src="/video/stream.php?id=<?= View::escape($selectedVideo['id'] ?? '') ?>" data-video-editor-player></video>

                <div class="video-editor__time" aria-live="polite">
                    <span data-video-current-time>00:00:00.000</span>
                    <span>/</span>
                    <span data-video-duration-time><?= View::escape($durationPreciseLabel($selectedVideo['duration_seconds'] ?? 0)) ?></span>
                </div>

                <div class="video-editor__controls" aria-label="Controles de reproduccion">
                    <button class="button button--secondary" type="button" data-video-editor-skip="-5">-5 s</button>
                    <button class="button button--primary" type="button" data-video-editor-play>Play</button>
                    <button class="button button--secondary" type="button" data-video-editor-skip="5">+5 s</button>
                    <button class="button button--secondary" type="button" data-video-editor-add-cut>Agregar corte</button>
                </div>

                <div class="video-editor__timeline-wrap">
                    <div class="video-editor__timeline" role="slider" tabindex="0" aria-label="Timeline del video" aria-valuemin="0" aria-valuemax="<?= View::escape($selectedVideo['duration_seconds'] ?? 0) ?>" aria-valuenow="0" data-video-timeline>
                        <div class="video-editor__ticks" data-video-ticks></div>
                        <div class="video-editor__cuts" data-video-cut-markers>
                            <?php foreach ($videoCutPoints as $cutPoint): ?>
                                <?php $position = (float) ($cutPoint['position_seconds'] ?? 0); ?>
                                <button
                                    class="video-editor__cut-marker"
                                    type="button"
                                    style="left: <?= View::escape(($position / (float) $selectedVideo['duration_seconds']) * 100) ?>%"
                                    data-cut-id="<?= View::escape($cutPoint['id'] ?? '') ?>"
                                    data-position-seconds="<?= View::escape(number_format($position, 3, '.', '')) ?>"
                                    aria-label="Corte <?= View::escape($durationPreciseLabel($position)) ?>"
                                ></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="video-editor__playhead" data-video-playhead></div>
                    </div>
                </div>

                <p class="task-message" role="status" aria-live="polite" data-video-editor-message hidden></p>

                <section class="video-cut-list-panel" aria-labelledby="video-cut-list-title">
                    <div class="tasks-list-header">
                        <h3 id="video-cut-list-title">Puntos de corte</h3>
                        <span class="task-count" data-video-cut-count><?= count($videoCutPoints) ?> cortes</span>
                    </div>
                    <ol class="video-cut-list" data-video-cut-list>
                        <?php if ($videoCutPoints === []): ?>
                            <li class="video-cut-list__empty">Aun no has agregado puntos de corte.</li>
                        <?php endif; ?>
                        <?php foreach ($videoCutPoints as $cutPoint): ?>
                            <?php $position = (float) ($cutPoint['position_seconds'] ?? 0); ?>
                            <li class="video-cut-item" data-cut-id="<?= View::escape($cutPoint['id'] ?? '') ?>" data-position-seconds="<?= View::escape(number_format($position, 3, '.', '')) ?>">
                                <strong><?= View::escape($durationPreciseLabel($position)) ?></strong>
                                <input aria-label="Editar tiempo del corte" type="text" value="<?= View::escape($durationPreciseLabel($position)) ?>" data-cut-time-input>
                                <button class="button button--secondary" type="button" data-cut-action="go">Ir</button>
                                <button class="button button--secondary" type="button" data-cut-action="save">Guardar</button>
                                <button class="button button--danger" type="button" data-cut-action="delete">Eliminar</button>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>

                <section class="video-segments-panel" aria-labelledby="video-segments-title" data-video-segments-panel>
                    <div class="tasks-list-header">
                        <h3 id="video-segments-title">Segmentos</h3>
                        <span class="task-count" data-video-segment-count><?= count($videoSegments) ?> segmentos</span>
                    </div>

                    <div class="video-result-summary" data-video-result-summary>
                        <div>
                            <span>Resultado</span>
                            <strong data-result-total><?= View::escape((string) ($videoSegmentSummary['total_segments'] ?? count($videoSegments))) ?> segmentos totales</strong>
                        </div>
                        <div>
                            <span>Incluidos</span>
                            <strong data-result-included><?= View::escape((string) ($videoSegmentSummary['included_segments'] ?? 0)) ?> incluidos</strong>
                        </div>
                        <div>
                            <span>Duracion original</span>
                            <strong data-result-original><?= View::escape($durationLabel($videoSegmentSummary['original_duration_seconds'] ?? ($selectedVideo['duration_seconds'] ?? 0))) ?></strong>
                        </div>
                        <div>
                            <span>Duracion final estimada</span>
                            <strong data-result-final><?= View::escape($durationLabel($videoSegmentSummary['final_duration_seconds'] ?? 0)) ?></strong>
                        </div>
                        <div>
                            <span>Secuencia</span>
                            <strong data-result-sequence><?= View::escape($segmentSequenceLabel($videoSegmentSummary)) ?></strong>
                        </div>
                    </div>

                    <div class="task-actions task-actions--start">
                        <button class="button button--primary" type="button" data-video-export-open>Exportar video</button>
                    </div>

                    <div class="video-export-confirm" data-video-export-confirm hidden>
                        <div class="video-result-summary">
                            <div>
                                <span>Segmentos incluidos</span>
                                <strong data-export-confirm-segments><?= View::escape((string) ($videoSegmentSummary['included_segments'] ?? 0)) ?></strong>
                            </div>
                            <div>
                                <span>Duracion estimada</span>
                                <strong data-export-confirm-duration><?= View::escape($durationLabel($videoSegmentSummary['final_duration_seconds'] ?? 0)) ?></strong>
                            </div>
                            <div>
                                <span>Formato de salida</span>
                                <strong>MP4</strong>
                            </div>
                            <div>
                                <span>Video</span>
                                <strong>H.264</strong>
                            </div>
                            <div>
                                <span>Audio</span>
                                <strong>AAC</strong>
                            </div>
                        </div>
                        <label>
                            <span>Nombre</span>
                            <input type="text" name="output_name" value="<?= View::escape((string) preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', (string) ($selectedVideo['original_name'] ?? 'Video editado'))) ?>" data-video-export-name>
                        </label>
                        <div class="task-actions task-actions--start">
                            <button class="button button--secondary" type="button" data-video-export-cancel>Cancelar</button>
                            <button class="button button--primary" type="button" data-video-export-create>Crear exportacion</button>
                        </div>
                    </div>

                    <div class="video-segment-strip" aria-label="Tira visual de segmentos" data-video-segment-strip>
                        <?php foreach ($videoSegments as $segment): ?>
                            <?php
                            $segmentStart = (float) ($segment['source_start_seconds'] ?? 0);
                            $segmentEnd = (float) ($segment['source_end_seconds'] ?? 0);
                            $segmentDuration = max(0.001, (float) ($segment['duration_seconds'] ?? ($segmentEnd - $segmentStart)));
                            $segmentWidth = max(8.0, min(100.0, ($segmentDuration / (float) ($selectedVideo['duration_seconds'] ?? 1)) * 100));
                            ?>
                            <button
                                type="button"
                                class="video-segment-strip__item<?= (bool) ($segment['is_included'] ?? true) ? '' : ' is-excluded' ?>"
                                style="flex-basis: <?= View::escape(number_format($segmentWidth, 3, '.', '')) ?>%"
                                data-segment-id="<?= View::escape($segment['id'] ?? '') ?>"
                                aria-label="Segmento fuente <?= View::escape($segment['source_index'] ?? '') ?>"
                            ><?= View::escape((string) ($segment['source_index'] ?? '')) ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="video-segment-list" data-video-segment-list>
                        <?php foreach ($videoSegments as $segment): ?>
                            <?php
                            $segmentStart = (float) ($segment['source_start_seconds'] ?? 0);
                            $segmentEnd = (float) ($segment['source_end_seconds'] ?? 0);
                            $segmentIncluded = (bool) ($segment['is_included'] ?? true);
                            ?>
                            <article
                                class="video-segment-card<?= $segmentIncluded ? '' : ' is-excluded' ?>"
                                data-segment-id="<?= View::escape($segment['id'] ?? '') ?>"
                                data-source-index="<?= View::escape($segment['source_index'] ?? '') ?>"
                                data-source-start="<?= View::escape(number_format($segmentStart, 3, '.', '')) ?>"
                                data-source-end="<?= View::escape(number_format($segmentEnd, 3, '.', '')) ?>"
                                data-is-included="<?= $segmentIncluded ? '1' : '0' ?>"
                                draggable="<?= $segmentIncluded ? 'true' : 'false' ?>"
                            >
                                <div>
                                    <span class="video-segment-card__number">Segmento <?= View::escape($segmentIncluded ? (string) ($segment['sort_order'] ?? '') : 'descartado') ?></span>
                                    <strong><?= View::escape($durationShortPreciseLabel($segmentStart)) ?> -> <?= View::escape($durationShortPreciseLabel($segmentEnd)) ?></strong>
                                    <span><?= View::escape($durationLabel($segment['duration_seconds'] ?? 0)) ?></span>
                                </div>
                                <div class="task-actions">
                                    <button class="button button--secondary" type="button" data-segment-action="go">Ir</button>
                                    <button class="button button--secondary" type="button" data-segment-action="play">Reproducir segmento</button>
                                    <?php if ($segmentIncluded): ?>
                                        <button class="button button--secondary" type="button" data-segment-action="move-left" aria-label="Mover segmento antes">&larr;</button>
                                        <button class="button button--secondary" type="button" data-segment-action="move-right" aria-label="Mover segmento despues">&rarr;</button>
                                        <button class="button button--danger" type="button" data-segment-action="exclude">Excluir del resultado</button>
                                    <?php else: ?>
                                        <button class="button button--secondary" type="button" data-segment-action="restore">Restaurar</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="video-exports-panel" aria-labelledby="video-exports-title">
                    <div class="tasks-list-header">
                        <h3 id="video-exports-title">Exportaciones</h3>
                        <span class="task-count" data-video-export-count><?= count($videoExportJobs) ?></span>
                    </div>
                    <div class="video-export-list" data-video-export-list>
                        <?php if ($videoExportJobs === []): ?>
                            <div class="video-cut-list__empty">Aun no has creado exportaciones.</div>
                        <?php endif; ?>
                        <?php foreach ($videoExportJobs as $job): ?>
                            <?php
                            $jobStatus = (string) ($job['status'] ?? 'pending');
                            $progress = max(0.0, min(100.0, (float) ($job['progress_percent'] ?? ($jobStatus === 'completed' ? 100 : 0))));
                            ?>
                            <article class="video-export-item" data-export-job-id="<?= View::escape($job['id'] ?? '') ?>">
                                <div>
                                    <strong><?= View::escape($job['output_name'] ?? '') ?></strong>
                                    <span><?= View::escape($jobStatus === 'pending' ? 'En cola' : $exportStatusLabel($jobStatus)) ?><?= $jobStatus === 'completed' && ($job['output_size_bytes'] ?? null) !== null ? ' · ' . View::escape($sizeLabel($job['output_size_bytes'])) : '' ?></span>
                                    <div class="video-export-progress" aria-label="Progreso <?= View::escape(number_format($progress, 0)) ?>%">
                                        <span style="width: <?= View::escape(number_format($progress, 2, '.', '')) ?>%"></span>
                                    </div>
                                    <small><?= View::escape(number_format($progress, 0)) ?>%<?= ($job['processed_seconds'] ?? null) !== null ? ' · ' . View::escape($durationLabel($job['processed_seconds'])) . ' procesados de ' . View::escape($durationLabel($job['estimated_duration_seconds'] ?? 0)) : '' ?><?= ($job['speed'] ?? null) !== null ? ' · Velocidad: ' . View::escape($job['speed']) : '' ?></small>
                                    <?php if ($jobStatus === 'completed' && ($job['expires_at'] ?? null) !== null): ?>
                                        <small>Expira: <?= View::escape($dateTimeLabel($job['expires_at'])) ?></small>
                                    <?php elseif ($jobStatus === 'failed' && ($job['error_message'] ?? null) !== null): ?>
                                        <small>No se pudo completar la exportacion.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="task-actions">
                                    <?php if ($jobStatus === 'completed'): ?>
                                        <a class="button button--secondary" href="/video/export/download.php?id=<?= View::escape($job['id'] ?? '') ?>">Descargar</a>
                                        <button class="button button--danger" type="button" data-export-action="delete">Eliminar exportacion</button>
                                    <?php elseif ($jobStatus === 'failed'): ?>
                                        <button class="button button--secondary" type="button" data-export-action="retry" data-output-name="<?= View::escape($job['output_name'] ?? '') ?>">Reintentar</button>
                                        <button class="button button--danger" type="button" data-export-action="delete">Eliminar exportacion</button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

            <?php endif; ?>
            <p class="task-message" role="status" aria-live="polite" data-video-message hidden></p>
        </section>
    <?php elseif ($selectedVideo !== null): ?>
        <section class="tasks-panel video-detail" aria-label="Detalle de video" data-video-id="<?= View::escape($selectedVideo['id'] ?? '') ?>">
            <div class="video-detail__header">
                <div>
                    <p class="eyebrow">Video</p>
                    <h2><?= View::escape($selectedVideo['original_name'] ?? '') ?></h2>
                </div>
                <div class="task-actions">
                    <a class="button button--secondary" href="/index.php?section=video">Volver</a>
                    <a class="button button--secondary" href="/index.php?section=video&amp;id=<?= View::escape($selectedVideo['id'] ?? '') ?>&amp;editor=1">Editar video</a>
                    <button class="button button--danger" type="button" data-video-action="delete" data-video-id="<?= View::escape($selectedVideo['id'] ?? '') ?>">Eliminar</button>
                </div>
            </div>

            <?php if ($canPreview($selectedVideo)): ?>
                <video class="video-player" controls preload="metadata" src="/video/stream.php?id=<?= View::escape($selectedVideo['id'] ?? '') ?>"></video>
            <?php else: ?>
                <p class="task-message">Este video fue subido correctamente, pero su formato no puede reproducirse directamente en el navegador. Podra convertirse durante la fase de exportacion.</p>
            <?php endif; ?>

            <div data-video-metadata-panel>
                <?php if ($metadataIsLoading($selectedVideo)): ?>
                    <div class="video-metadata-state" data-video-metadata-state="loading">
                        <strong>Analizando informacion del video...</strong>
                        <span>Tamano: <?= View::escape($sizeLabel($selectedVideo['size_bytes'] ?? 0)) ?></span>
                    </div>
                <?php elseif ($metadataIsFailed($selectedVideo)): ?>
                    <div class="video-metadata-state" data-video-metadata-state="failed">
                        <strong>No se pudo obtener la informacion tecnica del video.</strong>
                        <button class="button button--secondary" type="button" data-video-action="retry-metadata" data-video-id="<?= View::escape($selectedVideo['id'] ?? '') ?>">Reintentar analisis</button>
                    </div>
                <?php else: ?>
                    <dl class="video-metadata" data-video-metadata-state="ready">
                        <?php foreach ($metadataCards($selectedVideo) as $card): ?>
                            <div>
                                <dt><?= View::escape($card['label']) ?></dt>
                                <dd><?= View::escape($card['value']) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>

            <p class="task-message" role="status" aria-live="polite" data-video-message hidden></p>
        </section>
    <?php else: ?>
    <section class="tasks-panel video-upload-panel" aria-label="Subida de video">
        <form class="task-form video-upload-form" method="post" enctype="multipart/form-data" data-video-upload-form>
            <?= Csrf::input() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= View::escape($maxUploadMb * 1024 * 1024) ?>">
            <label>
                <span>Seleccionar video</span>
                <input type="file" name="video" accept=".mp4,.mov,.m4v,.webm,.mkv,video/mp4,video/quicktime,video/x-m4v,video/webm,video/x-matroska" required data-video-file-input>
            </label>
            <p class="muted" data-video-file-summary>Formatos permitidos: MP4, MOV, M4V, WEBM y MKV. Maximo <?= View::escape($maxUploadLabel) ?>.</p>
            <div class="task-form__actions">
                <button class="button button--primary" type="submit">Subir</button>
            </div>
        </form>
        <p class="task-message" role="status" aria-live="polite" data-video-message hidden></p>
    </section>

    <section class="tasks-panel" aria-label="Mis videos">
        <div class="tasks-list-header">
            <h2>Mis videos</h2>
            <span class="task-count" data-video-count><?= count($videos) ?></span>
        </div>
        <div class="video-list" data-video-list>
            <?php if ($videos === []): ?>
                <?php View::render('components/empty-state', ['text' => 'Aun no has subido videos.']); ?>
            <?php endif; ?>
            <?php foreach ($videos as $video): ?>
                <article class="video-item" data-video-id="<?= View::escape($video['id'] ?? '') ?>">
                    <div class="video-item__main">
                        <h3><a href="/index.php?section=video&amp;id=<?= View::escape($video['id'] ?? '') ?>"><?= View::escape($video['original_name'] ?? '') ?></a></h3>
                        <div class="task-meta">
                            <span><?= View::escape($sizeLabel($video['size_bytes'] ?? 0)) ?></span>
                            <?php foreach ($metadataSummary($video) as $summary): ?>
                                <span><?= $summary ?></span>
                            <?php endforeach; ?>
                            <span><?= View::escape($metadataStatusLabel($video['metadata_status'] ?? 'pending')) ?></span>
                        </div>
                    </div>
                    <div class="task-actions">
                        <?php if (($video['metadata_status'] ?? 'pending') === 'failed'): ?>
                            <button class="button button--secondary" type="button" data-video-action="retry-metadata">Reintentar analisis</button>
                        <?php endif; ?>
                        <a class="button button--secondary" href="/index.php?section=video&amp;id=<?= View::escape($video['id'] ?? '') ?>">Abrir</a>
                        <button class="button button--danger" type="button" data-video-action="delete">Eliminar</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</section>
