<?php
declare(strict_types=1);

use App\Database\Connection;
use Modules\Discounts\Collectors\BancoChileBenefitsParser;
use Modules\Discounts\Collectors\BancoChileDiscountCollector;
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
use Modules\Discounts\DiscountPromotionRepository;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
$pdo = Connection::get();
$suffix = strtolower(bin2hex(random_bytes(4)));
$promotionIds = [];
$programIds = [];
$exitCode = 1;

function banco_chile_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function banco_chile_table_exists(PDO $pdo, string $table): bool
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
 * @param array<int, array<string, mixed>> $entries
 */
function banco_chile_fixture(array $entries, int $page = 1, int $totalPages = 1, ?int $totalEntries = null): string
{
    return json_encode([
        'entries' => $entries,
        'meta' => [
            'total_entries' => $totalEntries ?? count($entries),
            'per_page' => 100,
            'current_page' => $page,
            'total_pages' => $totalPages,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<int, string> $tags
 * @param array<int, string> $cards
 * @param array<string, mixed> $extraFields
 * @return array<string, mixed>
 */
function banco_chile_entry(
    string $slug,
    string $title,
    string $discountText,
    array $tags,
    array $cards,
    string $extract,
    string $vigencia,
    string $description,
    string $terms,
    array $extraFields = [],
): array {
    return [
        'meta' => [
            'slug' => $slug,
            'name' => $title,
            'uuid' => 'fixture-' . $slug,
            'tags' => $tags,
        ],
        'fields' => array_merge([
            'Titulo' => $title,
            'Extracto' => $extract,
            'Vigencia' => $vigencia,
            'Descripcion' => $description,
            'Tipo Beneficio' => $discountText,
            'Tarjetas Permitidas' => $cards,
            'Condiciones Comerciales' => $terms,
        ], $extraFields),
    ];
}

function banco_chile_result(string $collectorKey, array $items): CollectorResult
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
        if ($migrationFile === '202608080023_create_discount_collector_schedules.php' && banco_chile_table_exists($pdo, 'discount_collector_schedules')) {
            continue;
        }

        if ($migrationFile === '202608080024_create_discount_collector_runs.php' && banco_chile_table_exists($pdo, 'discount_collector_runs')) {
            continue;
        }

        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $sourceOne = 'banco-test-dbs-' . $suffix;
    $sourceTwo = 'banco-test-cafe-' . $suffix;
    $uniqueCard = 'visa-test-card-' . $suffix;
    $pageOne = banco_chile_fixture([
        banco_chile_entry(
            $sourceOne,
            'DBS Ñuñoa ' . $suffix,
            '40% dto.',
            ['martes', 'miércoles'],
            ['visa-credito-infinite'],
            'martes y miércoles online',
            'Promoción válida desde el 01 al 31 de julio de 2026.',
            '<article><p>Belleza Ñuñoa</p><script>secreto()</script><p>40% de descuento online.</p></article>',
            'Promoción válida desde el 01 al 31 de julio de 2026. Exclusivo para clientes Visa Infinite. Tope máximo de descuento $50.000. No acumulable.',
        ),
        banco_chile_entry(
            $sourceTwo,
            'Cafetería Sur ' . $suffix,
            '20% dto.',
            ['jueves', 'domingo'],
            [],
            'jueves a domingo presencial',
            'Válido hasta el 31 de diciembre de 2026.',
            '<p>Café y pastelería con 20% de descuento.</p>',
            '',
            ['Sitio web' => 'https://comercio-ejemplo.cl/promocion'],
        ),
    ]);
    $parser = new BancoChileBenefitsParser();
    $parsed = $parser->parsePage($pageOne);
    banco_chile_assert(count($parsed['items']) === 2 && $parsed['total_pages'] === 1, 'Parser did not read Banco de Chile entries.');
    $firstData = $parsed['items'][0]->toArray();
    banco_chile_assert($firstData['source_key'] === $sourceOne, 'Stable source_key did not use slug.');
    banco_chile_assert($firstData['source_url'] === BancoChileBenefitsParser::DETAIL_BASE_URL . $sourceOne, 'Detail source_url was not built from official URL.');
    banco_chile_assert($firstData['merchant_name'] === 'DBS Ñuñoa ' . $suffix, 'Merchant/title was not extracted.');
    banco_chile_assert($firstData['discount_type_hint'] === 'percentage' && (float) $firstData['discount_value_hint'] === 40.0, 'Percentage discount hint failed.');
    banco_chile_assert($firstData['max_discount_clp'] === 50000, 'Max discount CLP was not extracted.');
    banco_chile_assert($firstData['starts_on_raw'] === '01 de julio de 2026' && $firstData['ends_on_raw'] === '31 de julio de 2026', 'Date range was not extracted.');
    banco_chile_assert(in_array('bank_card|Banco de Chile|Visa Infinite|Credito', $firstData['benefit_names_raw'] ?? [], true), 'Specific Banco de Chile card benefit was not extracted.');
    banco_chile_assert(str_contains((string) $firstData['description'], 'Ñuñoa') && !str_contains((string) $firstData['description'], 'secreto'), 'HTML description was not cleaned safely.');
    banco_chile_assert(str_contains(implode(' ', $firstData['weekdays_raw'] ?? []), 'martes'), 'Weekday raw text was not extracted.');
    $secondData = $parsed['items'][1]->toArray();
    banco_chile_assert($secondData['ends_on_raw'] === '31 de diciembre de 2026' && str_contains((string) $secondData['channel_raw'], 'presencial'), 'Optional end date/channel fields failed.');

    $invalidHtml = banco_chile_fixture([
        banco_chile_entry('banco-test-invalid-html-' . $suffix, 'HTML inválido ' . $suffix, '15%', [], [], '', '', '<p>Texto <strong>roto', ''),
    ]);
    banco_chile_assert($parser->parsePage($invalidHtml)['items'][0] instanceof CollectedPromotion, 'Reasonably invalid HTML was not tolerated.');

    foreach (['{"meta":{}}', banco_chile_fixture([], 1, 1, 5)] as $badPayload) {
        try {
            $parser->parsePage($badPayload);
            throw new RuntimeException('Broken Banco de Chile structure was accepted.');
        } catch (CollectorParseException) {
        }
    }

    $calls = [];
    $pageTwo = banco_chile_fixture([
        banco_chile_entry(
            'banco-test-pagina-dos-' . $suffix,
            'Página Dos ' . $suffix,
            '30% dto.',
            ['lunes'],
            [],
            'online',
            '',
            '<p>Beneficio paginado.</p>',
            '',
        ),
    ], 2, 2, 3);
    $transport = static function (string $url, array $headers, array $options) use (&$calls, $pageOne, $pageTwo): CollectorHttpResponse {
        $calls[] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return new CollectorHttpResponse(
            200,
            (int) ($query['page'] ?? 1) === 2 ? $pageTwo : banco_chile_fixture(json_decode($pageOne, true, 512, JSON_THROW_ON_ERROR)['entries'], 1, 2, 3),
            ['content-type' => 'application/json; charset=utf-8'],
            $url,
        );
    };
    $httpClient = new CollectorHttpClient(maxResponseBytes: 2_000_000, transport: $transport);
    $runner = new DiscountCollectorRunner(
        new DiscountCollectorRegistry([new BancoChileDiscountCollector()]),
        $httpClient,
        'America/Santiago',
        ['banco_chile' => ['max_items' => 0, 'request_delay_ms' => 0]],
    );
    $collection = $runner->run('banco_chile');
    banco_chile_assert($collection->success() && count($collection->items()) === 3 && count($calls) === 2, 'Collector did not paginate official Banco de Chile endpoint.');
    banco_chile_assert(!array_filter($calls, static fn (string $url): bool => !str_starts_with($url, 'https://sitiospublicos.bancochile.cl/api/content/')), 'Collector followed a non-official or detail/external URL.');

    $limitedRunner = new DiscountCollectorRunner(
        new DiscountCollectorRegistry([new BancoChileDiscountCollector()]),
        $httpClient,
        'America/Santiago',
        ['banco_chile' => ['max_items' => 2, 'request_delay_ms' => 0]],
    );
    banco_chile_assert(count($limitedRunner->run('banco_chile')->items()) === 2, 'Collector max_items config was ignored.');

    try {
        $httpClient->get('https://comercio-ejemplo.cl/promocion', ['sitiospublicos.bancochile.cl']);
        throw new RuntimeException('External merchant host was accepted by SSRF allowlist.');
    } catch (CollectorHttpException) {
    }

    $registry = new DiscountCollectorRegistry();
    banco_chile_assert($registry->has('banco_chile') && $registry->get('banco_chile')->getName() === 'Banco de Chile - Beneficios', 'Banco de Chile collector is not registered in production registry.');
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
    $schedule = (new DiscountCollectorScheduleRepository($pdo))->find('banco_chile');
    banco_chile_assert(is_array($schedule) && (int) $schedule['interval_minutes'] >= 60, 'Banco de Chile schedule was not created safely.');

    $pipelineFixture = banco_chile_fixture([
        banco_chile_entry(
            'banco-test-pipeline-' . $suffix,
            'Pipeline Banco Chile ' . $suffix,
            '25% dto.',
            ['martes'],
            [$uniqueCard],
            'martes online',
            'Válido hasta el 30 de noviembre de 2026.',
            '<p>Promoción pipeline.</p>',
            'Tope máximo de descuento $12.000. No acumulable.',
        ),
        banco_chile_entry(
            'banco-test-pipeline-' . $suffix,
            'Pipeline Banco Chile duplicado ' . $suffix,
            '25% dto.',
            ['martes'],
            [$uniqueCard],
            'martes online',
            'Válido hasta el 30 de noviembre de 2026.',
            '<p>Duplicado.</p>',
            '',
        ),
    ]);
    $pipelineItems = $parser->parsePage($pipelineFixture)['items'];
    $pipeline = discountCollectorImportPipelineFromConfig($config);
    $dryRun = $pipeline->import(banco_chile_result('banco_chile', $pipelineItems), true);
    banco_chile_assert($dryRun->dryRun() && $dryRun->createdCount() === 1 && $dryRun->duplicateCount() === 1, 'Banco de Chile dry-run did not report create/dedupe without persistence.');
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    $firstImport = $pipeline->import(banco_chile_result('banco_chile', $pipelineItems));
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    banco_chile_assert($firstImport->createdCount() === 1 && $firstImport->duplicateCount() === 1 && $afterCount === $beforeCount + 1, 'First Banco de Chile import did not create exactly one promotion.');
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $created = $promotionRepository->findCollectorByIdentity('banco_chile', 'banco-test-pipeline-' . $suffix);
    banco_chile_assert(is_array($created) && $created['source_type'] === 'collector' && $created['collector_key'] === 'banco_chile', 'Collector identity was not persisted for Banco de Chile.');
    $promotionIds[] = (int) $created['id'];
    banco_chile_assert((string) $created['discount_value'] === '25.00' && (int) $created['max_discount_clp'] === 12000, 'Banco de Chile normalized metrics were not persisted.');
    banco_chile_assert($promotionRepository->listWeekdays((int) $created['id']) === [2], 'Banco de Chile weekdays were not persisted.');
    $benefitIds = $promotionRepository->listBenefits((int) $created['id']);
    banco_chile_assert(count($benefitIds) === 1, 'Banco de Chile required benefits were not associated.');
    $programIds = array_merge($programIds, array_map(static fn (array $benefit): int => (int) ($benefit['id'] ?? 0), $benefitIds));

    $secondImport = $pipeline->import(banco_chile_result('banco_chile', [$pipelineItems[0]]));
    $afterSecondCount = (int) $pdo->query('SELECT COUNT(*) FROM discount_promotions')->fetchColumn();
    banco_chile_assert($secondImport->createdCount() === 0 && $secondImport->updatedCount() === 1 && $afterSecondCount === $afterCount, 'Second Banco de Chile import duplicated instead of updating.');

    $updatedFixture = banco_chile_fixture([
        banco_chile_entry(
            'banco-test-pipeline-' . $suffix,
            'Pipeline Banco Chile ' . $suffix,
            '35% dto.',
            ['jueves'],
            [$uniqueCard],
            'jueves online',
            'Válido hasta el 30 de noviembre de 2026.',
            '<p>Promoción pipeline actualizada.</p>',
            'Tope máximo de descuento $12.000. No acumulable.',
        ),
    ]);
    $updatedImport = $pipeline->import(banco_chile_result('banco_chile', $parser->parsePage($updatedFixture)['items']));
    $updated = $promotionRepository->findCollectorByIdentity('banco_chile', 'banco-test-pipeline-' . $suffix);
    banco_chile_assert($updatedImport->updatedCount() === 1 && is_array($updated) && (string) $updated['discount_value'] === '35.00', 'Banco de Chile update did not refresh changed promotion.');

    echo "Banco de Chile collector: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Banco de Chile collector: FAILED\n");
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

    $pdo->prepare(
        "DELETE FROM discount_benefit_programs
         WHERE provider_name = 'Banco de Chile'
           AND name = :test_card_name"
    )->execute(['test_card_name' => 'Visa Test Card ' . $suffix]);

    $pdo->prepare('DELETE FROM discount_merchants WHERE name LIKE :suffix')
        ->execute(['suffix' => '%' . $suffix . '%']);
}

exit($exitCode);
