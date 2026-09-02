<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionFormat;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\DiscountValidationException;

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
$merchantService = new DiscountMerchantService(new DiscountMerchantRepository($pdo));
$service = new DiscountPromotionService(new DiscountPromotionRepository($pdo), $merchantService, $programService);

try {
    if ($method === 'GET') {
        $promotionId = discountPromotionIdFromRequest();

        if ($promotionId !== null) {
            $promotion = $service->getManual($userId, $promotionId);

            if ($promotion === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Promocion no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => [
                'promotion' => $promotion,
                'labels' => discountPromotionLabels(),
            ]]);
        }

        JsonResponse::send(['ok' => true, 'data' => [
            'promotions' => $service->listManual($userId, $_GET),
            'labels' => discountPromotionLabels(),
        ]]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = discountPromotionPayload();

    if (!Csrf::validate(discountPromotionCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';

    if (in_array($action, ['create', 'update', 'deactivate', 'activate', 'delete'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Las promociones se administran automaticamente desde recolectores.'], 410);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
} catch (DiscountValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Discount promotions API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @return array<string, mixed>
 */
function discountPromotionPayload(): array
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
function discountPromotionCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}

function discountPromotionIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @param array<string, mixed> $payload
 */
function discountPromotionIdFromPayload(array $payload): int
{
    return (int) ($payload['id'] ?? 0);
}

/**
 * @return array<string, mixed>
 */
function discountPromotionLabels(): array
{
    return [
        'discount_types' => DiscountPromotionFormat::discountTypeLabels(),
        'channels' => DiscountPromotionFormat::channelLabels(),
        'weekdays' => DiscountPromotionFormat::WEEKDAYS,
    ];
}
