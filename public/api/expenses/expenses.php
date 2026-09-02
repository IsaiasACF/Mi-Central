<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Expenses\ExpenseValidationException;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$expenseService = new ExpenseService(new ExpenseRepository(Connection::get()));

try {
    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = expensesMonthlyPayload();

    if (!Csrf::validate(expensesMonthlyCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';
    $result = null;
    $message = '';
    $statusCode = 200;

    if ($action === 'create') {
        $result = $expenseService->create($userId, $payload);
        $message = 'Gasto creado.';
        $statusCode = 201;
    } elseif ($action === 'update') {
        $result = $expenseService->update($userId, expensesMonthlyId($payload), $payload);
        $message = 'Gasto actualizado.';
    } elseif ($action === 'mark-paid') {
        $result = $expenseService->markPaid($userId, expensesMonthlyId($payload), $payload['paid_on'] ?? null);
        $message = 'Gasto marcado como pagado.';
    } elseif ($action === 'reopen') {
        $result = $expenseService->reopen($userId, expensesMonthlyId($payload));
        $message = 'Gasto vuelto a pendiente.';
    } elseif ($action === 'cancel') {
        $result = $expenseService->cancel($userId, expensesMonthlyId($payload));
        $message = 'Gasto cancelado.';
    } elseif ($action === 'reactivate') {
        $result = $expenseService->reactivate($userId, expensesMonthlyId($payload));
        $message = 'Gasto reactivado.';
    } else {
        JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
    }

    if ($result === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Gasto no encontrado.'], 404);
    }

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'message' => $message,
            'item' => $result,
        ],
    ], $statusCode);
} catch (ExpenseValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Expenses monthly API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @return array<string, mixed>
 */
function expensesMonthlyPayload(): array
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
function expensesMonthlyCsrfToken(array $payload): ?string
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
function expensesMonthlyId(array $payload): int
{
    return (int) ($payload['id'] ?? 0);
}
