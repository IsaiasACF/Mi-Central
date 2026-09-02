<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\BancoChileDiscountCollector;
use Modules\Discounts\Collectors\BancoEstadoBenefitsParser;
use Modules\Discounts\Collectors\BancoEstadoDiscountCollector;
use Modules\Discounts\Collectors\CollectedPromotion;
use Modules\Discounts\Collectors\CollectorHttpClient;
use Modules\Discounts\Collectors\CollectorHttpException;
use Modules\Discounts\Collectors\CollectorHttpResponse;
use Modules\Discounts\Collectors\CollectorParseException;
use Modules\Discounts\Collectors\CollectorResult;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunner;
use Modules\Discounts\Collectors\DiscountCollectorScheduleRepository;
use Modules\Discounts\Collectors\DiscountCollectorScheduler;
use Modules\Discounts\Collectors\PromotionNormalizer;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountTextNormalizer;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
$pdo = Connection::get();
$suffix = strtolower(bin2hex(random_bytes(4)));
$promotionIds = [];
$programIds = [];
$exitCode = 1;

function bancoestado_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bancoestado_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @param array<int, string> $links
 */
function bancoestado_catalog(array $links): string
{
    $html = '<!doctype html><html><head><title>Beneficios con Tarjetas BancoEstado</title></head><body>';
    $html .= '<main><h1>Disfruta de los beneficios con tus Tarjetas BancoEstado</h1><h2>Encuentra tus beneficios</h2>';

    foreach ($links as $link) {
        $html .= '<article><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Conoce mas</a></article>';
    }

    return $html . '</main></body></html>';
}

