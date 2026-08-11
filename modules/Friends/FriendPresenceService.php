<?php
declare(strict_types=1);

namespace Modules\Friends;

use DateTimeImmutable;
use DateTimeZone;

final class FriendPresenceService
{
    public function __construct(
        private readonly FriendRepository $friends,
        private readonly FriendScheduleService $schedules,
        private readonly string $timezone = 'America/Santiago',
        private ?FriendScheduleResolver $resolver = null,
    ) {
        $this->resolver ??= new FriendScheduleResolver($this->schedules, $this->timezone);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function now(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $this->localNow($now);
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        $items = [];

        foreach ($this->friends->listForUser($userId, ['status' => 'active']) as $friend) {
            $friendId = (int) $friend['id'];
            $activeToday = $this->todayEntries($userId, $friendId, $date, true);
            $allToday = $this->todayEntries($userId, $friendId, $date, false);
            $current = null;
            $currentException = null;
            $next = null;

            foreach ($activeToday as $entry) {
                if ((string) $entry['starts_at'] <= $time && (string) $entry['ends_at'] > $time && empty($entry['counts_as_class'])) {
                    $currentException = $entry;
                    continue;
                }

                if ((string) $entry['starts_at'] <= $time && (string) $entry['ends_at'] > $time) {
                    $current = $entry;
                    break;
                }

                if ((string) $entry['starts_at'] > $time && $next === null && !empty($entry['counts_as_class'])) {
                    $next = $entry;
                }
            }

            if ($current !== null) {
                $items[] = $this->nowItem($friend, 'En clase', 'Deberia estar en clase', $current, 'hasta ' . $this->timeLabel($current['ends_at'] ?? ''));
                continue;
            }

            if ($currentException !== null) {
                $type = (string) ($currentException['exception_type'] ?? '');
                $summary = $type === 'absent'
                    ? 'No asistira a su clase de las ' . $this->timeLabel($currentException['starts_at'] ?? '')
                    : 'Clase cancelada a las ' . $this->timeLabel($currentException['starts_at'] ?? '');
                $items[] = $this->nowItem($friend, $type === 'absent' ? 'No asiste' : 'Cancelada', $summary, $currentException, '');
                continue;
            }

            if ($activeToday !== []) {
                $items[] = $this->nowItem(
                    $friend,
                    'Libre',
                    $next === null ? 'Segun horario, libre por ahora' : 'Libre hasta ' . $this->timeLabel($next['starts_at'] ?? ''),
                    $next,
                    $next === null ? '' : 'hasta ' . $this->timeLabel($next['starts_at'] ?? '')
                );
                continue;
            }

            if ($allToday !== []) {
                $items[] = $this->nowItem($friend, 'Fuera del periodo de vigencia', 'Segun horario, fuera del periodo de vigencia', null, '');
                continue;
            }

            $items[] = $this->nowItem($friend, 'Sin clases hoy', 'Segun horario, sin clases hoy', null, '');
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function today(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $this->localNow($now);
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        $items = [];

        foreach ($this->friends->listForUser($userId, ['status' => 'active']) as $friend) {
            $blocks = [];

            foreach ($this->todayEntries($userId, (int) $friend['id'], $date, true) as $entry) {
                $state = 'Proxima';

                if (($entry['exception_type'] ?? null) === 'cancelled') {
                    $state = 'Cancelada';
                } elseif (($entry['exception_type'] ?? null) === 'absent') {
                    $state = 'No asiste';
                } elseif ((string) $entry['ends_at'] <= $time) {
                    $state = 'Finalizada';
                } elseif ((string) $entry['starts_at'] <= $time && (string) $entry['ends_at'] > $time) {
                    $state = 'Ahora';
                } elseif (($entry['exception_type'] ?? null) === 'modified') {
                    $state = 'Modificada';
                }

                $blocks[] = array_merge($entry, [
                    'state' => $state,
                    'time_label' => $this->timeLabel($entry['starts_at'] ?? '') . ' - ' . $this->timeLabel($entry['ends_at'] ?? ''),
                ]);
            }

            $items[] = [
                'friend' => $friend,
                'blocks' => $blocks,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{label: string}>
     */
    public function dashboardItems(int $userId, int $limit = 3, ?DateTimeImmutable $now = null): array
    {
        $items = [];

        foreach (array_slice($this->now($userId, $now), 0, $limit) as $status) {
            $friend = is_array($status['friend'] ?? null) ? $status['friend'] : [];
            $suffix = (string) ($status['summary'] ?? $status['status'] ?? '');
            $items[] = [
                'label' => (string) ($friend['name'] ?? 'Amigo') . ' - ' . $suffix,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function todayEntries(int $userId, int $friendId, string $date, bool $activeOnly): array
    {
        $today = $this->resolver->effectiveForDate($userId, 'friend', $friendId, $date, $activeOnly);

        usort($today, static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return $today;
    }

    /**
     * @param array<string, mixed> $friend
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    private function nowItem(array $friend, string $status, string $summary, ?array $entry, string $until): array
    {
        return [
            'friend' => $friend,
            'status' => $status,
            'summary' => $summary,
            'entry' => $entry,
            'course_name' => $entry['course_name'] ?? null,
            'starts_at' => isset($entry['starts_at']) ? $this->timeLabel($entry['starts_at']) : null,
            'ends_at' => isset($entry['ends_at']) ? $this->timeLabel($entry['ends_at']) : null,
            'room' => $entry['room'] ?? null,
            'campus' => $entry['effective_campus'] ?? null,
            'until' => $until,
        ];
    }

    private function localNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        $timezone = new DateTimeZone($this->timezone);

        return $now === null ? new DateTimeImmutable('now', $timezone) : $now->setTimezone($timezone);
    }

    private function timeLabel(mixed $time): string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : '';
    }
}
