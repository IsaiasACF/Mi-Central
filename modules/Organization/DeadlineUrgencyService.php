<?php
declare(strict_types=1);

namespace Modules\Organization;

use App\Support\DateTimeHelper;
use DateTimeImmutable;

final class DeadlineUrgencyService
{
    public function __construct(private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forTask(?string $dueAtUtc, string $status, ?DateTimeImmutable $now = null): ?array
    {
        if ($status === 'completed') {
            return [
                'state' => 'completed',
                'level' => 'completed',
                'label' => 'Completada',
                'deadline_local' => DateTimeHelper::utcStorageToLocalStorage($dueAtUtc, $this->timezone),
                'deadline_input' => DateTimeHelper::utcStorageToLocalInput($dueAtUtc, $this->timezone),
                'is_overdue' => false,
            ];
        }

        if ($dueAtUtc === null || $dueAtUtc === '') {
            return null;
        }

        $deadline = DateTimeHelper::utcStorageToLocalDateTime($dueAtUtc, $this->timezone);

        return $this->calculate($deadline, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forProject(?string $dueOn, string $status, ?DateTimeImmutable $now = null): ?array
    {
        if ($dueOn === null || $dueOn === '') {
            return null;
        }

        if (in_array($status, ['completed', 'archived'], true)) {
            return [
                'state' => $status,
                'level' => 'completed',
                'label' => $status === 'archived' ? 'Archivado' : 'Completado',
                'deadline_local' => $dueOn,
                'deadline_input' => $dueOn,
                'is_overdue' => false,
            ];
        }

        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dueOn . ' 23:59:59', DateTimeHelper::timezone($this->timezone));

        if (!$deadline instanceof DateTimeImmutable) {
            return null;
        }

        return $this->calculate($deadline, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(DateTimeImmutable $deadline, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? DateTimeHelper::nowLocal($this->timezone))->setTimezone(DateTimeHelper::timezone($this->timezone));
        $seconds = $deadline->getTimestamp() - $now->getTimestamp();

        if ($seconds < 0) {
            $days = max(1, (int) ceil(abs($seconds) / 86400));

            return [
                'state' => 'overdue',
                'level' => 'overdue',
                'label' => 'Vencida hace ' . $days . ' ' . ($days === 1 ? 'dia' : 'dias'),
                'deadline_local' => $deadline->format('Y-m-d H:i:s'),
                'deadline_input' => $deadline->format('Y-m-d\TH:i'),
                'is_overdue' => true,
                'seconds_remaining' => $seconds,
                'days' => $days,
            ];
        }

        if ($seconds < 86400) {
            return $this->result($deadline, 'under-24h', 'critical', 'Menos de 24 h', $seconds, 0);
        }

        $days = (int) ceil($seconds / 86400);

        if ($days > 30) {
            return $this->result($deadline, 'future', 'neutral', 'Mas de 1 mes', $seconds, $days);
        }

        $level = match (true) {
            $days >= 15 => 'low',
            $days >= 8 => 'medium',
            $days >= 5 => 'warning',
            $days >= 3 => 'high',
            default => 'urgent',
        };

        return $this->result(
            $deadline,
            'upcoming',
            $level,
            'Falta' . ($days === 1 ? '' : 'n') . ' ' . $days . ' ' . ($days === 1 ? 'dia' : 'dias'),
            $seconds,
            $days,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(DateTimeImmutable $deadline, string $state, string $level, string $label, int $seconds, int $days): array
    {
        return [
            'state' => $state,
            'level' => $level,
            'label' => $label,
            'deadline_local' => $deadline->format('Y-m-d H:i:s'),
            'deadline_input' => $deadline->format('Y-m-d\TH:i'),
            'is_overdue' => false,
            'seconds_remaining' => $seconds,
            'days' => $days,
        ];
    }
}
