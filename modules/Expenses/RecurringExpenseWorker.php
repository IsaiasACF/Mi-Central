<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use Throwable;

final class RecurringExpenseWorker
{
    public function __construct(
        private readonly ExpenseRecurringRuleRepository $rules,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
        private readonly ?ExpenseRecurringAdjustmentRepository $adjustments = null,
    ) {
    }

    /**
     * @return array{processed_rules: int, created_expenses: int, skipped_existing: int, errors: int}
     */
    public function process(?DateTimeImmutable $today = null, ?int $onlyRuleId = null): array
    {
        $today ??= DateTimeHelper::nowLocal($this->timezone);
        $today = $today->setTimezone(DateTimeHelper::timezone($this->timezone));
        $todayString = $today->format('Y-m-d');
        $currentMonth = $today->modify('first day of this month')->format('Y-m-01');
        $summary = [
            'processed_rules' => 0,
            'created_expenses' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        ];

        foreach ($this->rules->dueForProcessing($todayString, $onlyRuleId) as $rule) {
            try {
                $result = $this->processRule($rule, $currentMonth);
                $summary['processed_rules']++;
                $summary['created_expenses'] += $result['created'];
                $summary['skipped_existing'] += $result['skipped'];
            } catch (Throwable) {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{created: int, skipped: int}
     */
    private function processRule(array $rule, string $currentMonth): array
    {
        $timezone = DateTimeHelper::timezone($this->timezone);
        $created = 0;
        $skipped = 0;
        $ruleId = (int) $rule['id'];
        $interval = max(1, (int) ($rule['interval_value'] ?? 1));
        $nextGeneration = is_string($rule['next_generation_on'] ?? null) ? (string) $rule['next_generation_on'] : null;
        $startsMonth = ExpenseDateHelper::monthStart((string) $rule['starts_on'], $timezone);
        $period = $nextGeneration === null || $nextGeneration < $startsMonth ? $startsMonth : $nextGeneration;

        if ((int) ($rule['service_active'] ?? 0) !== 1) {
            $this->rules->update((int) $rule['user_id'], $ruleId, [
                'next_generation_on' => $period,
            ]);

            return ['created' => 0, 'skipped' => 0];
        }

        $endsMonth = is_string($rule['ends_on'] ?? null)
            ? ExpenseDateHelper::monthStart((string) $rule['ends_on'], $timezone)
            : null;
        $iterations = 0;
        $lastConsideredPeriod = null;

        while ($period <= $currentMonth && ($endsMonth === null || $period <= $endsMonth)) {
            $generated = $this->createPeriod($rule, $period);
            $lastConsideredPeriod = $period;

            if ($generated) {
                $created++;
            } else {
                $skipped++;
            }

            $period = ExpenseDateHelper::addMonths($period, $interval, $timezone);
            $iterations++;

            if ($iterations > 240) {
                throw new \RuntimeException('Recurring expense worker could not advance rule.');
            }
        }

        $this->rules->update((int) $rule['user_id'], $ruleId, [
            'next_generation_on' => $endsMonth !== null && $period > $endsMonth ? null : ($lastConsideredPeriod ?? $period),
        ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function createPeriod(array $rule, string $periodMonth): bool
    {
        $period = DateTimeImmutable::createFromFormat('!Y-m-d', $periodMonth, DateTimeHelper::timezone($this->timezone));

        if (!$period instanceof DateTimeImmutable) {
            throw new ExpenseValidationException('Periodo invalido.');
        }

        $adjustment = $this->adjustments?->findActiveByRuleAndPeriod((int) $rule['id'], $periodMonth);

        if (($adjustment['action'] ?? '') === ExpenseRecurringAdjustmentService::ACTION_SKIP) {
            return false;
        }

        $paymentMethodId = null;
        $rulePaymentMethodId = $rule['default_payment_method_id'] === null ? null : (int) $rule['default_payment_method_id'];
        $servicePaymentMethodId = $rule['service_default_payment_method_id'] === null ? null : (int) $rule['service_default_payment_method_id'];

        if ($rulePaymentMethodId !== null && (int) ($rule['default_payment_method_active'] ?? 0) === 1) {
            $paymentMethodId = $rulePaymentMethodId;
        } elseif ($servicePaymentMethodId !== null && (int) ($rule['service_default_payment_method_active'] ?? 0) === 1) {
            $paymentMethodId = $servicePaymentMethodId;
        }

        if (($adjustment['payment_method_id'] ?? null) !== null) {
            $paymentMethodId = (int) $adjustment['payment_method_id'];
        }

        $categoryId = $rule['default_category_id'] === null
            ? ($rule['service_category_id'] === null ? null : (int) $rule['service_category_id'])
            : (int) $rule['default_category_id'];

        if (($adjustment['category_id'] ?? null) !== null) {
            $categoryId = (int) $adjustment['category_id'];
        }

        $amount = $rule['default_amount_clp'] === null
            ? ($rule['service_default_amount_clp'] === null ? null : (int) $rule['service_default_amount_clp'])
            : (int) $rule['default_amount_clp'];

        if ((int) ($adjustment['amount_override'] ?? 0) === 1) {
            $amount = $adjustment['amount_clp'] === null ? null : (int) $adjustment['amount_clp'];
        }

        $dueOn = ExpenseDateHelper::resolveDayOfMonth(
            (int) $period->format('Y'),
            (int) $period->format('n'),
            (int) $rule['day_of_month'],
        );

        if (is_string($adjustment['due_on'] ?? null) && $adjustment['due_on'] !== '') {
            $dueOn = (string) $adjustment['due_on'];
        }

        return $this->rules->createGeneratedExpense((int) $rule['user_id'], [
            'service_id' => (int) $rule['service_id'],
            'recurring_rule_id' => (int) $rule['id'],
            'category_id' => $categoryId,
            'period_month' => $periodMonth,
            'description' => is_string($adjustment['description'] ?? null) && trim((string) $adjustment['description']) !== ''
                ? (string) $adjustment['description']
                : (string) $rule['service_name'],
            'amount_clp' => $amount,
            'due_on' => $dueOn,
            'payment_method_id' => $paymentMethodId,
            'status' => ExpenseService::STATUS_PENDING,
            'notes' => is_string($adjustment['notes'] ?? null) && trim((string) $adjustment['notes']) !== '' ? (string) $adjustment['notes'] : null,
        ]);
    }
}
