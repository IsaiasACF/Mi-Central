<?php
declare(strict_types=1);

namespace Modules\Friends;

use Modules\Organization\SpaceRepository;

final class CoincidenceTaskPrefillService
{
    public function __construct(
        private readonly CoincidenceService $coincidences,
        private readonly SpaceRepository $spaces,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function fromQuery(int $userId, array $query): array
    {
        $type = is_string($query['type'] ?? null) ? (string) $query['type'] : 'individual';
        $date = $this->date($query['date'] ?? null);
        $startsAt = $this->time($query['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->time($query['ends_at'] ?? null, 'ends_at');

        if (!in_array($type, ['individual', 'group'], true)) {
            throw new FriendValidationException('Tipo de coincidencia invalido.');
        }

        if ($startsAt >= $endsAt) {
            throw new FriendValidationException('Coincidencia invalida.');
        }

        if ($type === 'group') {
            return $this->groupPrefill($userId, $date, $startsAt, $endsAt, $query);
        }

        return $this->individualPrefill($userId, $date, $startsAt, $endsAt, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function individualPrefill(int $userId, string $date, string $startsAt, string $endsAt, array $query): array
    {
        $friendId = $this->positiveId($query['friend_id'] ?? null, 'friend_id');
        $match = null;

        foreach ($this->coincidences->getCoincidencesForDate($userId, $date, [$friendId]) as $item) {
            if (($item['starts_at'] ?? '') === $startsAt && ($item['ends_at'] ?? '') === $endsAt && (int) ($item['friend_id'] ?? 0) === $friendId) {
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            throw new FriendValidationException('Coincidencia no encontrada.');
        }

        $friendName = (string) ($match['friend_name'] ?? 'Amigo');
        $description = ['Coincidencia segun horarios academicos con ' . $friendName . '.'];

        if (!empty($match['same_course'])) {
            $sharedCourse = $this->sharedCourseLabel($match);

            if ($sharedCourse !== '') {
                $description[] = 'Ramo compartido: ' . $sharedCourse . '.';
            }
        }

        if (!empty($match['same_campus']) && is_string($match['user_campus'] ?? null) && trim((string) $match['user_campus']) !== '') {
            $description[] = 'Campus segun horario: ' . trim((string) $match['user_campus']) . '.';
        }

        $description[] = 'No representa ubicacion en tiempo real.';

        return $this->basePrefill($userId, $date, $startsAt, $endsAt, [
            'title' => 'Ver a ' . $friendName,
            'description' => implode("\n", $description),
            'return_url' => $this->returnUrl($query['return_to'] ?? null, $date),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function groupPrefill(int $userId, string $date, string $startsAt, string $endsAt, array $query): array
    {
        $friendIds = $this->friendIds($query['friend_ids'] ?? null);
        $requestedSignature = $this->idSignature($friendIds);
        $match = null;

        foreach ($this->coincidences->getGroupCoincidencesForDate($userId, $date, $friendIds) as $group) {
            $groupFriendIds = array_map(
                static fn (array $friend): int => (int) ($friend['id'] ?? 0),
                is_array($group['friends'] ?? null) ? $group['friends'] : []
            );

            if (($group['starts_at'] ?? '') === $startsAt && ($group['ends_at'] ?? '') === $endsAt && $this->idSignature($groupFriendIds) === $requestedSignature) {
                $match = $group;
                break;
            }
        }

        if ($match === null) {
            throw new FriendValidationException('Coincidencia grupal no encontrada.');
        }

        $friends = is_array($match['friends'] ?? null) ? $match['friends'] : [];
        $friendNames = array_map(static fn (array $friend): string => (string) ($friend['name'] ?? 'Amigo'), $friends);
        $description = [
            'Coincidencia grupal segun horarios academicos.',
            'Participantes: ' . implode(', ', $friendNames) . '.',
        ];

        if (!empty($match['same_campus']) && is_string($match['campus'] ?? null) && trim((string) $match['campus']) !== '') {
            $description[] = 'Campus segun horario: ' . trim((string) $match['campus']) . '.';
        }

        $userBlock = is_array($match['user_block'] ?? null) ? $match['user_block'] : [];

        if ($userBlock !== []) {
            $description[] = 'Tu bloque: ' . $this->courseLabel($userBlock) . '.';
        }

        foreach ($friends as $friend) {
            $block = is_array($friend['block'] ?? null) ? $friend['block'] : [];
            $description[] = (string) ($friend['name'] ?? 'Amigo') . ': ' . ($block !== [] ? $this->courseLabel($block) : 'bloque academico') . '.';
        }

        $description[] = 'No representa ubicacion en tiempo real.';

        return $this->basePrefill($userId, $date, $startsAt, $endsAt, [
            'title' => 'Juntarse con ' . $this->humanList($friendNames),
            'description' => implode("\n", $description),
            'return_url' => $this->returnUrl($query['return_to'] ?? null, $date),
        ]);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function basePrefill(int $userId, string $date, string $startsAt, string $endsAt, array $extra): array
    {
        return array_merge([
            'source' => 'coincidence',
            'space_id' => $this->friendsSpaceId($userId),
            'priority' => 'normal',
            'starts_at' => $date . 'T' . $startsAt,
            'ends_at' => $date . 'T' . $endsAt,
            'due_at' => '',
        ], $extra);
    }

    private function friendsSpaceId(int $userId): string
    {
        foreach ($this->spaces->listForUser($userId) as $space) {
            if (($space['slug'] ?? '') === 'amigos') {
                return (string) $space['id'];
            }
        }

        return '';
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw new FriendValidationException('Fecha invalida.');
        }

        $parts = array_map('intval', explode('-', $value));

        if (!checkdate($parts[1], $parts[2], $parts[0])) {
            throw new FriendValidationException('Fecha invalida.');
        }

        return $value;
    }

    private function time(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/', $value) !== 1) {
            throw new FriendValidationException('Hora invalida: ' . $field . '.');
        }

        return $value;
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new FriendValidationException('Identificador invalido: ' . $field . '.');
        }

        if (preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1) {
            throw new FriendValidationException('Identificador invalido: ' . $field . '.');
        }

        return (int) $value;
    }

    /**
     * @return array<int, int>
     */
    private function friendIds(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new FriendValidationException('Amigos invalidos.');
        }

        $ids = [];

        foreach (explode(',', $value) as $rawId) {
            $ids[] = $this->positiveId(trim($rawId), 'friend_ids');
        }

        $ids = array_values(array_unique($ids));

        if (count($ids) < 2) {
            throw new FriendValidationException('Coincidencia grupal invalida.');
        }

        return $ids;
    }

    /**
     * @param array<int, int> $ids
     */
    private function idSignature(array $ids): string
    {
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function sharedCourseLabel(array $item): string
    {
        $block = is_array($item['user_block'] ?? null) ? $item['user_block'] : [];

        return $this->courseLabel($block);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function courseLabel(array $block): string
    {
        $code = trim((string) ($block['course_code'] ?? ''));

        if ($code !== '') {
            return $code;
        }

        return trim((string) ($block['course_name'] ?? ''));
    }

    /**
     * @param array<int, string> $items
     */
    private function humanList(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
        $count = count($items);

        if ($count === 0) {
            return 'el grupo';
        }

        if ($count === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)) . ' y ' . $items[$count - 1];
    }

    private function returnUrl(mixed $value, string $date): string
    {
        if (is_string($value) && str_starts_with($value, '/index.php?section=friends')) {
            return $value;
        }

        return '/index.php?section=friends&tab=coincidences&date=' . rawurlencode($date);
    }
}
