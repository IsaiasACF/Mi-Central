<?php
declare(strict_types=1);

use App\Support\DateTimeHelper;
use Modules\Organization\DeadlineUrgencyService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$exitCode = 1;

function urgency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function urgency_due_utc(DateTimeImmutable $now, string $modifier): string
{
    return $now->modify($modifier)->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
}

try {
    $service = new DeadlineUrgencyService('America/Santiago');
    $now = new DateTimeImmutable('2026-08-12 12:00:00', DateTimeHelper::timezone('America/Santiago'));

    urgency_assert($service->forTask(null, 'pending', $now) === null, 'Task without due_at should not have urgency.');
    urgency_assert($service->forProject(null, 'active', $now) === null, 'Project without due_on should not have urgency.');

    $cases = [
        '+31 days' => ['Mas de 1 mes', 'neutral'],
        '+30 days' => ['Faltan 30 dias', 'low'],
        '+15 days' => ['Faltan 15 dias', 'low'],
        '+14 days' => ['Faltan 14 dias', 'medium'],
        '+8 days' => ['Faltan 8 dias', 'medium'],
        '+7 days' => ['Faltan 7 dias', 'warning'],
        '+5 days' => ['Faltan 5 dias', 'warning'],
        '+4 days' => ['Faltan 4 dias', 'high'],
        '+3 days' => ['Faltan 3 dias', 'high'],
        '+2 days' => ['Faltan 2 dias', 'urgent'],
        '+1 day' => ['Falta 1 dia', 'urgent'],
        '+23 hours' => ['Menos de 24 h', 'critical'],
        '-1 day' => ['Vencida hace 1 dia', 'overdue'],
        '-4 days' => ['Vencida hace 4 dias', 'overdue'],
    ];

    foreach ($cases as $modifier => [$label, $level]) {
        $urgency = $service->forTask(urgency_due_utc($now, $modifier), 'pending', $now);
        urgency_assert(is_array($urgency), "Urgency missing for {$modifier}.");
        urgency_assert($urgency['label'] === $label, "Wrong label for {$modifier}: " . (string) $urgency['label']);
        urgency_assert($urgency['level'] === $level, "Wrong level for {$modifier}: " . (string) $urgency['level']);
    }

    $completed = $service->forTask(urgency_due_utc($now, '-4 days'), 'completed', $now);
    urgency_assert(is_array($completed) && $completed['label'] === 'Completada' && $completed['level'] === 'completed', 'Completed task appeared as urgent.');

    $projectToday = $service->forProject('2026-08-12', 'active', $now);
    urgency_assert(is_array($projectToday) && $projectToday['label'] === 'Menos de 24 h', 'Project due_on did not use local day end.');

    $projectCompleted = $service->forProject('2026-08-01', 'completed', $now);
    urgency_assert(is_array($projectCompleted) && $projectCompleted['label'] === 'Completado', 'Completed project appeared as urgent.');

    echo "Deadline urgency: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Deadline urgency: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
}

exit($exitCode);
