<?php
declare(strict_types=1);

namespace Modules\Expenses;

use App\Support\DateTimeHelper;
use DateTimeImmutable;

final class ExpenseService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const DERIVED_STATUS_OVERDUE = 'overdue';
    private const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];
    private const DESCRIPTION_MAX_LENGTH = 180;
    private const NOTES_MAX_LENGTH = 5000;

    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        return $this->createWithAmountPolicy($userId, $input, false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createImported(int $userId, array $input): array
    {
        return $this->createWithAmountPolicy($userId, $input, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createWithAmountPolicy(int $userId, array $input, bool $allowZeroAmount): array
    {
        $service = null;
        $serviceId = $this->nullableId($input['service_id'] ?? null, 'service_id');

        if ($serviceId !== null) {
            $service = $this->expenses->serviceForUser($userId, $serviceId);

            if ($service === null) {
                throw new ExpenseValidationException('Servicio de gasto invalido.');
            }
        }

        $categoryId = array_key_exists('category_id', $input)
            ? $this->categoryId($userId, $input['category_id'])
            : ($service === null ? null : ($service['category_id'] === null ? null : (int) $service['category_id']));

        $paymentMethodId = $this->paymentMethodId($userId, $input['payment_method_id'] ?? null);

        if (
            $paymentMethodId !== null
            && $serviceId !== null
            && !$this->expenses->serviceAllowsPaymentMethod($userId, $serviceId, $paymentMethodId)
            && !$this->expenses->paymentMethodIsActiveForUser($userId, $paymentMethodId)
        ) {
            throw new ExpenseValidationException('El medio de pago no esta permitido para ese servicio.');
        }

        [$installmentCurrent, $installmentTotal] = $this->installments(
            $input['installment_current'] ?? null,
            $input['installment_total'] ?? null,
        );

        $status = $this->status($input['status'] ?? self::STATUS_PENDING);
        $paidOn = $this->paidOnForStatus($status, $input['paid_on'] ?? null);
        $descriptionInput = $input['description'] ?? ($service['name'] ?? '');
        $id = $this->expenses->create($userId, [
            'service_id' => $serviceId,
            'category_id' => $categoryId,
            'period_month' => $this->periodMonth($input['period_month'] ?? null),
            'description' => $this->plainText($descriptionInput, self::DESCRIPTION_MAX_LENGTH, 'Descripcion invalida.'),
            'amount_clp' => $this->amount($input['amount_clp'] ?? null, $allowZeroAmount),
            'installment_current' => $installmentCurrent,
            'installment_total' => $installmentTotal,
            'due_on' => $this->optionalDate($input['due_on'] ?? null, 'Fecha limite invalida.'),
            'paid_on' => $paidOn,
            'payment_method_id' => $paymentMethodId,
            'status' => $status,
            'notes' => $this->optionalPlainText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
        ]);

        return $this->get($userId, $id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $expenseId, ?DateTimeImmutable $today = null): ?array
    {
        $expense = $this->expenses->findByIdForUser($userId, $this->positiveId($expenseId, 'id'));

        return $expense === null ? null : $this->withDerivedData($expense, $today);
    }

    /**
     * @return array<string, mixed>
     */
    public function listForMonth(int $userId, int $year, int $month, ?DateTimeImmutable $today = null, array $filters = [], string $sort = 'due'): array
    {
        $periodMonth = $this->periodMonthFromParts($year, $month);
        $validatedFilters = $this->filters($filters);
        $repositoryFilters = $validatedFilters;

        if (($repositoryFilters['status'] ?? '') === self::DERIVED_STATUS_OVERDUE) {
            $repositoryFilters['status'] = self::STATUS_PENDING;
        }

        $items = array_map(
            fn (array $expense): array => $this->withDerivedData($expense, $today),
            $this->expenses->listForMonth($userId, $periodMonth, $repositoryFilters),
        );
        $items = $this->filterDerivedStatus($items, $validatedFilters['status'] ?? '');
        $items = $this->sortItems($items, $sort);

        $summary = $this->summary($items);
        $monthSummaryItems = $this->hasActiveFilters($validatedFilters)
            ? array_map(
                fn (array $expense): array => $this->withDerivedData($expense, $today),
                $this->expenses->listForMonth($userId, $periodMonth),
            )
            : $items;
        $monthSummaryItems = $this->sortItems($monthSummaryItems, 'due');
        $monthSummary = $this->summary($monthSummaryItems);

        return [
            'period_month' => $periodMonth,
            'items' => $items,
            'all_items' => $monthSummaryItems,
            'filtered_summary' => $summary,
            'filters' => $validatedFilters,
            'sort' => in_array($sort, ['due', 'amount', 'service', 'status'], true) ? $sort : 'due',
        ] + $monthSummary;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach (['status', 'category_id', 'service_id', 'payment_method_id', 'search'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(array $items): array
    {
        $summary = [
            'total_amount_clp' => 0,
            'paid_amount_clp' => 0,
            'pending_amount_clp' => 0,
            'overdue_amount_clp' => 0,
            'cancelled_amount_clp' => 0,
            'unknown_amount_count' => 0,
            'pending_unknown_amount_count' => 0,
            'overdue_unknown_amount_count' => 0,
            'paid_unknown_amount_count' => 0,
            'cancelled_unknown_amount_count' => 0,
            'counts' => [
                'total' => 0,
                'paid' => 0,
                'pending' => 0,
                'overdue' => 0,
                'cancelled' => 0,
            ],
        ];

        foreach ($items as $item) {
            $amount = $item['amount_clp'] === null ? null : (int) $item['amount_clp'];

            if ($item['status'] === self::STATUS_PAID) {
                if ($amount === null) {
                    $summary['unknown_amount_count']++;
                    $summary['paid_unknown_amount_count']++;
                } else {
                    $summary['total_amount_clp'] += $amount;
                    $summary['paid_amount_clp'] += $amount;
                }

                $summary['counts']['total']++;
                $summary['counts']['paid']++;
                continue;
            }

            if ($item['status'] === self::STATUS_CANCELLED) {
                if ($amount === null) {
                    $summary['cancelled_unknown_amount_count']++;
                } else {
                    $summary['cancelled_amount_clp'] += $amount;
                }

                $summary['counts']['cancelled']++;
                continue;
            }

            if ($amount === null) {
                $summary['unknown_amount_count']++;
            } else {
                $summary['total_amount_clp'] += $amount;
            }

            $summary['counts']['total']++;

            if (($item['derived_status'] ?? '') === self::DERIVED_STATUS_OVERDUE) {
                if ($amount === null) {
                    $summary['overdue_unknown_amount_count']++;
                } else {
                    $summary['overdue_amount_clp'] += $amount;
                }

                $summary['counts']['overdue']++;
                continue;
            }

            if ($amount === null) {
                $summary['pending_unknown_amount_count']++;
            } else {
                $summary['pending_amount_clp'] += $amount;
            }

            $summary['counts']['pending']++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $expenseId, array $input): ?array
    {
        $expenseId = $this->positiveId($expenseId, 'id');
        $current = $this->expenses->findByIdForUser($userId, $expenseId);

        if ($current === null) {
            return null;
        }

        $data = [];
        $serviceId = array_key_exists('service_id', $input)
            ? $this->nullableId($input['service_id'], 'service_id')
            : ($current['service_id'] === null ? null : (int) $current['service_id']);
        $service = null;

        if ($serviceId !== null) {
            $service = $this->expenses->serviceForUser($userId, $serviceId);

            if ($service === null) {
                throw new ExpenseValidationException('Servicio de gasto invalido.');
            }
        }

        if (array_key_exists('service_id', $input)) {
            $data['service_id'] = $serviceId;
        }

        if (array_key_exists('category_id', $input)) {
            $data['category_id'] = $this->categoryId($userId, $input['category_id']);
        }

        if (array_key_exists('period_month', $input)) {
            $data['period_month'] = $this->periodMonth($input['period_month']);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $this->plainText($input['description'], self::DESCRIPTION_MAX_LENGTH, 'Descripcion invalida.');
        }

        if (array_key_exists('amount_clp', $input)) {
            $data['amount_clp'] = $this->amount($input['amount_clp']);
        }

        if (array_key_exists('installment_current', $input) || array_key_exists('installment_total', $input)) {
            [$data['installment_current'], $data['installment_total']] = $this->installments(
                $input['installment_current'] ?? $current['installment_current'],
                $input['installment_total'] ?? $current['installment_total'],
            );
        }

        if (array_key_exists('due_on', $input)) {
            $data['due_on'] = $this->optionalDate($input['due_on'], 'Fecha limite invalida.');
        }

        if (array_key_exists('paid_on', $input)) {
            $data['paid_on'] = $this->optionalDate($input['paid_on'], 'Fecha de pago invalida.');
        }

        if (array_key_exists('payment_method_id', $input)) {
            $data['payment_method_id'] = $this->paymentMethodId($userId, $input['payment_method_id']);
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        if (array_key_exists('notes', $input)) {
            $data['notes'] = $this->optionalPlainText($input['notes'], self::NOTES_MAX_LENGTH);
        }

        $effectivePaymentMethodId = array_key_exists('payment_method_id', $data)
            ? $data['payment_method_id']
            : ($current['payment_method_id'] === null ? null : (int) $current['payment_method_id']);

        $keptHistoricalPayment = $effectivePaymentMethodId !== null
            && $current['payment_method_id'] !== null
            && (int) $current['payment_method_id'] === $effectivePaymentMethodId
            && !array_key_exists('payment_method_id', $data);

        if (
            $effectivePaymentMethodId !== null
            && $serviceId !== null
            && !$keptHistoricalPayment
            && !$this->expenses->serviceAllowsPaymentMethod($userId, $serviceId, $effectivePaymentMethodId)
            && !$this->expenses->paymentMethodIsActiveForUser($userId, $effectivePaymentMethodId)
        ) {
            throw new ExpenseValidationException('El medio de pago no esta permitido para ese servicio.');
        }

        $effectiveStatus = $data['status'] ?? (string) $current['status'];
        $paidOnValue = array_key_exists('paid_on', $data) ? $data['paid_on'] : ($current['paid_on'] ?? null);
        $data['paid_on'] = $this->paidOnForStatus($effectiveStatus, $paidOnValue);

        $this->expenses->update($userId, $expenseId, $data);

        return $this->get($userId, $expenseId);
    }

    public function markPaid(int $userId, int $expenseId, mixed $paidOn = null): ?array
    {
        return $this->update($userId, $expenseId, [
            'status' => self::STATUS_PAID,
            'paid_on' => $paidOn === null || $paidOn === '' ? $this->todayString() : $paidOn,
        ]);
    }

    public function reopen(int $userId, int $expenseId): ?array
    {
        return $this->update($userId, $expenseId, [
            'status' => self::STATUS_PENDING,
            'paid_on' => null,
        ]);
    }

    public function cancel(int $userId, int $expenseId): ?array
    {
        return $this->update($userId, $expenseId, [
            'status' => self::STATUS_CANCELLED,
            'paid_on' => null,
        ]);
    }

    public function reactivate(int $userId, int $expenseId): ?array
    {
        return $this->reopen($userId, $expenseId);
    }

    public function delete(int $userId, int $expenseId): bool
    {
        return $this->expenses->delete($userId, $this->positiveId($expenseId, 'id'));
    }

    /**
     * @param array<string, mixed> $expense
     * @return array<string, mixed>
     */
    private function withDerivedData(array $expense, ?DateTimeImmutable $today = null): array
    {
        $today ??= DateTimeHelper::nowLocal($this->timezone);
        $localToday = $today->setTimezone(DateTimeHelper::timezone($this->timezone))->format('Y-m-d');
        $isOverdue = ($expense['status'] ?? '') === self::STATUS_PENDING
            && is_string($expense['due_on'] ?? null)
            && $expense['due_on'] !== ''
            && $expense['due_on'] < $localToday;

        $expense['is_overdue'] = $isOverdue;
        $expense['derived_status'] = $isOverdue ? self::DERIVED_STATUS_OVERDUE : (string) ($expense['status'] ?? '');

        return $expense;
    }

    private function periodMonth(mixed $value): string
    {
        $date = $this->date($value, 'Periodo invalido.');

        if (substr($date, 8, 2) !== '01') {
            throw new ExpenseValidationException('El periodo mensual debe usar el primer dia del mes.');
        }

        return $date;
    }

    private function periodMonthFromParts(int $year, int $month): string
    {
        if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) {
            throw new ExpenseValidationException('Periodo invalido.');
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function optionalDate(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $message);
    }

    private function date(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, DateTimeHelper::timezone($this->timezone));

        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new ExpenseValidationException($message);
        }

        return $value;
    }

    private function amount(mixed $value, bool $allowZero = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value)) {
            $normalized = str_replace(['$', '.', ' '], '', trim($value));

            if (preg_match('/\A[0-9]+\z/', $normalized) !== 1) {
                throw new ExpenseValidationException('Monto invalido.');
            }

            $amount = (int) $normalized;
        } else {
            throw new ExpenseValidationException('Monto invalido.');
        }

        if ($amount < 0 || (!$allowZero && $amount === 0)) {
            throw new ExpenseValidationException('Monto invalido.');
        }

        return $amount;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function installments(mixed $current, mixed $total): array
    {
        if (($current === null || $current === '') && ($total === null || $total === '')) {
            return [null, null];
        }

        if ($current === null || $current === '' || $total === null || $total === '') {
            throw new ExpenseValidationException('Cuotas invalidas.');
        }

        $current = $this->positiveInputNumber($current, 'installment_current');
        $total = $this->positiveInputNumber($total, 'installment_total');

        if ($current > $total) {
            throw new ExpenseValidationException('Cuotas invalidas.');
        }

        return [$current, $total];
    }

    private function categoryId(int $userId, mixed $categoryId): ?int
    {
        $categoryId = $this->nullableId($categoryId, 'category_id');

        if ($categoryId !== null && !$this->expenses->categoryBelongsToUser($userId, $categoryId)) {
            throw new ExpenseValidationException('Categoria invalida.');
        }

        return $categoryId;
    }

    private function paymentMethodId(int $userId, mixed $paymentMethodId): ?int
    {
        $paymentMethodId = $this->nullableId($paymentMethodId, 'payment_method_id');

        if ($paymentMethodId !== null && !$this->expenses->paymentMethodBelongsToUser($userId, $paymentMethodId)) {
            throw new ExpenseValidationException('Medio de pago invalido.');
        }

        return $paymentMethodId;
    }

    private function paidOnForStatus(string $status, mixed $paidOn): ?string
    {
        if ($status !== self::STATUS_PAID) {
            return null;
        }

        if ($paidOn === null || $paidOn === '') {
            return $this->todayString();
        }

        return $this->optionalDate($paidOn, 'Fecha de pago invalida.');
    }

    private function todayString(): string
    {
        return DateTimeHelper::nowLocal($this->timezone)->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function filters(array $filters): array
    {
        $clean = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_CANCELLED, self::DERIVED_STATUS_OVERDUE], true)) {
                throw new ExpenseValidationException('Filtro de estado invalido.');
            }

            $clean['status'] = $status;
        }

        foreach (['category_id', 'service_id', 'payment_method_id'] as $field) {
            if (!isset($filters[$field]) || $filters[$field] === '') {
                continue;
            }

            $clean[$field] = $this->nullableId($filters[$field], $field);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $length = function_exists('mb_strlen') ? mb_strlen($search) : strlen($search);

            if ($length > 120 || preg_match('/<[^>]*>/', $search) === 1) {
                throw new ExpenseValidationException('Busqueda invalida.');
            }

            $clean['search'] = $search;
        }

        return $clean;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function filterDerivedStatus(array $items, string $status): array
    {
        if ($status === '') {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($status): bool {
            if ($status === self::DERIVED_STATUS_OVERDUE) {
                return ($item['derived_status'] ?? '') === self::DERIVED_STATUS_OVERDUE;
            }

            if ($status === self::STATUS_PENDING) {
                return ($item['status'] ?? '') === self::STATUS_PENDING
                    && ($item['derived_status'] ?? '') !== self::DERIVED_STATUS_OVERDUE;
            }

            return ($item['status'] ?? '') === $status;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items, string $sort): array
    {
        $sort = in_array($sort, ['due', 'amount', 'service', 'status'], true) ? $sort : 'due';

        usort($items, function (array $left, array $right) use ($sort): int {
            if ($sort === 'amount') {
                $leftAmount = $left['amount_clp'] === null ? -1 : (int) $left['amount_clp'];
                $rightAmount = $right['amount_clp'] === null ? -1 : (int) $right['amount_clp'];

                return ($rightAmount <=> $leftAmount)
                    ?: $this->defaultSort($left, $right);
            }

            if ($sort === 'service') {
                return strcasecmp((string) ($left['description'] ?? ''), (string) ($right['description'] ?? ''))
                    ?: $this->defaultSort($left, $right);
            }

            if ($sort === 'status') {
                return $this->statusWeight($left) <=> $this->statusWeight($right)
                    ?: $this->defaultSort($left, $right);
            }

            return $this->defaultSort($left, $right);
        });

        return $items;
    }

    private function defaultSort(array $left, array $right): int
    {
        return $this->statusWeight($left) <=> $this->statusWeight($right)
            ?: strcmp((string) ($left['due_on'] ?? '9999-12-31'), (string) ($right['due_on'] ?? '9999-12-31'))
            ?: strcasecmp((string) ($left['description'] ?? ''), (string) ($right['description'] ?? ''))
            ?: ((int) $left['id'] <=> (int) $right['id']);
    }

    private function statusWeight(array $item): int
    {
        if (($item['derived_status'] ?? '') === self::DERIVED_STATUS_OVERDUE) {
            return 10;
        }

        if (($item['status'] ?? '') === self::STATUS_PENDING && !empty($item['due_on'])) {
            return 20;
        }

        if (($item['status'] ?? '') === self::STATUS_PENDING) {
            return 30;
        }

        if (($item['status'] ?? '') === self::STATUS_PAID) {
            return 40;
        }

        return 50;
    }

    private function status(mixed $status): string
    {
        $status = trim((string) $status);

        if (!in_array($status, self::STATUSES, true)) {
            throw new ExpenseValidationException('Estado de gasto invalido.');
        }

        return $status;
    }

    private function plainText(mixed $value, int $maxLength, string $message): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($value === '' || $length > $maxLength || preg_match('/<[^>]*>/', $value) === 1) {
            throw new ExpenseValidationException($message);
        }

        return $value;
    }

    private function optionalPlainText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength || preg_match('/<[^>]*>/', $value) === 1) {
            throw new ExpenseValidationException('Texto invalido.');
        }

        return $value === '' ? null : $value;
    }

    private function nullableId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $this->positiveId($value, $field);
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $this->positiveId((int) $value, $field);
        }

        throw new ExpenseValidationException("Identificador invalido: {$field}.");
    }

    private function positiveInputNumber(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $this->positiveId($value, $field);
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $this->positiveId((int) $value, $field);
        }

        throw new ExpenseValidationException("Identificador invalido: {$field}.");
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new ExpenseValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
