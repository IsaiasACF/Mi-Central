<?php
declare(strict_types=1);

use App\Database\Connection;
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
use Modules\Discounts\Collectors\SantanderChileBenefitsParser;
use Modules\Discounts\Collectors\SantanderChileDiscountCollector;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountTextNormalizer;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
$pdo = Connection::get();
$suffix = strtolower(bin2hex(random_bytes(4)));
$promotionIds = [];
$programIds = [];
$exitCode = 1;

function santander_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function santander_table_exists(PDO $pdo, string $table): bool
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
 * @param array<int, array<string, mixed>> $items
 */
function santander_fixture(array $items, ?int $totalEntries = null): string
{
    return json_encode([
        'promociones' => $items,
        'meta' => [
            'total_entries' => $totalEntries ?? count($items),
            'per_page' => 500,
            'current_page' => 1,
            'total_pages' => 1,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<int, string> $tags
 * @param array<string, string> $fields
 * @return array<string, mixed>
 */
function santander_item(
    string $slug,
    string $title,
    string $copy,
    array $tags,
    string $description,
    string $vigencia,
    array $fields = [],
): array {
    $customFields = [
        'Bajada externa' => ['id' => 1, 'value' => $copy],
        'Bajada interna' => ['id' => 2, 'value' => $copy],
        'Vigencia' => ['id' => 3, 'value' => $vigencia],
        'Sitio web beneficio' => ['id' => 4, 'value' => ''],
        'Titulo en landing Cuarenta' => ['id' => 5, 'value' => ''],
        'Bajada en landing Cuarenta' => ['id' => 6, 'value' => ''],
    ];

    foreach ($fields as $name => $value) {
        $customFields[$name] = ['id' => count($customFields) + 1, 'value' => $value];
    }

    return [
        'custom_fields' => $customFields,
        'id' => random_int(1000, 9999),
        'uuid' => 'fixture-' . $slug,
        'url' => 'https://banco.santander.cl/beneficios/promociones/' . $slug,
        'title' => $title,
        'slug' => $slug,
        'excerpt' => '',
        'description' => $description,
        'covers' => [],
        'tags' => array_values(array_unique(array_merge(['home-disfrutadores'], $tags))),
        'category' => null,
        'site_id' => 39,
        'conditions' => 'No acumulable. Beneficio informado por Banco Santander Chile.',
        'start_date' => null,
        'end_date' => null,
        'discount' => null,
    ];
}

function santander_result(string $collectorKey, array $items): CollectorResult
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
        if ($migrationFile === '202608080023_create_discount_collector_schedules.php' && santander_table_exists($pdo, 'discount_collector_schedules')) {
            continue;
        }

        if ($migrationFile === '202608080024_create_discount_collector_runs.php' && santander_table_exists($pdo, 'discount_collector_runs')) {
            continue;
        }

        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $parser = new SantanderChileBenefitsParser();
    $percentageSlug = 'santander-test-porfirio-' . $suffix;
    $hastaSlug = 'santander-test-hasta-' . $suffix;
    $cuotasSlug = 'santander-test-cuotas-' . $suffix;
    $amexSlug = 'santander-test-amex-' . $suffix;
    $debitSlug = 'santander-test-debito-' . $suffix;
    $irrelevantSlug = 'santander-test-publicidad-' . $suffix;
    $json = santander_fixture([
        santander_item(
            $percentageSlug,
            'Bar Porfirio Ñuñoa ' . $suffix,
            '30% dcto. todos los miércoles.',
            ['tarjetas-credito', 'tarjetas-debito', 'miercoles', 'cat-sabores'],
            '<p>Exclusivo con tus Tarjetas de Crédito y Débito Santander en Ñuñoa.</p><p>Válido en local. Tope de descuento $20.000. Código promocional: SANTANDER20.</p><script>bad()</script>',
            'Hasta el 31 de agosto de 2026',
        ),
        santander_item(
            $hastaSlug,
            'Hasta Promo ' . $suffix,
            'Hasta 20% de dcto. todos los días.',
            ['tarjetas-credito', 'todos-los-dias', 'cat-descuentos'],
            '<p>Solo online en sitio web del comercio.</p>',
            'Hasta el 30 de septiembre de 2026',
        ),
        santander_item(
            $cuotasSlug,
            'Cuotas Comercio ' . $suffix,
            '3 a 48 cuotas sin interés',
            ['tarjetas-credito', 'cat-cuotas-sin-interes', 'todos-los-dias'],
            '<p>Compras en tienda y online.</p>',
            'Hasta el 31 de diciembre de 2026',
        ),
        santander_item(
            $amexSlug,
            'Amex Restaurante ' . $suffix,
            '50% dcto. todos los lunes',
            ['amex', 'exclusivo-amex', 'lunes', 'cat-sabores'],
            '<p>Exclusivo para American Express Santander.</p>',
            'Hasta el 31 de agosto de 2026',
        ),
        santander_item(
            $debitSlug,
            'Débito Café ' . $suffix,
            '15% dcto todos los martes',
            ['tarjetas-debito', 'martes', 'cat-descuentos'],
            '<p>Exclusivo con Tarjetas de Débito Santander. Compra presencial.</p>',
            'Hasta el 31 de agosto de 2026',
        ),
        santander_item(
            'santander-test-empresas-' . $suffix,
            'Empresa no personas ' . $suffix,
            '30% de dcto',
            ['empresas', 'tarjetas-credito'],
            '<p>Beneficio empresas.</p>',
            'Hasta el 31 de agosto de 2026',
        ),
        santander_item(
            $irrelevantSlug,
            'Banner institucional ' . $suffix,
            'Conoce Santander',
            ['no-home'],
            '<p>Contenido institucional.</p>',
            '',
        ),
    ]);
    $parsed = $parser->parseCatalog($json);
    santander_assert(count($parsed['items']) === 5, 'Parser did not skip empresas/no-home or valid Santander items.');
    $first = $parsed['items'][0]->toArray();
    santander_assert($first['source_key'] === $percentageSlug, 'Santander source_key did not use slug.');
    santander_assert($first['source_url'] === 'https://banco.santander.cl/beneficios/promociones/' . $percentageSlug, 'Santander detail URL was not preserved.');
    santander_assert($first['merchant_name'] === 'Bar Porfirio Ñuñoa ' . $suffix, 'Santander merchant/title extraction failed.');
    santander_assert($first['discount_type_hint'] === 'percentage' && (float) $first['discount_value_hint'] === 30.0, 'Santander percentage hint failed.');
    santander_assert($first['max_discount_clp'] === 20000, 'Santander max discount was not extracted.');
    santander_assert($first['promo_code'] === 'SANTANDER20', 'Santander promo code was not extracted conservatively.');
    santander_assert($first['ends_on_raw'] === '31 de agosto de 2026', 'Santander vigencia was not extracted.');
    santander_assert(str_contains(implode(' ', $first['weekdays_raw'] ?? []), 'miercoles'), 'Santander weekday raw extraction failed.');
    santander_assert(str_contains((string) ($first['category_raw'] ?? ''), 'cat-sabores'), 'Santander category raw tags were not preserved.');
    santander_assert(in_array('bank_card|Santander Chile|Tarjetas Credito Santander|Credito', $first['benefit_names_raw'] ?? [], true), 'Santander credit benefit missing.');
    santander_assert(in_array('bank_card|Santander Chile|Tarjetas Debito Santander|Debito', $first['benefit_names_raw'] ?? [], true), 'Santander debit benefit missing.');
    santander_assert(str_contains((string) $first['description'], 'Ñuñoa') && !str_contains((string) $first['description'], 'bad()'), 'Santander HTML description was not cleaned.');

    $normalizer = new PromotionNormalizer(new DiscountTextNormalizer());
    $hasta = $normalizer->normalize('santander_chile', $parsed['items'][1]);
    $cuotas = $normalizer->normalize('santander_chile', $parsed['items'][2]);
    $firstNormalized = $normalizer->normalize('santander_chile', $parsed['items'][0]);
    $amex = $parsed['items'][3]->toArray();
    santander_assert($firstNormalized->category() === 'restaurants', 'Santander source category was not normalized.');
    santander_assert($hasta->discountType() === 'other' && $hasta->discountValue() === null, 'Hasta percentage was treated as exact percentage.');
    santander_assert($cuotas->discountType() === 'other' && $cuotas->discountValue() === null, 'Cuotas sin interes was treated as numeric discount.');
    santander_assert(in_array('bank_card|Santander Chile|American Express Santander|Credito', $amex['benefit_names_raw'] ?? [], true), 'American Express Santander benefit missing.');

    foreach (['{"meta":{}}', santander_fixture([], 5)] as $badPayload) {
        try {
            $parser->parseCatalog($badPayload);
            throw new RuntimeException('Broken Santander structure was accepted.');
        } catch (CollectorParseException) {
        }
    }
    santander_assert($parser->parseCatalog(santander_fixture([], 0))['items'] === [], 'Valid empty Santander catalog was not accepted.');

    $calls = [];
    $httpClient = new CollectorHttpClient(
        maxResponseBytes: 2_000_000,
        transport: static function (string $url, array $headers, array $options) use (&$calls, $json): CollectorHttpResponse {
            $calls[] = ['url' => $url, 'headers' => $headers];

            return new CollectorHttpResponse(200, $json, ['content-type' => 'application/json; charset=utf-8'], $url);
        },
    );
    $runner = new DiscountCollectorRunner(
        new DiscountCollectorRegistry([new SantanderChileDiscountCollector()]),
        $httpClient,
        'America/Santiago',
        ['santander_chile' => ['max_items' => 3, 'request_delay_ms' => 0]],
    );
    $result = $runner->run('santander_chile');
    santander_assert($result->success() && count($result->items()) === 3 && count($calls) === 1, 'Santander collector did not use single official JSON endpoint or max_items.');
    santander_assert(str_starts_with((string) $calls[0]['url'], 'https://banco.santander.cl/beneficios/promociones.json?'), 'Santander collector did not call official catalog JSON.');
    santander_assert(str_starts_with((string) ($calls[0]['headers']['User-Agent'] ?? ''), 'curl/'), 'Santander collector did not use a transparent libcurl User-Agent.');

    try {
        $httpClient->get('https://comercio-externo.cl/promocion', ['banco.santander.cl']);
        throw new RuntimeException('External merchant host was accepted by Santander allowlist.');
    } catch (CollectorHttpException) {
    }

    $registry = new DiscountCollectorRegistry();
    santander_assert($registry->has('santander_chile') && $registry->has('banco_chile'), 'Production registry did not include Santander and Banco de Chile collectors.');
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
    $schedule = (new DiscountCollectorScheduleRepository($pdo))->find('santander_chile');
    santander_assert(is_array($schedule) && (int) $schedule['interval_minutes'] >= 60, 'Santander schedule was not created safely.');

    $pipeline = discountCollectorImportPipelineFromConfig($config);
    $pipelineFixture = santander_fixture([
        santander_item(
            'santander-test-pipeline-' . $suffix,
            'Pipeline Santander ' . $suffix,
            '25% dcto. todos los miércoles.',
            ['tarjetas-credito', 'miercoles', 'cat-descuentos'],
            '<p>Exclusivo con tus Tarjetas de Crédito Santander. Válido en local.</p>',
            'Hasta el 30 de noviembre de 2026',
        ),
        santander_item(
            'santander-test-pipeline-' . $suffix,
            'Pipeline Santander duplicado ' . $suffix,
            '25% dcto. todos los miércoles.',
            ['tarjetas-credito', 'miercoles', 'cat-descuentos'],
            '<p>Duplicado de fixture.</p>',
            'Hasta el 30 de noviembre de 2026',
        ),
    ]);
    $pipelineItems = $parser->parseCatalog($pipelineFixture)['items'];
    $dryRun = $pipeline->import(santander_result('santander_chile', $pipelineItems), true);
    santander_assert($dryRun->dryRun() && $dryRun->createdCount() === 1 && $dryRun->duplicateCount() === 1, 'Santander dry-run did not dedupe batch.');
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    $firstImport = $pipeline->import(santander_result('santander_chile', $pipelineItems));
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    santander_assert($firstImport->createdCount() === 1 && $afterCount === $beforeCount + 1, 'Santander first import did not create one promotion.');
    $repository = new DiscountPromotionRepository($pdo);
    $created = $repository->findCollectorByIdentity('santander_chile', 'santander-test-pipeline-' . $suffix);
    santander_assert(is_array($created) && $created['source_type'] === 'collector' && $created['collector_key'] === 'santander_chile', 'Santander collector identity was not persisted.');
    $promotionIds[] = (int) $created['id'];
    $programIds = array_merge($programIds, array_map('intval', $repository->listBenefits((int) $created['id'])));
    santander_assert((string) $created['discount_value'] === '25.00' && $repository->listWeekdays((int) $created['id']) === [3], 'Santander normalized data was not persisted.');

    $secondImport = $pipeline->import(santander_result('santander_chile', [$pipelineItems[0]]));
    $afterSecondCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    santander_assert($secondImport->createdCount() === 0 && $secondImport->updatedCount() === 1 && $afterSecondCount === $afterCount, 'Santander second import duplicated instead of updating.');

    $bankItem = new CollectedPromotion(
        sourceKey: 'cross-source-' . $suffix,
        title: 'Cross Source ' . $suffix,
        merchantName: 'Cross Merchant ' . $suffix,
        discountText: '20%',
        channelRaw: 'online',
    );
    $santanderItem = new CollectedPromotion(
        sourceKey: 'cross-source-' . $suffix,
        title: 'Cross Source ' . $suffix,
        merchantName: 'Cross Merchant ' . $suffix,
        discountText: '20%',
        channelRaw: 'online',
    );
    $bankImport = $pipeline->import(santander_result('banco_chile', [$bankItem]));
    $santanderCross = $pipeline->import(santander_result('santander_chile', [$santanderItem]));
    $bankCreated = $repository->findCollectorByIdentity('banco_chile', 'cross-source-' . $suffix);
    $santanderCreated = $repository->findCollectorByIdentity('santander_chile', 'cross-source-' . $suffix);
    $promotionIds[] = (int) $bankCreated['id'];
    $promotionIds[] = (int) $santanderCreated['id'];
    santander_assert($bankImport->createdCount() === 1 && $santanderCross->createdCount() === 1 && (int) $bankCreated['id'] !== (int) $santanderCreated['id'], 'Cross-source Banco/Santander promotion was incorrectly fused.');

    echo "Santander Chile collector: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Santander Chile collector: FAILED\n");
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
