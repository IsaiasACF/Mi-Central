<?php
declare(strict_types=1);

namespace Modules\Friends;

final class CoincidenceService
{
    public function __construct(
        private readonly FriendRepository $friends,
        private readonly FriendScheduleResolver $resolver,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $schedule
     * @return array<int, array<string, mixed>>
     */
    public function calculateFreeIntervals(array $schedule): array
    {
        $occupied = [];

        foreach ($schedule as $block) {
            if (empty($block['counts_as_class'])) {
                continue;
            }

            $start = $this->timeToMinutes($block['starts_at'] ?? null);
            $end = $this->timeToMinutes($block['ends_at'] ?? null);

            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            $campus = $this->campus($block);
            $occupied[] = [
                'start' => $start,
                'end' => $end,
                'campus' => $campus,
                'first_campus' => $campus,
                'last_campus' => $campus,
            ];
        }

        usort($occupied, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $merged = $this->mergeOccupied($occupied);

        if (count($merged) < 2) {
            return [];
        }

        $free = [];

        for ($index = 0, $total = count($merged) - 1; $index < $total; $index++) {
            $previous = $merged[$index];
            $next = $merged[$index + 1];
            $start = (int) $previous['end'];
            $end = (int) $next['start'];

            if ($end <= $start) {
                continue;
            }

            $previousCampus = $previous['last_campus'] ?? null;
            $nextCampus = $next['first_campus'] ?? null;

            $free[] = [
                'starts_at' => $this->minutesToTime($start),
                'ends_at' => $this->minutesToTime($end),
                'duration_minutes' => $end - $start,
                'campus' => $this->sameCampus($previousCampus, $nextCampus) ? $previousCampus : null,
                'previous_campus' => $previousCampus,
                'next_campus' => $nextCampus,
            ];
        }

        return $free;
    }

    /**
     * @param array<int, int>|null $friendIds
     * @return array<int, array<string, mixed>>
     */
    public function getCoincidencesForDate(int $userId, string $date, ?array $friendIds = null): array
    {
        $userBlocks = $this->effectiveClassBlocks(
            $this->resolver->effectiveForDate($userId, 'user', null, $date, true)
        );

        if ($userBlocks === []) {
            return [];
        }

        $allowedFriendIds = $friendIds === null
            ? null
            : array_values(array_unique(array_map('intval', $friendIds)));
        $items = [];

        foreach ($this->friends->listForUser($userId, ['status' => 'active']) as $friend) {
            $friendId = (int) ($friend['id'] ?? 0);

            if ($allowedFriendIds !== null && !in_array($friendId, $allowedFriendIds, true)) {
                continue;
            }

            $friendBlocks = $this->effectiveClassBlocks(
                $this->resolver->effectiveForDate($userId, 'friend', $friendId, $date, true)
            );

            foreach ($userBlocks as $userBlock) {
                foreach ($friendBlocks as $friendBlock) {
                    $intersection = $this->intersectBlocks($userBlock, $friendBlock);

                    if ($intersection === null) {
                        continue;
                    }

                    $items[] = array_merge($intersection, [
                        'friend_id' => $friendId,
                        'friend_name' => (string) ($friend['name'] ?? 'Amigo'),
                        'date' => $date,
                    ]);
                }
            }
        }

        usort($items, fn (array $left, array $right): int => strcmp((string) $left['starts_at'], (string) $right['starts_at'])
            ?: $this->coincidencePriority($right) <=> $this->coincidencePriority($left)
            ?: strcmp((string) $left['friend_name'], (string) $right['friend_name']));

        return $items;
    }

    /**
     * @param array<int, int>|null $friendIds
     * @return array<int, array<string, mixed>>
     */
    public function getGroupCoincidencesForDate(int $userId, string $date, ?array $friendIds = null): array
    {
        $individual = $this->getCoincidencesForDate($userId, $date, $friendIds);

        if ($individual === []) {
            return [];
        }

        $points = [];

        foreach ($individual as $item) {
            $points[] = $this->timeToMinutes($item['starts_at'] ?? null);
            $points[] = $this->timeToMinutes($item['ends_at'] ?? null);
        }

        $points = array_values(array_unique(array_filter($points, static fn (?int $point): bool => $point !== null)));
        sort($points);
        $groups = [];

        for ($index = 0, $total = count($points) - 1; $index < $total; $index++) {
            $start = (int) $points[$index];
            $end = (int) $points[$index + 1];

            if ($end <= $start) {
                continue;
            }

            $friends = [];
            $covering = [];

            foreach ($individual as $item) {
                $itemStart = $this->timeToMinutes($item['starts_at'] ?? null);
                $itemEnd = $this->timeToMinutes($item['ends_at'] ?? null);

                if ($itemStart === null || $itemEnd === null || $itemStart > $start || $itemEnd < $end) {
                    continue;
                }

                $friends[(int) $item['friend_id']] = [
                    'id' => (int) $item['friend_id'],
                    'name' => (string) $item['friend_name'],
                    'block' => is_array($item['friend_block'] ?? null) ? $item['friend_block'] : [],
                ];
                $covering[] = $item;
            }

            if (count($friends) < 2) {
                continue;
            }

            $campus = $this->groupCampus($covering);
            $userBlocks = $this->groupUserBlocks($covering);

            $groups[] = [
                'date' => $date,
                'starts_at' => $this->minutesToTime($start),
                'ends_at' => $this->minutesToTime($end),
                'duration_minutes' => $end - $start,
                'friends' => array_values($friends),
                'user_block' => $userBlocks[0] ?? null,
                'user_blocks' => $userBlocks,
                'campus' => $campus,
                'same_campus' => $campus !== null,
            ];
        }

        return $this->mergeAdjacentGroups($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $schedule
     * @return array<int, array<string, mixed>>
     */
    private function effectiveClassBlocks(array $schedule): array
    {
        $blocks = [];

        foreach ($schedule as $block) {
            if (empty($block['counts_as_class'])) {
                continue;
            }

            $start = $this->timeToMinutes($block['starts_at'] ?? null);
            $end = $this->timeToMinutes($block['ends_at'] ?? null);

            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            $block['_start_minutes'] = $start;
            $block['_end_minutes'] = $end;
            $blocks[] = $block;
        }

        usort($blocks, static fn (array $left, array $right): int => ((int) $left['_start_minutes']) <=> ((int) $right['_start_minutes']));

        return $blocks;
    }

    /**
     * @param array<string, mixed> $userBlock
     * @param array<string, mixed> $friendBlock
     * @return array<string, mixed>|null
     */
    private function intersectBlocks(array $userBlock, array $friendBlock): ?array
    {
        $start = max((int) $userBlock['_start_minutes'], (int) $friendBlock['_start_minutes']);
        $end = min((int) $userBlock['_end_minutes'], (int) $friendBlock['_end_minutes']);

        if ($start >= $end) {
            return null;
        }

        $sameCourse = $this->sameCourse($userBlock, $friendBlock);
        $userCampus = $this->campus($userBlock);
        $friendCampus = $this->campus($friendBlock);
        $fullOverlap = $start === (int) $userBlock['_start_minutes']
            && $start === (int) $friendBlock['_start_minutes']
            && $end === (int) $userBlock['_end_minutes']
            && $end === (int) $friendBlock['_end_minutes'];

        return [
            'starts_at' => $this->minutesToTime($start),
            'ends_at' => $this->minutesToTime($end),
            'duration_minutes' => $end - $start,
            'user_block' => $this->compactBlock($userBlock),
            'friend_block' => $this->compactBlock($friendBlock),
            'user_campus' => $userCampus,
            'friend_campus' => $friendCampus,
            'same_course' => $sameCourse,
            'same_campus' => $this->sameCampus($userCampus, $friendCampus),
            'overlap_type' => $this->overlapType($sameCourse, $fullOverlap),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function compactBlock(array $block): array
    {
        return [
            'course_name' => (string) ($block['course_name'] ?? ''),
            'course_code' => (string) ($block['course_code'] ?? ''),
            'room' => (string) ($block['room'] ?? ''),
            'campus' => $this->campus($block),
            'starts_at' => $this->minutesToTime((int) $block['_start_minutes']),
            'ends_at' => $this->minutesToTime((int) $block['_end_minutes']),
        ];
    }

    private function overlapType(bool $sameCourse, bool $fullOverlap): string
    {
        if (!$fullOverlap) {
            return 'partial';
        }

        return $sameCourse ? 'exact_course' : 'same_time';
    }

    /**
     * @param array<int, array<string, mixed>> $occupied
     * @return array<int, array<string, mixed>>
     */
    private function mergeOccupied(array $occupied): array
    {
        $merged = [];

        foreach ($occupied as $interval) {
            if ($merged === []) {
                $merged[] = $interval;
                continue;
            }

            $lastIndex = count($merged) - 1;

            if ((int) $interval['start'] <= (int) $merged[$lastIndex]['end']) {
                if ((int) $interval['end'] > (int) $merged[$lastIndex]['end']) {
                    $merged[$lastIndex]['end'] = (int) $interval['end'];
                    $merged[$lastIndex]['last_campus'] = $interval['last_campus'] ?? null;
                }

                $merged[$lastIndex]['campus'] = $this->sameCampus($merged[$lastIndex]['campus'] ?? null, $interval['campus'] ?? null)
                    ? ($merged[$lastIndex]['campus'] ?? null)
                    : null;
                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function mergeAdjacentGroups(array $groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0
                && $merged[$lastIndex]['ends_at'] === $group['starts_at']
                && $this->friendSignature($merged[$lastIndex]['friends']) === $this->friendSignature($group['friends'])
                && $this->blockSignature($merged[$lastIndex]['user_blocks'] ?? []) === $this->blockSignature($group['user_blocks'] ?? [])
                && ($merged[$lastIndex]['campus'] ?? null) === ($group['campus'] ?? null)
            ) {
                $merged[$lastIndex]['ends_at'] = $group['ends_at'];
                $merged[$lastIndex]['duration_minutes'] += $group['duration_minutes'];
                continue;
            }

            $merged[] = $group;
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function groupCampus(array $items): ?string
    {
        $campus = null;

        foreach ($items as $item) {
            if (($item['same_campus'] ?? false) !== true || !is_string($item['user_campus'] ?? null)) {
                return null;
            }

            if ($campus === null) {
                $campus = (string) $item['user_campus'];
                continue;
            }

            if (!$this->sameCampus($campus, $item['user_campus'])) {
                return null;
            }
        }

        return $campus;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function groupUserBlocks(array $items): array
    {
        $blocks = [];

        foreach ($items as $item) {
            if (!is_array($item['user_block'] ?? null)) {
                continue;
            }

            $block = $item['user_block'];
            $blocks[$this->singleBlockSignature($block)] = $block;
        }

        return array_values($blocks);
    }

    /**
     * @param array<int, array<string, mixed>> $friends
     */
    private function friendSignature(array $friends): string
    {
        $ids = array_map(fn (array $friend): string => (int) ($friend['id'] ?? 0) . ':' . $this->singleBlockSignature(is_array($friend['block'] ?? null) ? $friend['block'] : []), $friends);
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function blockSignature(array $blocks): string
    {
        $signatures = array_map(fn (array $block): string => $this->singleBlockSignature($block), $blocks);
        sort($signatures);

        return implode('|', $signatures);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function singleBlockSignature(array $block): string
    {
        return implode(':', [
            (string) ($block['starts_at'] ?? ''),
            (string) ($block['ends_at'] ?? ''),
            (string) ($block['course_code'] ?? ''),
            (string) ($block['course_name'] ?? ''),
            (string) ($block['room'] ?? ''),
            (string) ($block['campus'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function campus(array $block): ?string
    {
        $campus = $block['effective_campus'] ?? $block['campus'] ?? null;

        if (!is_string($campus)) {
            return null;
        }

        $campus = trim($campus);

        return $campus === '' ? null : $campus;
    }

    private function sameCampus(mixed $first, mixed $second): bool
    {
        if (!is_string($first) || !is_string($second)) {
            return false;
        }

        return $this->normalizeCampus($first) === $this->normalizeCampus($second);
    }

    private function normalizeCampus(string $campus): string
    {
        return strtolower(trim($campus));
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    private function sameCourse(array $first, array $second): bool
    {
        $firstCode = $this->normalizeCourse((string) ($first['course_code'] ?? ''));
        $secondCode = $this->normalizeCourse((string) ($second['course_code'] ?? ''));

        if ($firstCode !== '' && $secondCode !== '') {
            return $firstCode === $secondCode;
        }

        $firstName = $this->normalizeCourse((string) ($first['course_name'] ?? ''));
        $secondName = $this->normalizeCourse((string) ($second['course_name'] ?? ''));

        return $firstName !== '' && $secondName !== '' && $firstName === $secondName;
    }

    private function normalizeCourse(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function coincidencePriority(array $item): int
    {
        $priority = 0;

        if (($item['same_campus'] ?? false) === true) {
            $priority += 1;
        }

        if (($item['same_course'] ?? false) === true) {
            $priority += 2;
        }

        return $priority;
    }

    private function timeToMinutes(mixed $time): ?int
    {
        if (!is_string($time) || preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?\z/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
