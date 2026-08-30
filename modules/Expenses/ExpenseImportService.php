<?php
declare(strict_types=1);

namespace Modules\Expenses;

use PDO;
use Throwable;

final class ExpenseImportService
{
    private const MAX_RECORDS = 300;

    /** @var array<string, array<string, mixed>> */
    private array $categoriesByName = [];

    /** @var array<string, array<string, mixed>> */
    private array $paymentMethodsByName = [];

    /** @var array<string, array<string, mixed>> */
    private array $servicesByName = [];

    /** @var array<string, mixed> */
    private array $summary = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExpenseCategoryService $categoryService,
        private readonly ExpensePaymentMethodService $paymentMethodService,
        private readonly ExpenseServiceDefinitionService $serviceDefinitionService,
        private readonly ExpenseService $expenseService,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    public function import(int $userId, array $payload): array
    {
        $records = $this->recordsFromPayload($payload);
        $totalRecords = count($records['categories']) + count($records['payment_methods']) + count($records['services']) + count($records['expenses']);

        if ($totalRecords === 0) {
            throw new ExpenseValidationException('El JSON no contiene elementos para importar.');
        }

        if ($totalRecords > self::MAX_RECORDS) {
            throw new ExpenseValidationException('El JSON supera el maximo de ' . self::MAX_RECORDS . ' elementos.');
        }

        $this->summary = [
            'categories_created' => 0,
            'categories_reused' => 0,
            'payment_methods_created' => 0,
            'payment_methods_reused' => 0,
            'services_created' => 0,
            'services_reused' => 0,
            'expenses_created' => 0,
            'service_payment_links_added' => 0,
            'created_category_ids' => [],
            'created_payment_method_ids' => [],
            'created_service_ids' => [],
            'created_expense_ids' => [],
        ];

        $this->loadMaps($userId);
        $this->pdo->beginTransaction();

        try {
            foreach ($records['categories'] as $index => $category) {
                $this->ensureCategory($userId, $category, 'categories[' . $index . ']');
            }

            foreach ($records['payment_methods'] as $index => $method) {
                $this->ensurePaymentMethod($userId, $method, 'payment_methods[' . $index . ']');
            }

            foreach ($records['services'] as $index => $service) {
                $this->ensureService($userId, $service, 'services[' . $index . ']');
            }

            foreach ($records['expenses'] as $index => $expense) {
                $this->createExpense($userId, $this->expenseRecord($expense, 'expenses[' . $index . ']'), 'expenses[' . $index . ']');
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $this->summary;
    }

    /**
     * @param array<mixed> $payload
     * @return array{categories: array<int, mixed>, payment_methods: array<int, mixed>, services: array<int, mixed>, expenses: array<int, mixed>}
     */
    private function recordsFromPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return [
                'categories' => [],
                'payment_methods' => [],
                'services' => [],
                'expenses' => $payload,
            ];
        }

        $records = [
            'categories' => $this->sectionRecords($payload, 'categories', 'category', true),
            'payment_methods' => $this->sectionRecords($payload, 'payment_methods', 'payment_method', true),
            'services' => $this->sectionRecords($payload, 'services', 'service', true),
            'expenses' => $this->sectionRecords($payload, 'expenses', 'expense', false),
        ];

        if (array_key_exists('items', $payload)) {
            if (!is_array($payload['items']) || !array_is_list($payload['items'])) {
                throw new ExpenseValidationException('items debe ser una lista.');
            }

            foreach ($payload['items'] as $index => $item) {
                if (!is_array($item)) {
                    throw new ExpenseValidationException('items[' . $index . '] debe ser un objeto.');
                }

                $type = $this->text($item['type'] ?? '', 'items[' . $index . '].type');
                $itemData = $item;
                unset($itemData['type']);

                if ($type === 'category') {
                    $records['categories'][] = $itemData;
                } elseif ($type === 'payment_method') {
                    $records['payment_methods'][] = $itemData;
                } elseif ($type === 'service') {
                    $records['services'][] = $itemData;
                } elseif ($type === 'expense') {
                    $records['expenses'][] = $itemData;
                } else {
                    throw new ExpenseValidationException('Tipo de item invalido en items[' . $index . '].');
                }
            }
        }

        return $records;
    }

