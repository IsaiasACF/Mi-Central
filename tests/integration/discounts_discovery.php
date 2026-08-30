<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionAvailabilityService;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Discounts\UserDiscountBenefitService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$suffix = bin2hex(random_bytes(4));
$username = 'test_discount_discovery_' . $suffix;
$otherUsername = 'test_discount_discovery_other_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-discovery-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-discovery-other-');
$userIds = [];
$programIds = [];
$promotionIds = [];
$merchantIds = [];
$exitCode = 1;

function discount_discovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discount_discovery_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $jsonPayload = null, array $headers = [], array $postFields = []): array
{
    $handle = curl_init($url);
    $requestHeaders = [];

    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method !== 'GET') {
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);

        if ($jsonPayload !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
            $requestHeaders[] = 'Accept: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($jsonPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($postFields !== []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function discount_discovery_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function discount_discovery_json(array $response): array
{
    $decoded = json_decode($response['body'], true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: ' . $response['body']);
    }

    return $decoded;
}

function discount_discovery_login(string $username, string $password, string $cookieFile): string
{
    $loginPage = discount_discovery_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    discount_discovery_assert((int) $loginPage['status'] === 200, 'Login page did not load.');
    $login = discount_discovery_http('http://127.0.0.1/login.php', 'POST', $cookieFile, null, [], [
        'csrf_token' => discount_discovery_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ]);
    discount_discovery_assert((int) $login['status'] === 302, 'Login failed.');
    $page = discount_discovery_http('http://127.0.0.1/index.php?section=discounts', 'GET', $cookieFile);
    discount_discovery_assert((int) $page['status'] === 200, 'Discounts page did not load after login.');

    return discount_discovery_csrf($page['body']);
}

function discount_discovery_favorite(string $cookieFile, string $csrf, int $promotionId, bool $favorite): array
{
    return discount_discovery_http('http://127.0.0.1/api/discounts/favorites.php', 'POST', $cookieFile, [
        'promotion_id' => $promotionId,
        'favorite' => $favorite,
    ], ['X-CSRF-Token' => $csrf]);
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080018_create_discount_tables.php',
        '202608080019_add_discount_promotion_ownership.php',
        '202608080020_add_discount_collector_import_fields.php',
        '202608080026_add_discount_promotion_category.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $password);
    $userIds = [$userId, $otherUserId];
    $programService = new DiscountBenefitProgramService(new DiscountBenefitProgramRepository($pdo));
    $merchantService = new DiscountMerchantService(new DiscountMerchantRepository($pdo));
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $promotionService = new DiscountPromotionService($promotionRepository, $merchantService, $programService);
    $userBenefitService = new UserDiscountBenefitService(new UserDiscountBenefitRepository($pdo), $programService);
    $availability = new DiscountPromotionAvailabilityService('America/Santiago');
    $today = new DateTimeImmutable($availability->today());
    $weekdayToday = $availability->weekdayToday();
    $otherWeekday = $weekdayToday === 7 ? 1 : $weekdayToday + 1;

    $bankProgram = $programService->create([
        'provider_name' => 'Banco de Chile ' . $suffix,
        'name' => 'Tarjetas Banco de Chile ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    $mobileProgram = $programService->create([
        'provider_name' => 'Santander ' . $suffix,
        'name' => 'Beneficios Santander ' . $suffix,
        'benefit_type' => 'bank_card',
    ]);
    $programIds = [(int) $bankProgram['id'], (int) $mobileProgram['id']];
    $userBenefitService->create($userId, ['benefit_program_id' => (int) $bankProgram['id']]);

    $unauth = discount_discovery_http('http://127.0.0.1/index.php?section=discounts', 'GET', $cookieFile);
    discount_discovery_assert((int) $unauth['status'] === 302 && str_contains($unauth['headers'], 'Location: /login.php'), 'Discount discovery did not require login.');

    $csrf = discount_discovery_login($username, $password, $cookieFile);
    $otherCsrf = discount_discovery_login($otherUsername, $password, $otherCookieFile);

    $collectorGeneral = $promotionService->create([
        'title' => 'General publica ' . $suffix,
        'description' => 'Descripcion segura',
        'category' => 'services',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'channel' => 'both',
        'terms' => 'Condiciones generales',
        'source_type' => 'collector',
        'collector_key' => 'banco_chile',
        'source_key' => 'public-' . $suffix,
        'source_url' => 'https://sitiospublicos.bancochile.cl/personas/beneficios',
    ]);
    $promotionIds[] = (int) $collectorGeneral['id'];

    $perfumeMerchant = $merchantService->create([
        'name' => 'Clinique Discovery ' . $suffix,
        'category' => null,
    ]);
    $merchantIds[] = (int) $perfumeMerchant['id'];
    $perfumePromotion = $promotionService->create([
        'merchant_id' => (int) $perfumeMerchant['id'],
        'title' => '20% exclusivo ' . $suffix,
        'description' => 'Descuento en tienda oficial.',
        'category' => 'perfumes',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'channel' => 'online',
        'terms' => str_repeat('Condicion larga de prueba. ', 80),
        'source_type' => 'collector',
        'collector_key' => 'santander_chile',
        'source_key' => 'perfume-' . $suffix,
        'source_url' => 'https://banco.santander.cl/beneficios/promociones/perfume-' . $suffix,
    ]);
    $promotionIds[] = (int) $perfumePromotion['id'];

    $techMerchant = $merchantService->create([
        'name' => 'Notebook Discovery ' . $suffix,
        'category' => null,
    ]);
    $merchantIds[] = (int) $techMerchant['id'];
    $techPromotion = $promotionService->create([
        'merchant_id' => (int) $techMerchant['id'],
        'title' => 'Accesorios digitales ' . $suffix,
        'description' => 'Oferta sin tilde en categoria.',
        'category' => 'technology',
        'discount_type' => 'other',
        'channel' => 'both',
        'source_type' => 'collector',
        'collector_key' => 'bancoestado',
        'source_key' => 'tech-' . $suffix,
        'source_url' => 'https://www.bancoestado.cl/beneficios/tech-' . $suffix,
    ]);
    $promotionIds[] = (int) $techPromotion['id'];

    $generalToday = $promotionService->createManual($userId, [
        'merchant_mode' => 'create',
        'merchant_name' => 'Dunkin Discovery ' . $suffix,
        'title' => 'General hoy ' . $suffix,
        'category' => 'food',
        'discount_type' => 'percentage',
        'discount_value' => 30,
        'channel' => 'both',
        'starts_on' => $today->modify('-1 day')->format('Y-m-d'),
        'ends_on' => $today->modify('+1 day')->format('Y-m-d'),
        'terms' => 'No acumulable <script>alert(1)</script>',
    ]);
    $promotionIds[] = (int) $generalToday['id'];
    $merchantIds[] = (int) $generalToday['merchant_id'];

    $bankToday = $promotionService->createManual($userId, [
        'title' => 'Banco hoy <script>alert(2)</script> ' . $suffix,
        'category' => 'perfumes',
        'discount_type' => 'fixed_amount',
        'discount_value' => 5000,
        'channel' => 'online',
        'starts_on' => $today->modify('-1 day')->format('Y-m-d'),
        'ends_on' => $today->modify('+1 day')->format('Y-m-d'),
        'weekdays' => [(string) $weekdayToday],
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $promotionIds[] = (int) $bankToday['id'];

    $mobileToday = $promotionService->createManual($userId, [
        'title' => 'Santander hoy ' . $suffix,
        'category' => 'perfumes',
        'discount_type' => 'other',
        'channel' => 'in_store',
        'starts_on' => $today->modify('-1 day')->format('Y-m-d'),
        'ends_on' => $today->modify('+1 day')->format('Y-m-d'),
        'weekdays' => [(string) $weekdayToday],
        'benefit_program_ids' => [(string) $mobileProgram['id']],
    ]);
    $promotionIds[] = (int) $mobileToday['id'];

    $wrongWeekday = $promotionService->createManual($userId, [
        'title' => 'Otro dia ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'starts_on' => $today->modify('-1 day')->format('Y-m-d'),
        'ends_on' => $today->modify('+1 day')->format('Y-m-d'),
        'weekdays' => [(string) $otherWeekday],
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $futureBank = $promotionService->createManual($userId, [
        'title' => 'Banco futuro ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'starts_on' => $today->modify('+3 days')->format('Y-m-d'),
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $finalizedBank = $promotionService->createManual($userId, [
        'title' => 'Banco finalizado ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'ends_on' => $today->modify('-1 day')->format('Y-m-d'),
        'benefit_program_ids' => [(string) $bankProgram['id']],
    ]);
    $inactiveGeneral = $promotionService->createManual($userId, [
        'title' => 'General inactiva ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
        'is_active' => '0',
    ]);
    $foreignManual = $promotionService->createManual($otherUserId, [
        'title' => 'Manual ajena ' . $suffix,
        'discount_type' => 'other',
        'channel' => 'both',
    ]);
    $promotionIds = array_merge($promotionIds, [
        (int) $wrongWeekday['id'],
        (int) $futureBank['id'],
        (int) $finalizedBank['id'],
        (int) $inactiveGeneral['id'],
        (int) $foreignManual['id'],
    ]);

    $root = discount_discovery_http('http://127.0.0.1/index.php?section=discounts', 'GET', $cookieFile);
    discount_discovery_assert(
        (int) $root['status'] === 200
        && str_contains($root['body'], '<h1 id="page-title">Para mi</h1>')
        && str_contains($root['body'], 'Favoritos')
        && str_contains($root['body'], 'Todos')
        && !str_contains($root['body'], 'tab=today')
        && !str_contains($root['body'], 'tab=benefits')
        && !str_contains($root['body'], 'tab=promotions'),
        '/discounts did not open Para mi with the final tabs.'
    );
    discount_discovery_assert(
        str_contains($root['body'], 'General hoy ' . $suffix)
        && str_contains($root['body'], 'Banco hoy &lt;script&gt;alert(2)&lt;/script&gt; ' . $suffix)
        && str_contains($root['body'], 'Banco futuro ' . $suffix)
        && str_contains($root['body'], 'General publica ' . $suffix)
        && !str_contains($root['body'], 'Santander hoy ' . $suffix)
        && !str_contains($root['body'], 'Banco finalizado ' . $suffix)
        && !str_contains($root['body'], 'General inactiva ' . $suffix)
        && str_contains($root['body'], 'Compatible con tu beneficio')
        && str_contains($root['body'], 'Disponible para todos')
        && str_contains($root['body'], '$5.000')
        && str_contains($root['body'], 'Todos los dias')
        && str_contains($root['body'], 'Presencial y online')
        && str_contains($root['body'], 'Ver condiciones')
        && str_contains($root['body'], 'Banco de Chile')
        && str_contains($root['body'], 'Ver promocion oficial')
        && !str_contains($root['body'], '<script>alert(2)</script>'),
        'Para mi did not render compatible/general cards correctly.'
    );

    $otherRoot = discount_discovery_http('http://127.0.0.1/index.php?section=discounts', 'GET', $otherCookieFile);
    discount_discovery_assert(
        str_contains($otherRoot['body'], 'Marca tus tarjetas y beneficios en tu perfil')
        && str_contains($otherRoot['body'], 'General publica ' . $suffix)
        && !str_contains($otherRoot['body'], 'Banco hoy'),
        'User without benefits did not see public general promotions and the benefits notice.'
    );

    $todayPage = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=today', 'GET', $cookieFile);
    discount_discovery_assert((int) $todayPage['status'] === 302 && str_contains($todayPage['headers'], 'Location: /index.php?section=discounts'), 'Old Today tab did not redirect to Para mi.');

    $allPage = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&channel=online', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($allPage['body'], 'Banco hoy')
        && str_contains($allPage['body'], 'Te sirve')
        && str_contains($allPage['body'], 'Online')
        && !str_contains($allPage['body'], 'Santander hoy ' . $suffix),
        'All channel filter or compatibility indicators failed.'
    );

    $forMePerfumes = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&category=perfumes', 'GET', $cookieFile);
    discount_discovery_assert(
        (int) $forMePerfumes['status'] === 200
        && str_contains($forMePerfumes['body'], 'Banco hoy')
        && str_contains($forMePerfumes['body'], '20% exclusivo ' . $suffix)
        && str_contains($forMePerfumes['body'], '<option value="perfumes" selected>')
        && !str_contains($forMePerfumes['body'], 'Santander hoy ' . $suffix)
        && !str_contains($forMePerfumes['body'], 'General hoy ' . $suffix),
        'For me category filter did not combine compatibility and category.'
    );

    $forMeTechnology = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&category=technology', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($forMeTechnology['body'], 'Accesorios digitales ' . $suffix)
        && !str_contains($forMeTechnology['body'], 'Banco hoy')
        && !str_contains($forMeTechnology['body'], 'General hoy ' . $suffix),
        'For me category filter leaked another category.'
    );

    $allPerfumes = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&category=perfumes', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($allPerfumes['body'], 'Banco hoy')
        && str_contains($allPerfumes['body'], 'Santander hoy ' . $suffix)
        && str_contains($allPerfumes['body'], '20% exclusivo ' . $suffix)
        && !str_contains($allPerfumes['body'], 'General hoy ' . $suffix),
        'All category filter did not isolate perfumes.'
    );

    $allFood = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&category=food', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($allFood['body'], 'General hoy ' . $suffix)
        && !str_contains($allFood['body'], 'Banco hoy')
        && !str_contains($allFood['body'], '20% exclusivo ' . $suffix),
        'Food category filter did not use the related category mapping safely.'
    );

    $invalidCategory = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&category=perfumes%27%20OR%201=1', 'GET', $cookieFile);
    discount_discovery_assert(
        (int) $invalidCategory['status'] === 200
        && str_contains($invalidCategory['body'], 'Banco hoy')
        && str_contains($invalidCategory['body'], 'General hoy ' . $suffix)
        && !str_contains($invalidCategory['body'], 'SQLSTATE'),
        'Invalid category was not ignored safely.'
    );

    $sourceFiltered = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&collector_key=banco_chile', 'GET', $cookieFile);
    discount_discovery_assert(str_contains($sourceFiltered['body'], 'General publica ' . $suffix) && str_contains($sourceFiltered['body'], 'Fuente'), 'Source filter did not show Banco de Chile collector promotions.');

    $perfumeSearch = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&search=perfumes', 'GET', $cookieFile);
    discount_discovery_assert(
        (int) $perfumeSearch['status'] === 200
        && str_contains($perfumeSearch['body'], '20% exclusivo ' . $suffix)
        && str_contains($perfumeSearch['body'], 'Perfumes')
        && !str_contains($perfumeSearch['body'], 'SQLSTATE'),
        'Search by perfume category alias failed or exposed SQL error.'
    );

    $perfumeCombined = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&search=perfume&merchant_id=' . (int) $perfumeMerchant['id'] . '&channel=online&category=perfumes', 'GET', $cookieFile);
    discount_discovery_assert(
        (int) $perfumeCombined['status'] === 200
        && str_contains($perfumeCombined['body'], '20% exclusivo ' . $suffix)
        && !str_contains($perfumeCombined['body'], 'General publica ' . $suffix)
        && !str_contains($perfumeCombined['body'], 'SQLSTATE'),
        'Combined search, merchant, channel and category filters failed.'
    );

    $technologySearch = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&search=' . rawurlencode('tecnología'), 'GET', $cookieFile);
    $technologyPlainSearch = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&search=tecnologia', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($technologySearch['body'], 'Accesorios digitales ' . $suffix)
        && str_contains($technologyPlainSearch['body'], 'Accesorios digitales ' . $suffix),
        'Technology search with and without accent failed.'
    );

    $literalWildcardSearch = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&search=' . rawurlencode('%_'), 'GET', $cookieFile);
    discount_discovery_assert((int) $literalWildcardSearch['status'] === 200 && !str_contains($literalWildcardSearch['body'], 'SQLSTATE'), 'Literal wildcard search failed.');

    $allWithState = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&state=all', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($allWithState['body'], 'Banco finalizado ' . $suffix)
        && str_contains($allWithState['body'], 'General inactiva ' . $suffix)
        && str_contains($allWithState['body'], 'Vencida')
        && str_contains($allWithState['body'], 'Inactiva'),
        'All state filter did not include ended/inactive promotions.'
    );

    $expiredOnly = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=all&state=expired', 'GET', $cookieFile);
    discount_discovery_assert(str_contains($expiredOnly['body'], 'Banco finalizado ' . $suffix) && !str_contains($expiredOnly['body'], 'Banco futuro ' . $suffix), 'Expired filter did not isolate ended promotions.');

    $favorite = discount_discovery_favorite($cookieFile, $csrf, (int) $bankToday['id'], true);
    $favoriteBody = discount_discovery_json($favorite);
    discount_discovery_assert((int) $favorite['status'] === 200 && ($favoriteBody['data']['favorite'] ?? null) === true, 'Favorite add failed.');
    $duplicateFavorite = discount_discovery_favorite($cookieFile, $csrf, (int) $bankToday['id'], true);
    discount_discovery_assert((int) $duplicateFavorite['status'] === 200, 'Duplicate favorite was not idempotent.');

    $favorites = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=favorites', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($favorites['body'], 'Banco hoy')
        && str_contains($favorites['body'], '♥')
        && !str_contains($favorites['body'], 'Santander hoy ' . $suffix),
        'Favorites page did not show own favorite only.'
    );

    $favoritesPerfumes = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=favorites&category=perfumes', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($favoritesPerfumes['body'], 'Banco hoy')
        && !str_contains($favoritesPerfumes['body'], '20% exclusivo ' . $suffix)
        && !str_contains($favoritesPerfumes['body'], 'Santander hoy ' . $suffix),
        'Favorites category filter included non-favorites or wrong category.'
    );

    $favoritesTechnology = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=favorites&category=technology', 'GET', $cookieFile);
    discount_discovery_assert(!str_contains($favoritesTechnology['body'], 'Banco hoy'), 'Favorites category filter did not exclude another category.');

    $otherFavorites = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=favorites', 'GET', $otherCookieFile);
    discount_discovery_assert(!str_contains($otherFavorites['body'], 'Banco hoy'), 'Another user could read a favorite that was not theirs.');

    $foreignFavorite = discount_discovery_favorite($otherCookieFile, $otherCsrf, (int) $bankToday['id'], true);
    discount_discovery_assert((int) $foreignFavorite['status'] === 422, 'Another user could favorite an invisible manual promotion.');

    $finalFavorite = discount_discovery_favorite($cookieFile, $csrf, (int) $finalizedBank['id'], true);
    discount_discovery_assert((int) $finalFavorite['status'] === 200, 'Finalized own promotion could not be favorited.');
    $favoritesWithFinalized = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=favorites', 'GET', $cookieFile);
    discount_discovery_assert(
        str_contains($favoritesWithFinalized['body'], 'Banco finalizado ' . $suffix)
        && str_contains($favoritesWithFinalized['body'], 'Vencida'),
        'Finalized favorite did not remain visible.'
    );

    $unfavorite = discount_discovery_favorite($cookieFile, $csrf, (int) $bankToday['id'], false);
    $unfavoriteBody = discount_discovery_json($unfavorite);
    discount_discovery_assert((int) $unfavorite['status'] === 200 && ($unfavoriteBody['data']['favorite'] ?? true) === false, 'Favorite remove failed.');

    $benefitsPage = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=benefits', 'GET', $cookieFile);
    $promotionsPage = discount_discovery_http('http://127.0.0.1/index.php?section=discounts&tab=promotions', 'GET', $cookieFile);
    discount_discovery_assert((int) $benefitsPage['status'] === 302 && str_contains($benefitsPage['headers'], 'Location: /index.php?section=settings&tab=benefits'), 'Old benefits tab did not redirect to settings.');
    discount_discovery_assert((int) $promotionsPage['status'] === 302 && str_contains($promotionsPage['headers'], 'Location: /index.php?section=discounts&tab=all'), 'Old promotions tab did not redirect to Todos.');

    $legacy = discount_discovery_http('http://127.0.0.1/index.php?section=discounts-for-me', 'GET', $cookieFile);
    discount_discovery_assert((int) $legacy['status'] === 302 && str_contains($legacy['headers'], 'Location: /index.php?section=discounts'), 'Legacy Para mi route did not redirect.');

    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    discount_discovery_assert(
        is_string($css)
        && is_string($js)
        && str_contains($css, '.discount-discovery-grid')
        && str_contains($css, '.discount-favorite-button')
        && str_contains($css, '.discount-promo-card__footer')
        && str_contains($css, '-webkit-line-clamp: 4')
        && str_contains($css, '@media (max-width: 768px)')
        && str_contains($js, 'data-discounts-discovery-page')
        && str_contains($js, 'data-discount-conditions-toggle'),
        'Discovery responsive/dark styles or JS were not present.'
    );

    echo "Discounts discovery: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discounts discovery: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ([$cookieFile, $otherCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    foreach ($promotionIds as $promotionId) {
        $statement = $pdo->prepare('DELETE FROM discount_promotions WHERE id = :id');
        $statement->execute(['id' => $promotionId]);
    }

    foreach (array_unique($merchantIds) as $merchantId) {
        if ($merchantId > 0) {
            $statement = $pdo->prepare('DELETE FROM discount_merchants WHERE id = :id');
            $statement->execute(['id' => $merchantId]);
        }
    }

    foreach ($userIds as $userId) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    foreach ($programIds as $programId) {
        $statement = $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id');
        $statement->execute(['id' => $programId]);
    }

    foreach ([$username, $otherUsername] as $loginUsername) {
        $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
        $statement->execute(['username' => $loginUsername]);
    }
}

exit($exitCode);
