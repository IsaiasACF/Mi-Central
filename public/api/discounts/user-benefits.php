<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountBenefitType;
use Modules\Discounts\DiscountValidationException;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Discounts\UserDiscountBenefitService;

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
$programService = new DiscountBenefitProgramService(new DiscountBenefitProgramRepository($pdo));
$service = new UserDiscountBenefitService(new UserDiscountBenefitRepository($pdo), $programService);

try {
    if ($method === 'GET') {
        JsonResponse::send(['ok' => true, 'data' => discountsPayload($userId, $service, $programService)]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = discountRequestPayload();

    if (!Csrf::validate(discountCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';

    if ($action === 'add-existing') {
        JsonResponse::send([
            'ok' => true,
            'data' => [
                'benefit' => $service->create($userId, $payload),
                'state' => discountsPayload($userId, $service, $programService),
            ],
        ], 201);
    }

    if ($action === 'toggle-program') {
        $programId = (int) ($payload['benefit_program_id'] ?? 0);
        $enabled = (bool) ($payload['enabled'] ?? false);
        $service->setProgramActive($userId, $programId, $enabled);

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'enabled' => $enabled,
                'benefit_program_id' => $programId,
                'state' => discountsPayload($userId, $service, $programService),
            ],
        ]);
    }

    if ($action === 'create-and-add') {
        JsonResponse::send(['ok' => false, 'error' => 'El catalogo de beneficios se administra desde fuentes controladas.'], 410);
    }

    if ($action === 'update') {
        $benefit = $service->update($userId, discountIdFromPayload($payload), $payload);

        if ($benefit === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Beneficio no encontrado.'], 404);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'benefit' => $benefit,
                'state' => discountsPayload($userId, $service, $programService),
            ],
        ]);
    }

    if ($action === 'remove') {
        if (!$service->deactivate($userId, discountIdFromPayload($payload))) {
            JsonResponse::send(['ok' => false, 'error' => 'Beneficio no encontrado.'], 404);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'removed' => true,
                'state' => discountsPayload($userId, $service, $programService),
            ],
        ]);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
} catch (DiscountValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Discount user benefits API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @return array<string, mixed>
 */
function discountsPayload(
    int $userId,
    UserDiscountBenefitService $service,
    DiscountBenefitProgramService $programService,
): array {
    return [
        'benefits' => $service->listActive($userId),
        'programs' => $programService->listActive(),
        'type_labels' => DiscountBenefitType::labels(),
    ];
}

/**
 * @return array<string, mixed>
 */
function discountRequestPayload(): array
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
function discountCsrfToken(array $payload): ?string
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
function discountIdFromPayload(array $payload): int
{
    return (int) ($payload['id'] ?? 0);
}