    /**
     * @param array<mixed> $payload
     * @return array<int, mixed>
     */
    private function sectionRecords(array $payload, string $plural, string $singular, bool $allowString): array
    {
        $records = [];

        if (array_key_exists($singular, $payload)) {
            $records[] = $this->sectionRecord($payload[$singular], $singular, $allowString);
        }

        if (!array_key_exists($plural, $payload)) {
            return $records;
        }

        if (!is_array($payload[$plural]) || !array_is_list($payload[$plural])) {
            throw new ExpenseValidationException($plural . ' debe ser una lista.');
        }

        foreach ($payload[$plural] as $index => $record) {
            $records[] = $this->sectionRecord($record, $plural . '[' . $index . ']', $allowString);
        }

        return $records;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function sectionRecord(mixed $record, string $path, bool $allowString): array|string
    {
        if ($allowString && is_string($record)) {
            return $record;
        }

        if (!is_array($record) || array_is_list($record)) {
            throw new ExpenseValidationException($path . ' debe ser un objeto.');
        }

        return $record;
    }

    private function loadMaps(int $userId): void
    {
        $this->categoriesByName = [];
        foreach ($this->categoryService->list($userId) as $category) {
            $this->categoriesByName[$this->normalizedName((string) ($category['name'] ?? ''))] = $category;
        }

        $this->paymentMethodsByName = [];
        foreach ($this->paymentMethodService->list($userId) as $method) {
            $this->paymentMethodsByName[$this->normalizedName((string) ($method['name'] ?? ''))] = $method;
        }

        $this->servicesByName = [];
        foreach ($this->serviceDefinitionService->list($userId) as $service) {
            $this->servicesByName[$this->normalizedName((string) ($service['name'] ?? ''))] = $service;
        }
    }

    private function ensureCategory(int $userId, mixed $value, string $path): ?int
    {
        if ($this->isEmptyReference($value)) {
            return null;
        }

        $record = $this->referenceRecord($value, $path);
        $name = $this->text($record['name'] ?? '', $path . '.name');
        $key = $this->normalizedName($name);

        if (isset($this->categoriesByName[$key])) {
            $this->summary['categories_reused']++;
            return (int) $this->categoriesByName[$key]['id'];
        }

        $category = $this->categoryService->create($userId, [
            'name' => $name,
            'color' => $record['color'] ?? null,
            'active' => $this->optionalBool($record['active'] ?? true, $path . '.active'),
        ]);
        $this->categoriesByName[$key] = $category;
        $this->summary['categories_created']++;
        $this->summary['created_category_ids'][] = (int) $category['id'];

        return (int) $category['id'];
    }

    private function ensurePaymentMethod(int $userId, mixed $value, string $path): ?int
    {
        if ($this->isEmptyReference($value)) {
            return null;
        }

        $record = $this->referenceRecord($value, $path);
        $name = $this->text($record['name'] ?? '', $path . '.name');
        $key = $this->normalizedName($name);

        if (isset($this->paymentMethodsByName[$key])) {
            $this->summary['payment_methods_reused']++;
            return (int) $this->paymentMethodsByName[$key]['id'];
        }

        $method = $this->paymentMethodService->create($userId, [
            'name' => $name,
            'type' => $record['type'] ?? 'other',
            'institution_name' => $record['institution_name'] ?? null,
            'notes' => $record['notes'] ?? null,
            'active' => $this->optionalBool($record['active'] ?? true, $path . '.active'),
        ]);
        $this->paymentMethodsByName[$key] = $method;
        $this->summary['payment_methods_created']++;
        $this->summary['created_payment_method_ids'][] = (int) $method['id'];

        return (int) $method['id'];
    }

    private function ensureService(int $userId, mixed $value, string $path, ?int $fallbackCategoryId = null, ?int $fallbackPaymentMethodId = null): ?int
    {
        if ($this->isEmptyReference($value)) {
            return null;
        }

        $record = $this->referenceRecord($value, $path);
        $name = $this->text($record['name'] ?? '', $path . '.name');
        $key = $this->normalizedName($name);
        $categoryId = $this->referenceId($record['category_id'] ?? null, $path . '.category_id')
            ?? $this->ensureCategory($userId, $record['category'] ?? ($record['category_name'] ?? null), $path . '.category')
            ?? $fallbackCategoryId;
        $paymentMethodIds = $this->paymentMethodIdsFromRecord($userId, $record, $path);

        if ($fallbackPaymentMethodId !== null && !in_array($fallbackPaymentMethodId, $paymentMethodIds, true)) {
            $paymentMethodIds[] = $fallbackPaymentMethodId;
        }

        $defaultPaymentMethodId = $this->paymentMethodDefaultId($userId, $record, $path, $paymentMethodIds);

        if ($defaultPaymentMethodId !== null && !in_array($defaultPaymentMethodId, $paymentMethodIds, true)) {
            $paymentMethodIds[] = $defaultPaymentMethodId;
        }

        if (isset($this->servicesByName[$key])) {
            $service = $this->mergeServicePaymentMethods($userId, (int) $this->servicesByName[$key]['id'], $paymentMethodIds, $defaultPaymentMethodId);
            $this->servicesByName[$key] = $service;
            $this->summary['services_reused']++;

            return (int) $service['id'];
        }

        $service = $this->serviceDefinitionService->create($userId, [
            'name' => $name,
            'category_id' => $categoryId,
            'default_amount_clp' => $record['default_amount_clp'] ?? null,
            'notes' => $record['notes'] ?? null,
            'active' => $this->optionalBool($record['active'] ?? true, $path . '.active'),
            'payment_method_ids' => $paymentMethodIds,
            'default_payment_method_id' => $defaultPaymentMethodId,
        ]);
        $this->servicesByName[$key] = $service;
        $this->summary['services_created']++;
        $this->summary['created_service_ids'][] = (int) $service['id'];

        return (int) $service['id'];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<int, int>
     */
    private function paymentMethodIdsFromRecord(int $userId, array $record, string $path): array
    {
        $ids = [];

        if (array_key_exists('payment_method_ids', $record)) {
            if (!is_array($record['payment_method_ids'])) {
                throw new ExpenseValidationException($path . '.payment_method_ids debe ser una lista.');
            }

            foreach ($record['payment_method_ids'] as $index => $id) {
                $resolved = $this->referenceId($id, $path . '.payment_method_ids[' . $index . ']');
                if ($resolved !== null) {
                    $ids[] = $resolved;
                }
            }
        }

        if (array_key_exists('payment_methods', $record)) {
            if (!is_array($record['payment_methods']) || !array_is_list($record['payment_methods'])) {
                throw new ExpenseValidationException($path . '.payment_methods debe ser una lista.');
            }

            foreach ($record['payment_methods'] as $index => $method) {
                $id = $this->ensurePaymentMethod($userId, $method, $path . '.payment_methods[' . $index . ']');
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        $singleMethod = $record['payment_method'] ?? ($record['payment_method_name'] ?? null);
        $singleMethodId = $this->referenceId($record['payment_method_id'] ?? null, $path . '.payment_method_id');

        if ($singleMethodId !== null) {
            $ids[] = $singleMethodId;
        }

        if (!$this->isEmptyReference($singleMethod)) {
            $id = $this->ensurePaymentMethod($userId, $singleMethod, $path . '.payment_method');
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, int> $paymentMethodIds
     */
    private function paymentMethodDefaultId(int $userId, array $record, string $path, array $paymentMethodIds): ?int
    {
        $defaultId = $this->referenceId($record['default_payment_method_id'] ?? null, $path . '.default_payment_method_id');

        if ($defaultId !== null) {
            return $defaultId;
        }

        $default = $record['default_payment_method'] ?? ($record['default_payment_method_name'] ?? null);

        if (!$this->isEmptyReference($default)) {
            return $this->ensurePaymentMethod($userId, $default, $path . '.default_payment_method');
        }

        return count($paymentMethodIds) === 1 ? $paymentMethodIds[0] : null;
    }

    /**
     * @param array<int, int> $paymentMethodIds
     * @return array<string, mixed>
     */
    private function mergeServicePaymentMethods(int $userId, int $serviceId, array $paymentMethodIds, ?int $defaultPaymentMethodId): array
    {
        if ($paymentMethodIds === [] && $defaultPaymentMethodId === null) {
            return $this->serviceDefinitionService->get($userId, $serviceId) ?? [];
        }

        $current = $this->serviceDefinitionService->get($userId, $serviceId);

        if ($current === null) {
            throw new ExpenseValidationException('Servicio de gasto invalido.');
        }

        $currentIds = [];
        $currentDefaultId = null;

        foreach ((array) ($current['payment_methods'] ?? []) as $method) {
            $methodId = (int) ($method['id'] ?? 0);
            if ($methodId > 0) {
                $currentIds[] = $methodId;
            }

            if ((int) ($method['is_default'] ?? 0) === 1) {
                $currentDefaultId = $methodId;
            }
        }

        $nextIds = array_values(array_unique(array_merge($currentIds, $paymentMethodIds)));
        $nextDefaultId = $defaultPaymentMethodId ?? $currentDefaultId;

        if ($nextDefaultId !== null && !in_array($nextDefaultId, $nextIds, true)) {
            $nextIds[] = $nextDefaultId;
        }

        $updated = $this->serviceDefinitionService->update($userId, $serviceId, [
            'payment_method_ids' => $nextIds,
            'default_payment_method_id' => $nextDefaultId,
        ]);

        if ($updated === null) {
            throw new ExpenseValidationException('Servicio de gasto invalido.');
        }

        $added = count(array_diff($nextIds, $currentIds));
        $this->summary['service_payment_links_added'] += $added;

        return $updated;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function createExpense(int $userId, array $record, string $path): void
    {
        $categoryId = $this->referenceId($record['category_id'] ?? null, $path . '.category_id')
            ?? $this->ensureCategory($userId, $record['category'] ?? ($record['category_name'] ?? null), $path . '.category');
        $paymentMethodId = $this->referenceId($record['payment_method_id'] ?? null, $path . '.payment_method_id')
            ?? $this->ensurePaymentMethod($userId, $record['payment_method'] ?? ($record['payment_method_name'] ?? null), $path . '.payment_method');
        $serviceInput = $record['service'] ?? ($record['service_name'] ?? null);
        $serviceId = $this->referenceId($record['service_id'] ?? null, $path . '.service_id');

        if ($serviceId === null && !$this->isEmptyReference($serviceInput)) {
            $serviceId = $this->ensureService($userId, $serviceInput, $path . '.service', $categoryId, $paymentMethodId);
        } elseif ($serviceId !== null && $paymentMethodId !== null) {
            $this->mergeServicePaymentMethods($userId, $serviceId, [$paymentMethodId], null);
        }

        $description = $record['description'] ?? null;

        if (($description === null || $description === '') && !$this->isEmptyReference($serviceInput)) {
            $description = is_array($serviceInput) ? ($serviceInput['name'] ?? '') : $serviceInput;
        }

        $expense = $this->expenseService->createImported($userId, [
            'period_month' => $this->periodMonth($record['period_month'] ?? ($record['month'] ?? null), $path . '.period_month'),
            'service_id' => $serviceId,
            'category_id' => $categoryId,
            'description' => $description,
            'amount_clp' => array_key_exists('amount_clp', $record) ? $record['amount_clp'] : ($record['amount'] ?? null),
            'installment_current' => $record['installment_current'] ?? null,
            'installment_total' => $record['installment_total'] ?? null,
            'due_on' => $record['due_on'] ?? null,
            'paid_on' => $record['paid_on'] ?? null,
            'payment_method_id' => $paymentMethodId,
            'status' => $record['status'] ?? ExpenseService::STATUS_PENDING,
            'notes' => $record['notes'] ?? null,
        ]);

        $this->summary['expenses_created']++;
        $this->summary['created_expense_ids'][] = (int) $expense['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRecord(mixed $record, string $path): array
    {
        if (!is_array($record) || array_is_list($record)) {
            throw new ExpenseValidationException($path . ' debe ser un objeto.');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceRecord(mixed $value, string $path): array
    {
        if (is_string($value)) {
            return ['name' => $value];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new ExpenseValidationException($path . ' debe ser texto u objeto.');
        }

        return $value;
    }

    private function isEmptyReference(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }

    private function referenceId(mixed $value, string $path): ?int
    {
        if ($this->isEmptyReference($value)) {
            return null;
        }

        if (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1)) {
            return (int) $value;
        }

        throw new ExpenseValidationException($path . ' debe ser un id positivo, null o 0.');
    }

    private function periodMonth(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new ExpenseValidationException($path . ' debe venir en formato YYYY-MM o YYYY-MM-01.');
        }

        $value = trim($value);

        if (preg_match('/\A(19|20|21|22)[0-9]{2}-(0[1-9]|1[0-2])\z/', $value) === 1) {
            return $value . '-01';
        }

        if (preg_match('/\A(19|20|21|22)[0-9]{2}-(0[1-9]|1[0-2])-01\z/', $value) === 1) {
            return $value;
        }

        throw new ExpenseValidationException($path . ' debe venir en formato YYYY-MM o YYYY-MM-01.');
    }

    private function text(mixed $value, string $path): string
    {
        if (!is_scalar($value)) {
            throw new ExpenseValidationException($path . ' debe ser texto.');
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($text === '') {
            throw new ExpenseValidationException($path . ' es obligatorio.');
        }

        return $text;
    }

    private function optionalBool(mixed $value, string $path): bool|int|string
    {
        if (is_bool($value) || (is_int($value) && ($value === 0 || $value === 1)) || (is_string($value) && in_array($value, ['0', '1'], true))) {
            return $value;
        }

        throw new ExpenseValidationException($path . ' debe ser true, false, 1 o 0.');
    }

    private function normalizedName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }
}
