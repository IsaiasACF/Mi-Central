<?php
declare(strict_types=1);

namespace Modules\Video;

final class VideoEditorService
{
    private const DUPLICATE_TOLERANCE_SECONDS = 0.050;

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoCutPointRepository $cutPoints,
        private readonly VideoEditSegmentRepository $segments,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, int $videoId): array
    {
        $video = $this->editableVideo($userId, $videoId);

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        return $this->resources($this->cutPoints->listForVideoForUser($userId, (int) $video['id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, int $videoId, mixed $position): array
    {
        return $this->segments->transaction(function () use ($userId, $videoId, $position): array {
            $video = $this->editableVideo($userId, $videoId);

            if ($video === null) {
                throw new VideoValidationException('Video no encontrado.');
            }

            $positionSeconds = $this->validPosition($position, (float) $video['duration_seconds']);
            $this->ensureNotDuplicate((int) $video['id'], $positionSeconds, null);
            $cutPointId = $this->cutPoints->create((int) $video['id'], $positionSeconds);
            $this->syncSegments($userId, $video);

            return $this->resource($this->cutPoints->findForUser($userId, $cutPointId) ?? []);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $cutPointId, mixed $position): ?array
    {
        return $this->segments->transaction(function () use ($userId, $cutPointId, $position): ?array {
            $cutPoint = $this->cutPoints->findForUser($userId, $this->positiveId($cutPointId, 'cut_id'));

            if ($cutPoint === null) {
                return null;
            }

            $video = $this->editableVideo($userId, (int) $cutPoint['video_id']);

            if ($video === null) {
                throw new VideoValidationException('Video no encontrado.');
            }

            $positionSeconds = $this->validPosition($position, (float) $video['duration_seconds']);
            $this->ensureNotDuplicate((int) $video['id'], $positionSeconds, (int) $cutPoint['id']);

            if (!$this->cutPoints->updateForUser($userId, (int) $cutPoint['id'], $positionSeconds)) {
                return null;
            }

            $this->syncSegments($userId, $video);

            return $this->resource($this->cutPoints->findForUser($userId, (int) $cutPoint['id']) ?? []);
        });
    }

    public function delete(int $userId, int $cutPointId): bool
    {
        return $this->segments->transaction(function () use ($userId, $cutPointId): bool {
            $cutPoint = $this->cutPoints->findForUser($userId, $this->positiveId($cutPointId, 'cut_id'));

            if ($cutPoint === null) {
                return false;
            }

            $video = $this->editableVideo($userId, (int) $cutPoint['video_id']);

            if ($video === null) {
                throw new VideoValidationException('Video no encontrado.');
            }

            if (!$this->cutPoints->deleteForUser($userId, (int) $cutPoint['id'])) {
                return false;
            }

            $this->syncSegments($userId, $video);

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function listSegments(int $userId, int $videoId): array
    {
        $video = $this->editableVideo($userId, $videoId);

        if ($video === null) {
            throw new VideoValidationException('Video no encontrado.');
        }

        $segments = $this->segments->transaction(fn (): array => $this->syncSegments($userId, $video));

        return $this->segmentCollection($segments, (float) $video['duration_seconds']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setSegmentIncluded(int $userId, int $segmentId, bool $isIncluded): ?array
    {
        return $this->segments->transaction(function () use ($userId, $segmentId, $isIncluded): ?array {
            $segment = $this->segments->findForUser($userId, $this->positiveId($segmentId, 'segment_id'));

            if ($segment === null) {
                return null;
            }

            $video = $this->editableVideo($userId, (int) $segment['video_id']);

            if ($video === null) {
                throw new VideoValidationException('Video no encontrado.');
            }

            $this->syncSegments($userId, $video);

            if (!$this->segments->setIncludedForUser($userId, (int) $segment['id'], $isIncluded)) {
                return null;
            }

            if ($isIncluded) {
                $this->placeRestoredSegmentNearSource($userId, (int) $video['id'], (int) $segment['id']);
            } else {
                $this->normalizeIncludedSortOrder($userId, (int) $video['id']);
            }

            return $this->segmentCollection($this->segments->listForVideoForUser($userId, (int) $video['id']), (float) $video['duration_seconds']);
        });
    }

    /**
     * @param array<int, mixed> $segmentIds
     * @return array<string, mixed>
     */
    public function reorderSegments(int $userId, int $videoId, array $segmentIds): array
    {
        return $this->segments->transaction(function () use ($userId, $videoId, $segmentIds): array {
            $video = $this->editableVideo($userId, $videoId);

            if ($video === null) {
                throw new VideoValidationException('Video no encontrado.');
            }

            $segments = $this->syncSegments($userId, $video);
            $included = array_values(array_filter($segments, static fn (array $segment): bool => (bool) (int) $segment['is_included']));
            $expectedIds = array_map(static fn (array $segment): int => (int) $segment['id'], $included);
            $receivedIds = array_map(fn (mixed $id): int => $this->positiveId((int) $id, 'segment_id'), $segmentIds);

            sort($expectedIds, SORT_NUMERIC);
            $sortedReceived = $receivedIds;
            sort($sortedReceived, SORT_NUMERIC);

            if ($receivedIds === [] || count($receivedIds) !== count(array_unique($receivedIds)) || $sortedReceived !== $expectedIds) {
                throw new VideoValidationException('Orden de segmentos invalido.');
            }

            foreach (array_values($receivedIds) as $index => $segmentId) {
                $this->segments->updateSortOrderForUser($userId, $segmentId, $index + 1);
            }

            return $this->segmentCollection($this->segments->listForVideoForUser($userId, (int) $video['id']), (float) $video['duration_seconds']);
        });
    }

    /**
     * @return array<int, array{source_start_seconds: float, source_end_seconds: float}>
     */
    public function getExportSequence(int $videoId, int $userId): array
    {
        $collection = $this->listSegments($userId, $videoId);

        return array_map(
            static fn (array $segment): array => [
                'source_start_seconds' => (float) $segment['source_start_seconds'],
                'source_end_seconds' => (float) $segment['source_end_seconds'],
            ],
            array_values(array_filter($collection['segments'], static fn (array $segment): bool => (bool) $segment['is_included']))
        );
    }

    /**
     * @param array<int, float> $cutPositions
     * @return array<int, array{start: float, end: float}>
     */
    public function virtualSegments(float $durationSeconds, array $cutPositions): array
    {
        if ($durationSeconds <= 0.0) {
            return [];
        }

        $positions = array_values(array_filter($cutPositions, static fn (float $position): bool => $position > 0.0 && $position < $durationSeconds));
        sort($positions, SORT_NUMERIC);
        $boundaries = array_merge([0.0], $positions, [$durationSeconds]);
        $segments = [];

        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            if ($boundaries[$index + 1] <= $boundaries[$index]) {
                continue;
            }

            $segments[] = [
                'start' => round((float) $boundaries[$index], 3),
                'end' => round((float) $boundaries[$index + 1], 3),
            ];
        }

        return $segments;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function editableVideo(int $userId, int $videoId): ?array
    {
        $video = $this->videos->findByIdForUser($userId, $this->positiveId($videoId, 'video_id'));

        if ($video === null) {
            return null;
        }

        if (($video['metadata_status'] ?? '') !== 'ready' || ($video['duration_seconds'] ?? null) === null || (float) $video['duration_seconds'] <= 0.0) {
            throw new VideoValidationException('El video debe terminar de analizarse antes de abrir el editor.');
        }

        return $video;
    }

    private function validPosition(mixed $value, float $durationSeconds): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new VideoValidationException('El punto de corte esta fuera del video.');
        }

        if (is_string($value) && !is_numeric($value)) {
            throw new VideoValidationException('El punto de corte esta fuera del video.');
        }

        $positionSeconds = round((float) $value, 3);

        if ($positionSeconds <= 0.0 || $positionSeconds >= $durationSeconds) {
            throw new VideoValidationException('El punto de corte esta fuera del video.');
        }

        return $positionSeconds;
    }

    private function ensureNotDuplicate(int $videoId, float $positionSeconds, ?int $exceptCutPointId): void
    {
        if ($this->cutPoints->existsNearPosition($videoId, $positionSeconds, self::DUPLICATE_TOLERANCE_SECONDS, $exceptCutPointId)) {
            throw new VideoValidationException('Ya existe un corte muy proximo a esa posicion.');
        }
    }

    /**
     * @param array<string, mixed> $video
     * @return array<int, array<string, mixed>>
     */
    private function syncSegments(int $userId, array $video): array
    {
        $videoId = (int) $video['id'];
        $duration = (float) $video['duration_seconds'];
        $cuts = $this->cutPoints->listForVideoForUser($userId, $videoId);
        $positions = array_map(static fn (array $cutPoint): float => (float) $cutPoint['position_seconds'], $cuts);
        $expected = $this->virtualSegments($duration, $positions);
        $existing = $this->segments->listForVideo($videoId);
        $usedIds = [];
        $specs = [];

        foreach ($expected as $sourceIndex => $range) {
            $spec = $this->segmentSpecForRange($range, $sourceIndex, $existing, $usedIds);
            $specs[] = $spec;

            if ($spec['id'] !== null) {
                $usedIds[] = (int) $spec['id'];
            }
        }

        $specs = $this->normalizeSegmentSpecs($specs);
        $keepIds = [];

        foreach ($specs as $spec) {
            if ($spec['id'] === null) {
                $spec['id'] = $this->segments->create(
                    $videoId,
                    (float) $spec['source_start_seconds'],
                    (float) $spec['source_end_seconds'],
                    (int) $spec['sort_order'],
                    (bool) $spec['is_included']
                );
            } else {
                $this->segments->update(
                    (int) $spec['id'],
                    (float) $spec['source_start_seconds'],
                    (float) $spec['source_end_seconds'],
                    (int) $spec['sort_order'],
                    (bool) $spec['is_included']
                );
            }

            $keepIds[] = (int) $spec['id'];
        }

        $this->segments->deleteExcept($videoId, $keepIds);

        return $this->segments->listForVideoForUser($userId, $videoId);
    }

    /**
     * @param array{start: float, end: float} $range
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, int> $usedIds
     * @return array<string, mixed>
     */
    private function segmentSpecForRange(array $range, int $sourceIndex, array $existing, array $usedIds): array
    {
        $start = round((float) $range['start'], 3);
        $end = round((float) $range['end'], 3);
        $matches = [];

        foreach ($existing as $segment) {
            $id = (int) $segment['id'];
            $idWasUsed = in_array($id, $usedIds, true);

            $existingStart = (float) $segment['source_start_seconds'];
            $existingEnd = (float) $segment['source_end_seconds'];

            if (!$idWasUsed && $this->sameTime($existingStart, $start) && $this->sameTime($existingEnd, $end)) {
                return $this->segmentSpec($id, $start, $end, (bool) (int) $segment['is_included'], (float) $segment['sort_order'], $sourceIndex);
            }

            if ($existingStart <= $start + 0.001 && $existingEnd >= $end - 0.001) {
                $matches[] = ['segment' => $segment, 'kind' => 'containing', 'duration' => $existingEnd - $existingStart, 'id_available' => !$idWasUsed];
            } elseif (!$idWasUsed && $existingStart >= $start - 0.001 && $existingEnd <= $end + 0.001) {
                $matches[] = ['segment' => $segment, 'kind' => 'contained', 'duration' => $existingEnd - $existingStart];
            }
        }

        if ($matches !== []) {
            usort($matches, static fn (array $a, array $b): int => $a['duration'] <=> $b['duration']);
            $base = $matches[0]['segment'];
            $isIncluded = true;
            $sortKey = (float) ($base['sort_order'] ?? ($sourceIndex + 1));

            if ($matches[0]['kind'] === 'containing') {
                $isIncluded = (bool) (int) $base['is_included'];
                $sortKey += $sourceIndex / 1000;
            } else {
                foreach ($matches as $match) {
                    $isIncluded = $isIncluded && (bool) (int) $match['segment']['is_included'];
                    $sortKey = min($sortKey, (float) $match['segment']['sort_order']);
                }
            }

            return $this->segmentSpec(($matches[0]['id_available'] ?? true) ? (int) $base['id'] : null, $start, $end, $isIncluded, $sortKey, $sourceIndex);
        }

        return $this->segmentSpec(null, $start, $end, true, $sourceIndex + 1, $sourceIndex);
    }

    /**
     * @return array<string, mixed>
     */
    private function segmentSpec(?int $id, float $start, float $end, bool $isIncluded, float $sortKey, int $sourceIndex): array
    {
        return [
            'id' => $id,
            'source_start_seconds' => round($start, 3),
            'source_end_seconds' => round($end, 3),
            'is_included' => $isIncluded,
            'sort_key' => $sortKey,
            'source_index' => $sourceIndex,
            'sort_order' => 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $specs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSegmentSpecs(array $specs): array
    {
        $includedIndexes = array_keys(array_filter($specs, static fn (array $spec): bool => (bool) $spec['is_included']));
        usort($includedIndexes, static function (int $a, int $b) use ($specs): int {
            $sort = (float) $specs[$a]['sort_key'] <=> (float) $specs[$b]['sort_key'];

            return $sort !== 0 ? $sort : (int) $specs[$a]['source_index'] <=> (int) $specs[$b]['source_index'];
        });

        foreach ($includedIndexes as $order => $index) {
            $specs[$index]['sort_order'] = $order + 1;
        }

        foreach ($specs as $index => $spec) {
            if (!(bool) $spec['is_included']) {
                $specs[$index]['sort_order'] = (int) ($spec['sort_key'] ?: ($spec['source_index'] + 1));
            }
        }

        return $specs;
    }

    private function sameTime(float $a, float $b): bool
    {
        return abs($a - $b) <= 0.001;
    }

    private function placeRestoredSegmentNearSource(int $userId, int $videoId, int $segmentId): void
    {
        $segments = $this->segments->listForVideoForUser($userId, $videoId);
        $included = array_values(array_filter($segments, static fn (array $segment): bool => (bool) (int) $segment['is_included']));
        usort($included, static fn (array $a, array $b): int => (float) $a['source_start_seconds'] <=> (float) $b['source_start_seconds']);

        foreach ($included as $index => $segment) {
            $this->segments->updateSortOrderForUser($userId, (int) $segment['id'], $index + 1);
        }
    }

    private function normalizeIncludedSortOrder(int $userId, int $videoId): void
    {
        $segments = array_values(array_filter(
            $this->segments->listForVideoForUser($userId, $videoId),
            static fn (array $segment): bool => (bool) (int) $segment['is_included']
        ));

        usort($segments, static fn (array $a, array $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order']);

        foreach ($segments as $index => $segment) {
            $this->segments->updateSortOrderForUser($userId, (int) $segment['id'], $index + 1);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<string, mixed>
     */
    private function segmentCollection(array $segments, float $durationSeconds): array
    {
        $resources = $this->segmentResources($segments);
        $included = array_values(array_filter($resources, static fn (array $segment): bool => (bool) $segment['is_included']));
        $finalDuration = array_reduce(
            $included,
            static fn (float $carry, array $segment): float => $carry + (float) $segment['duration_seconds'],
            0.0
        );

        return [
            'segments' => $resources,
            'summary' => [
                'total_segments' => count($resources),
                'included_segments' => count($included),
                'original_duration_seconds' => round($durationSeconds, 3),
                'final_duration_seconds' => round($finalDuration, 3),
                'sequence' => array_map(static fn (array $segment): int => (int) $segment['source_index'], $included),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function segmentResources(array $segments): array
    {
        $sourceOrdered = $segments;
        usort($sourceOrdered, static fn (array $a, array $b): int => (float) $a['source_start_seconds'] <=> (float) $b['source_start_seconds']);
        $sourceIndexById = [];

        foreach ($sourceOrdered as $index => $segment) {
            $sourceIndexById[(int) $segment['id']] = $index + 1;
        }

        return array_map(function (array $segment) use ($sourceIndexById): array {
            $start = round((float) $segment['source_start_seconds'], 3);
            $end = round((float) $segment['source_end_seconds'], 3);

            return [
                'id' => (int) $segment['id'],
                'video_id' => (int) $segment['video_id'],
                'source_start_seconds' => $start,
                'source_end_seconds' => $end,
                'duration_seconds' => round($end - $start, 3),
                'sort_order' => (int) $segment['sort_order'],
                'is_included' => (bool) (int) $segment['is_included'],
                'source_index' => $sourceIndexById[(int) $segment['id']] ?? 0,
                'created_at' => (string) ($segment['created_at'] ?? ''),
                'updated_at' => (string) ($segment['updated_at'] ?? ''),
            ];
        }, $segments);
    }

    /**
     * @param array<int, array<string, mixed>> $cutPoints
     * @return array<int, array<string, mixed>>
     */
    private function resources(array $cutPoints): array
    {
        return array_map(fn (array $cutPoint): array => $this->resource($cutPoint), $cutPoints);
    }

    /**
     * @param array<string, mixed> $cutPoint
     * @return array<string, mixed>
     */
    private function resource(array $cutPoint): array
    {
        return [
            'id' => (int) ($cutPoint['id'] ?? 0),
            'video_id' => (int) ($cutPoint['video_id'] ?? 0),
            'position_seconds' => round((float) ($cutPoint['position_seconds'] ?? 0), 3),
            'created_at' => (string) ($cutPoint['created_at'] ?? ''),
            'updated_at' => (string) ($cutPoint['updated_at'] ?? ''),
        ];
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new VideoValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }
}
