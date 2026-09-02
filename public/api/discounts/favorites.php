<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Discounts\DiscountCompatibilityService;
use Modules\Discounts\DiscountDiscoveryService;
use Modules\Discounts\DiscountPromotionAvailabilityService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountValidationException;
use Modules\Discounts\UserDiscountBenefitRepository;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
}

$payload = discountFavoritePayload();

if (!Csrf::validate(discountFavoriteCsrfToken($payload))) {
    JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$favorite = filter_var($payload['favorite'] ?? false, FILTER_VALIDATE_BOOLEAN);
$promotionId = (int) ($payload['promotion_id'] ?? 0);
$pdo = Connection::get();
$promotionRepository = new DiscountPromotionRepository($pdo);
$service = new DiscountDiscoveryService(
    $promotionRepository,
    new DiscountCompatibilityService($promotionRepository, new UserDiscountBenefitRepository($pdo)),
    new DiscountPromotionAvailabilityService((string) ($config['app']['timezone'] ?? 'America/Santiago')),
);

try {
    $isFavorite = $service->setFavorite($userId, $promotionId, $favorite);

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'promotion_id' => $promotionId,
            'favorite' => $isFavorite,
        ],
    ]);
} catch (DiscountValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Discount favorites API failed: ' . $exception->getMessage());
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo actualizar el favorito.'], 500);
}

/**
 * @return array<string, mixed>
 */
function discountFavoritePayload(): array
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
function discountFavoriteCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