function bancoestado_detail(string $merchant, string $detail, string $where, string $payment, string $vigencia, string $terms = ''): string
{
    return '<!doctype html><html><head><title>' . htmlspecialchars($merchant, ENT_QUOTES, 'UTF-8') . ' | Beneficios BancoEstado</title></head><body>'
        . '<nav>Inicio Beneficios BancoEstado</nav>'
        . '<main><h1>' . htmlspecialchars($merchant, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<h2>Usa tus Tarjetas BancoEstado y obten</h2>'
        . '<h5>Detalle</h5><p>' . $detail . '</p>'
        . '<h5>Donde</h5><p>' . $where . '</p>'
        . '<h5>Medios de Pago</h5><p>' . $payment . '</p>'
        . '<h5>Vigencia</h5><p>' . $vigencia . '</p>'
        . '<section class="terms">' . $terms . '</section></main>'
        . '<footer>Existimos para acompañar a todas las personas. Casa Matriz BancoEstado.</footer>'
        . '</body></html>';
}

function bancoestado_result(string $collectorKey, array $items): CollectorResult
{
    $now = new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('America/Santiago'));

    return CollectorResult::ok($collectorKey, $now, $now, $items);
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080018_create_discount_tables.php',
        '202608080019_add_discount_promotion_ownership.php',
        '202608080020_add_discount_collector_import_fields.php',
        '202608080026_add_discount_promotion_category.php',
        '202608080023_create_discount_collector_schedules.php',
        '202608080024_create_discount_collector_runs.php',
    ] as $migrationFile) {
        if ($migrationFile === '202608080023_create_discount_collector_schedules.php' && bancoestado_table_exists($pdo, 'discount_collector_schedules')) {
            continue;
        }

        if ($migrationFile === '202608080024_create_discount_collector_runs.php' && bancoestado_table_exists($pdo, 'discount_collector_runs')) {
            continue;
        }

        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $parser = new BancoEstadoBenefitsParser();
    $base = 'https://investor.bancoestado.cl/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios/';
    $papaUrl = $base . 'papa-john-s---beneficios-bancoestado.html';
    $womUrl = $base . 'wom.html';
    $reuseUrl = $base . 'reuse---beneficios-bancoestado.html';
    $visaUrl = $base . 'lank---beneficios-bancoestado.html';
    $rutpayUrl = $base . 'copec-rutpay-' . $suffix . '.html';
    $digitsUrl = $base . 'primeros-digitos-' . $suffix . '.html';
    $catalog = bancoestado_catalog([
        $papaUrl,
        $papaUrl,
        $womUrl,
        'https://comercio-externo.cl/promocion.html',
        '/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios/reuse---beneficios-bancoestado.html',
    ]);
    $links = $parser->parseCatalogLinks($catalog, BancoEstadoBenefitsParser::CATALOG_URL);
    bancoestado_assert(count($links) === 3 && in_array($papaUrl, $links, true), 'BancoEstado catalog links were not parsed/deduped.');

    $papaItems = $parser->parseDetail(
        bancoestado_detail(
            'Papa Johns Ñuñoa ' . $suffix,
            '25% de descuento todos los domingos. Compra minima $15.000. Tope de descuento $10.000.',
            'Online o App Papa Johns.',
            'Tarjetas de Debito o Credito BancoEstado.',
            'Oferta valida desde el 04 de enero hasta el 27 de diciembre 2026.',
            '<p>No acumulable con otras promociones.</p><script>bad()</script>',
        ),
        $papaUrl,
    );
    $papa = $papaItems[0]->toArray();
    bancoestado_assert($papa['source_key'] === 'papa-john-s', 'BancoEstado source_key did not derive from slug.');
    bancoestado_assert($papa['merchant_name'] === 'Papa Johns Ñuñoa ' . $suffix, 'BancoEstado merchant extraction failed.');
    bancoestado_assert($papa['discount_type_hint'] === 'percentage' && (float) $papa['discount_value_hint'] === 25.0, 'BancoEstado percentage extraction failed.');
    bancoestado_assert($papa['max_discount_clp'] === 10000, 'BancoEstado max discount was not extracted.');
    bancoestado_assert($papa['starts_on_raw'] === '04 de enero de 2026' && $papa['ends_on_raw'] === '27 de diciembre de 2026', 'BancoEstado date range failed.');
    bancoestado_assert(in_array('bank_card|BancoEstado|Tarjetas Debito BancoEstado|Debito', $papa['benefit_names_raw'] ?? [], true), 'BancoEstado debit benefit missing.');
    bancoestado_assert(in_array('bank_card|BancoEstado|Tarjetas Credito BancoEstado|Credito', $papa['benefit_names_raw'] ?? [], true), 'BancoEstado credit benefit missing.');
    bancoestado_assert(str_contains(implode(' ', $papa['weekdays_raw'] ?? []), 'domingo'), 'BancoEstado weekday raw missing.');
    bancoestado_assert(!str_contains((string) $papa['terms'], 'bad()'), 'BancoEstado terms were not cleaned.');

    $normalizer = new PromotionNormalizer(new DiscountTextNormalizer());
    $normalizedPapa = $normalizer->normalize('bancoestado', $papaItems[0]);
    bancoestado_assert($normalizedPapa->category() === 'restaurants', 'BancoEstado fallback category was not normalized.');
    bancoestado_assert($normalizedPapa->channel() === 'online' && $normalizedPapa->weekdays() === [7], 'BancoEstado normalized channel/weekdays failed.');

    $wom = $parser->parseDetail(
        bancoestado_detail(
            'WOM ' . $suffix,
            '13 a 24 cuotas sin interes con tus Tarjetas de Credito BancoEstado.',
            'Presencial y online en WOM.',
            'Comprando con Tarjetas de Credito BancoEstado o BE Pay.',
            'Oferta valida desde el 01 de enero al 31 de diciembre 2026.',
        ),
        $womUrl,
    )[0]->toArray();
    $normalizedWom = $normalizer->normalize('bancoestado', CollectedPromotion::fromArray($wom));
    bancoestado_assert($normalizedWom->discountType() === 'other' && $normalizedWom->discountValue() === null, 'BancoEstado cuotas were treated as numeric discount.');
    bancoestado_assert(in_array('bank_card|BancoEstado|Tarjetas Credito BancoEstado|Credito', $wom['benefit_names_raw'] ?? [], true), 'BancoEstado credit-only benefit missing.');

    $reuse = $parser->parseDetail(
        bancoestado_detail(
            'Reuse ' . $suffix,
            '5% de descuento sobre el precio normal. Escribe el codigo bancoestadoreuse. Compra minima $15.000.',
            'Online en www.reuse.cl.',
            'Tarjetas BancoEstado.',
            'Oferta valida desde el 01 de marzo al 31 de diciembre 2026.',
        ),
        $reuseUrl,
    )[0]->toArray();
    bancoestado_assert($reuse['promo_code'] === 'BANCOESTADOREUSE' && $reuse['max_discount_clp'] === null, 'BancoEstado promo code or minimum purchase handling failed.');

    $visa = $parser->parseDetail(
        bancoestado_detail(
            'Lank ' . $suffix,
            '20% de descuento en suscripciones. Tope de descuento por suscripcion de $1.000.',
            'Online en somoslank.cl.',
            'Tarjetas de Credito Visa BancoEstado.',
            'Oferta valida desde el 15 al 30 de junio de 2026.',
        ),
        $visaUrl,
    )[0]->toArray();
    bancoestado_assert(in_array('bank_card|BancoEstado|Tarjetas Credito Visa BancoEstado|Credito', $visa['benefit_names_raw'] ?? [], true), 'BancoEstado Visa benefit missing.');

    $rutpay = $parser->parseDetail(
        bancoestado_detail(
            'Copec Rutpay ' . $suffix,
            '$100 dto. todos los martes en gasolina o diesel.',
            'Presencial.',
            'Pagando con Rutpay y CuentaRUT.',
            'Oferta valida hasta el 31 de diciembre de 2026.',
        ),
        $rutpayUrl,
    )[0]->toArray();
    bancoestado_assert($rutpay['discount_type_hint'] === 'fixed_amount' && (int) $rutpay['discount_value_hint'] === 100, 'BancoEstado fixed amount failed.');
    bancoestado_assert(in_array('bank_account|BancoEstado|CuentaRUT BancoEstado|CuentaRUT', $rutpay['benefit_names_raw'] ?? [], true), 'BancoEstado CuentaRUT benefit missing.');

    $digits = $parser->parseDetail(
        bancoestado_detail(
            'Codigo no fijo ' . $suffix,
            '30% de descuento ingresando los primeros 6 digitos de tu tarjeta.',
            'Online.',
            'Tarjetas BancoEstado.',
            'Oferta valida hasta el 31 de diciembre de 2026.',
        ),
        $digitsUrl,
    )[0]->toArray();
    bancoestado_assert($digits['promo_code'] === null, 'BancoEstado stored card digits as promo_code.');

    foreach (['<html><body>Access Denied</body></html>', '<html><body>Pagina no encontrada</body></html>', '<html><body>Contenido sin beneficios</body></html>'] as $badHtml) {
        try {
            $parser->parseCatalogLinks($badHtml, BancoEstadoBenefitsParser::CATALOG_URL);
            throw new RuntimeException('Broken BancoEstado catalog was accepted.');
        } catch (CollectorParseException) {
        }
    }

    $responses = [
        BancoEstadoBenefitsParser::CATALOG_URL => $catalog,
        $papaUrl => bancoestado_detail(
            'Papa Johns Pipeline ' . $suffix,
            '25% de descuento todos los domingos.',
            'Online o App Papa Johns.',
            'Tarjetas de Debito o Credito BancoEstado.',
            'Oferta valida desde el 04 de enero hasta el 27 de diciembre 2026.',
        ),
        $womUrl => bancoestado_detail(
            'WOM Pipeline ' . $suffix,
            '13 a 24 cuotas sin interes con tus Tarjetas de Credito BancoEstado.',
            'Presencial y online en WOM.',
            'Tarjetas de Credito BancoEstado.',
            'Oferta valida desde el 01 de enero al 31 de diciembre 2026.',
        ),
        'https://investor.bancoestado.cl/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios/reuse---beneficios-bancoestado.html' => bancoestado_detail(
            'Reuse Pipeline ' . $suffix,
            '5% de descuento sobre el precio normal.',
            'Online en reuse.cl.',
            'Tarjetas BancoEstado.',
            'Oferta valida desde el 01 de marzo al 31 de diciembre 2026.',
        ),
    ];
    $calls = [];
    $httpClient = new CollectorHttpClient(
        maxResponseBytes: 2_000_000,
        transport: static function (string $url, array $headers, array $options) use (&$calls, $responses): CollectorHttpResponse {
            $calls[] = $url;

            if (!isset($responses[$url])) {
                return new CollectorHttpResponse(404, 'not found', [], $url);
            }

            return new CollectorHttpResponse(200, $responses[$url], ['content-type' => 'text/html; charset=utf-8'], $url);
        },
    );
    $runner = new DiscountCollectorRunner(
        new DiscountCollectorRegistry([new BancoEstadoDiscountCollector()]),
        $httpClient,
        'America/Santiago',
        ['bancoestado' => ['max_items' => 2, 'request_delay_ms' => 0]],
    );
    $result = $runner->run('bancoestado');
    bancoestado_assert($result->success() && count($result->items()) === 2 && count($calls) === 3, 'BancoEstado collector did not process catalog/details/max_items.');

    try {
        $httpClient->get('https://comercio-externo.cl/promocion', ['www.bancoestado.cl']);
        throw new RuntimeException('External merchant host was accepted by BancoEstado allowlist.');
    } catch (CollectorHttpException) {
    }

    $registry = new DiscountCollectorRegistry();
    bancoestado_assert($registry->has('bancoestado') && $registry->has('banco_chile') && $registry->has('santander_chile'), 'Production registry did not include three real collectors.');
    $schedulerConfig = $config['collectors']['scheduler'];
    $schedulerConfig['default_interval_minutes'] = 1440;
    $scheduler = new DiscountCollectorScheduler(
        new DiscountCollectorScheduleRepository($pdo),
        $registry,
        $runner,
        discountCollectorImportPipelineFromConfig($config),
        $schedulerConfig,
    );
    $scheduler->ensureSchedulesExist();
    $schedule = (new DiscountCollectorScheduleRepository($pdo))->find('bancoestado');
    bancoestado_assert(is_array($schedule) && (int) $schedule['interval_minutes'] >= 60, 'BancoEstado schedule was not created safely.');

    $pipeline = discountCollectorImportPipelineFromConfig($config);
    $pipelineItems = [$papaItems[0], $papaItems[0]];
    $dryRun = $pipeline->import(bancoestado_result('bancoestado', $pipelineItems), true);
    bancoestado_assert($dryRun->dryRun() && $dryRun->createdCount() === 1 && $dryRun->duplicateCount() === 1, 'BancoEstado dry-run did not dedupe batch.');
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    $firstImport = $pipeline->import(bancoestado_result('bancoestado', [$papaItems[0]]));
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    bancoestado_assert($firstImport->createdCount() === 1 && $afterCount === $beforeCount + 1, 'BancoEstado first import did not create one promotion.');
    $repository = new DiscountPromotionRepository($pdo);
    $created = $repository->findCollectorByIdentity('bancoestado', 'papa-john-s');
    bancoestado_assert(is_array($created) && $created['source_type'] === 'collector' && $created['collector_key'] === 'bancoestado', 'BancoEstado collector identity was not persisted.');
    $promotionIds[] = (int) $created['id'];
    $programIds = array_merge($programIds, array_map('intval', $repository->listBenefits((int) $created['id'])));
    bancoestado_assert((string) $created['discount_value'] === '25.00' && $repository->listWeekdays((int) $created['id']) === [7], 'BancoEstado normalized data was not persisted.');

    $secondImport = $pipeline->import(bancoestado_result('bancoestado', [$papaItems[0]]));
    $afterSecondCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    bancoestado_assert($secondImport->createdCount() === 0 && $secondImport->updatedCount() === 1 && $afterSecondCount === $afterCount, 'BancoEstado second import duplicated instead of updating.');

    $bankItem = new CollectedPromotion(
        sourceKey: 'cross-source-' . $suffix,
        title: 'Cross Source ' . $suffix,
        merchantName: 'Cross Merchant ' . $suffix,
        discountText: '20%',
        channelRaw: 'online',
    );
    $estadoItem = new CollectedPromotion(
        sourceKey: 'cross-source-' . $suffix,
        title: 'Cross Source ' . $suffix,
        merchantName: 'Cross Merchant ' . $suffix,
        discountText: '20%',
        channelRaw: 'online',
    );
    $bankImport = $pipeline->import(bancoestado_result(BancoChileDiscountCollector::KEY, [$bankItem]));
    $estadoCross = $pipeline->import(bancoestado_result('bancoestado', [$estadoItem]));
    $bankCreated = $repository->findCollectorByIdentity(BancoChileDiscountCollector::KEY, 'cross-source-' . $suffix);
    $estadoCreated = $repository->findCollectorByIdentity('bancoestado', 'cross-source-' . $suffix);
    $promotionIds[] = (int) $bankCreated['id'];
    $promotionIds[] = (int) $estadoCreated['id'];
    bancoestado_assert($bankImport->createdCount() === 1 && $estadoCross->createdCount() === 1 && (int) $bankCreated['id'] !== (int) $estadoCreated['id'], 'Cross-source BancoEstado/BancoChile promotion was incorrectly fused.');

    echo "BancoEstado collector: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "BancoEstado collector: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach (array_unique($promotionIds) as $promotionId) {
        $pdo->prepare('DELETE FROM discount_promotions WHERE id = :id')->execute(['id' => $promotionId]);
    }

    foreach (array_unique($programIds) as $programId) {
        try {
            $pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id')->execute(['id' => $programId]);
        } catch (Throwable) {
        }
    }

    $pdo->prepare('DELETE FROM discount_merchants WHERE name LIKE :suffix')
        ->execute(['suffix' => '%' . $suffix . '%']);
}

exit($exitCode);
