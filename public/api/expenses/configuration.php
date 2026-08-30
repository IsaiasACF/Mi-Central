<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRecurringAdjustmentRepository;
use Modules\Expenses\ExpenseRecurringAdjustmentService;
use Modules\Expenses\ExpenseRecurringRuleRepository;
use Modules\Expenses\ExpenseRecurringRuleService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
use Modules\Expenses\ExpenseValidationException;
use Modules\Expenses\RecurringExpenseWorker;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = Connection::get();
$categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
$paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
$serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
$recurringRuleRepository = new ExpenseRecurringRuleRepository($pdo);
$recurringAdjustmentRepository = new ExpenseRecurringAdjustmentRepository($pdo);
$recurringAdjustmentService = new ExpenseRecurringAdjustmentService(
    $recurringAdjustmentRepository,
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
);
$recurringRuleService = new ExpenseRecurringRuleService(
    $recurringRuleRepository,
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
);
$recurringWorker = new RecurringExpenseWorker(
    $recurringRuleRepository,
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
    $recurringAdjustmentRepository,
);

try {
    if ($method === 'GET') {
        JsonResponse::send(['ok' => true, 'data' => expensesConfigurationState(
            $userId,
            $categoryService,
            $paymentMethodService,
            $serviceDefinitionService,
            $recurringAdjustmentService,
        )]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = expensesConfigurationPayload();

    if (!Csrf::validate(expensesConfigurationCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';
    $result = null;
    $message = '';

    if ($action === 'create-category') {
        $result = $categoryService->create($userId, $payload);
        $message = 'Categoria creada.';
    } elseif ($action === 'update-category') {
        $result = $categoryService->update($userId, expensesConfigurationId($payload), $payload);
        $message = 'Categoria actualizada.';
    } elseif ($action === 'deactivate-category' || $action === 'reactivate-category') {
        $result = $categoryService->update($userId, expensesConfigurationId($payload), [
            'active' => $action === 'reactivate-category' ? 1 : 0,
        ]);
        $message = $action === 'reactivate-category' ? 'Categoria reactivada.' : 'Categoria desactivada.';
    } elseif ($action === 'create-payment-method') {
        $result = $paymentMethodService->create($userId, $payload);
        $message = 'Medio de pago creado.';
    } elseif ($action === 'update-payment-method') {
        $result = $paymentMethodService->update($userId, expensesConfigurationId($payload), $payload);
        $message = 'Medio de pago actualizado.';
    } elseif ($action === 'deactivate-payment-method' || $action === 'reactivate-payment-method') {
        $result = $paymentMethodService->update($userId, expensesConfigurationId($payload), [
            'active' => $action === 'reactivate-payment-method' ? 1 : 0,
        ]);
        $message = $action === 'reactivate-payment-method' ? 'Medio de pago reactivado.' : 'Medio de pago desactivado.';
    } elseif ($action === 'create-service') {
        $result = $serviceDefinitionService->create($userId, expensesConfigurationServicePayload($payload));
        $message = 'Servicio guardado.';
    } elseif ($action === 'update-service') {
        $result = $serviceDefinitionService->update($userId, expensesConfigurationId($payload), expensesConfigurationServicePayload($payload));
        $message = 'Servicio guardado.';
    } elseif ($action === 'deactivate-service' || $action === 'reactivate-service') {
        $result = $serviceDefinitionService->update($userId, expensesConfigurationId($payload), [
            'active' => $action === 'reactivate-service' ? 1 : 0,
        ]);
        $message = $action === 'reactivate-service' ? 'Servicio reactivado.' : 'Servicio desactivado.';
    } elseif ($action === 'save-recurring-rule') {
        $result = $recurringRuleService->saveForService($userId, expensesConfigurationServiceId($payload), $payload);
        if ((int) ($result['active'] ?? 0) === 1) {
            $recurringWorker->process(null, (int) $result['id']);
        }
        $message = 'Recurrencia guardada.';
    } elseif ($action === 'deactivate-recurring-rule' || $action === 'reactivate-recurring-rule') {
        $result = $recurringRuleService->setActive(
            $userId,
            expensesConfigurationId($payload),
            $action === 'reactivate-recurring-rule',
        );
        if ($result !== null && (int) ($result['active'] ?? 0) === 1) {
            $recurringWorker->process(null, (int) $result['id']);
        }
        $message = $action === 'reactivate-recurring-rule' ? 'Recurrencia reactivada.' : 'Recurrencia desactivada.';
    } elseif ($action === 'save-recurring-adjustment') {
        $result = $recurringAdjustmentService->saveForService($userId, expensesConfigurationServiceId($payload), $payload);
        $message = 'Ajuste de recurrencia guardado.';
    } elseif ($action === 'delete-recurring-adjustment') {
        $deleted = $recurringAdjustmentService->delete($userId, expensesConfigurationId($payload));
        $result = $deleted ? ['deleted' => true] : null;
        $message = 'Ajuste de recurrencia eliminado.';
    } else {
        JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
    }

    if ($result === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Registro no encontrado.'], 404);
    }

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'message' => $message,
            'item' => $result,
            'state' => expensesConfigurationState(
                $userId,
                $categoryService,
                $paymentMethodService,
                $serviceDefinitionService,
                $recurringAdjustmentService,
            ),
        ],
    ], str_starts_with($action, 'create-') ? 201 : 200);
} catch (ExpenseValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Expenses configuration API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @return array<string, mixed>
 */
function expensesConfigurationState(
    int $userId,
    ExpenseCategoryService $categoryService,
    ExpensePaymentMethodService $paymentMethodService,
    ExpenseServiceDefinitionService $serviceDefinitionService,
    ?ExpenseRecurringAdjustmentService $recurringAdjustmentService = null,
): array {
    $services = $serviceDefinitionService->list($userId);
    $activeServices = $serviceDefinitionService->list($userId, true);

    if ($recurringAdjustmentService !== null) {
        $services = expensesConfigurationAttachAdjustments($userId, $services, $recurringAdjustmentService);
        $activeServices = expensesConfigurationAttachAdjustments($userId, $activeServices, $recurringAdjustmentService);
    }

    return [
        'categories' => $categoryService->list($userId),
        'active_categories' => $categoryService->list($userId, true),
        'payment_methods' => $paymentMethodService->list($userId),
        'active_payment_methods' => $paymentMethodService->list($userId, true),
        'services' => $services,
        'active_services' => $activeServices,
        'payment_method_type_labels' => expensesPaymentMethodTypeLabels(),
    ];
}

/**
 * @param array<int, array<string, mixed>> $services
 * @return array<int, array<string, mixed>>
 */
function expensesConfigurationAttachAdjustments(int $userId, array $services, ExpenseRecurringAdjustmentService $recurringAdjustmentService): array
{
    foreach ($services as $index => $service) {
        $services[$index]['recurring_adjustments'] = $recurringAdjustmentService->listForService($userId, (int) ($service['id'] ?? 0));
    }

    return $services;
}

/**
 * @return array<string, string>
 */
function expensesPaymentMethodTypeLabels(): array
{
    return [
        'card' => 'Tarjeta',
        'bank_account' => 'Cuenta bancaria',
        'automatic_payment' => 'Pago automatico / PAT-PAC',
        'wallet' => 'Billetera digital',
        'webpay' => 'WebPay',
        'transfer' => 'Transferencia',
        'cash' => 'Efectivo',
        'other' => 'Otro',
    ];
}

/**
 * @return array<string, mixed>
 */
function expensesConfigurationPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '' && str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function expensesConfigurationCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}

/**
 * @param array<string, mixed> $payload
 */
function expensesConfigurationId(array $payload): int
{
    return (int) ($payload['id'] ?? 0);
}

/**
 * @param array<string, mixed> $payload
 */
function expensesConfigurationServiceId(array $payload): int
{
    return (int) ($payload['service_id'] ?? 0);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function expensesConfigurationServicePayload(array $payload): array
{
    $paymentMethodIds = $payload['payment_method_ids'] ?? [];

    if (isset($payload['payment_method_ids[]']) && !isset($payload['payment_method_ids'])) {
        $paymentMethodIds = $payload['payment_method_ids[]'];
    }

    return $payload + [
        'payment_method_ids' => $paymentMethodIds,
        'default_payment_method_id' => $payload['default_payment_method_id'] ?? null,
    ];
}
