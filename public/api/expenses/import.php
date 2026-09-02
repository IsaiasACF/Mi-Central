<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpenseImportService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
use Modules\Expenses\ExpenseValidationException;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
}

$payload = expensesImportPayload();

if (!Csrf::validate(expensesImportCsrfToken($payload))) {
    JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$pdo = Connection::get();

try {
    $importService = new ExpenseImportService(
        $pdo,
        new ExpenseCategoryService(new ExpenseCategoryRepository($pdo)),
        new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo)),
        new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo)),
        new ExpenseService(new ExpenseRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago')),
    );
    $data = $payload['data'] ?? $payload;

    if (!is_array($data)) {
        throw new ExpenseValidationException('data debe ser un objeto o una lista.');
    }

    $summary = $importService->import($userId, $data);

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'message' => 'Importacion completada.',
            'summary' => $summary,
        ],
    ], 201);
} catch (ExpenseValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Expenses import API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo importar el JSON.'], 500);
}

/**
 * @return array<string, mixed>
 */
function expensesImportPayload(): array
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
function expensesImportCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
