<?php
declare(strict_types=1);

use Modules\Discounts\Collectors\CollectedPromotion;
use Modules\Discounts\Collectors\CollectorContext;
use Modules\Discounts\Collectors\CollectorException;
use Modules\Discounts\Collectors\CollectorHtmlHelper;
use Modules\Discounts\Collectors\CollectorHttpClient;
use Modules\Discounts\Collectors\CollectorHttpException;
use Modules\Discounts\Collectors\CollectorHttpResponse;
use Modules\Discounts\Collectors\CollectorParseException;
use Modules\Discounts\Collectors\DiscountCollectorInterface;
use Modules\Discounts\Collectors\DiscountCollectorRegistry;
use Modules\Discounts\Collectors\DiscountCollectorRunner;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/workers/run-discount-collector.php';
$exitCode = 1;

function discount_collectors_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeDiscountCollector implements DiscountCollectorInterface
{
    public function getKey(): string
    {
        return 'fake_source';
    }

    public function getName(): string
    {
        return 'Fuente fake';
    }

    public function collect(CollectorContext $context): array
    {
        discount_collectors_assert(!array_key_exists('user_id', $context->config()), 'Collector context leaked user_id.');

        return [
            new CollectedPromotion(
                sourceKey: 'promo-uno',
                title: '30% descuento en cafe nino',
                sourceUrl: 'https://example.cl/promos/uno',
                merchantName: 'Cafe Nino',
                description: 'Promocion con caracteres espanoles: nino, corazon, accion.',
                discountText: '30%',
                startsOnRaw: 'Desde hoy',
                endsOnRaw: 'Hasta fin de mes',
                weekdaysRaw: ['Todos los martes'],
                benefitNamesRaw: ['Tarjetas Banco de Prueba'],
                channelRaw: 'Online',
                terms: 'No acumulable',
                collectedAt: $context->now(),
            ),
            CollectedPromotion::fromArray([
                'source_key' => 'promo-dos',
                'title' => 'Precio especial',
                'source_url' => 'https://example.cl/promos/dos',
                'merchant_name' => 'Comercio Dos',
                'discount_text' => '2x1',
                'benefit_names_raw' => ['Beneficio Movil'],
                'collected_at' => $context->now(),
            ]),
        ];
    }
}

final class EmptyDiscountCollector implements DiscountCollectorInterface
{
    public function getKey(): string
    {
        return 'empty_source';
    }

    public function getName(): string
    {
        return 'Fuente vacia';
    }

    public function collect(CollectorContext $context): array
    {
        return [];
    }
}

final class FailingDiscountCollector implements DiscountCollectorInterface
{
    public function getKey(): string
    {
        return 'failing_source';
    }

    public function getName(): string
    {
        return 'Fuente fallida';
    }

    public function collect(CollectorContext $context): array
    {
        throw new CollectorException('Error controlado del collector.');
    }
}

final class InvalidKeyDiscountCollector implements DiscountCollectorInterface
{
    public function getKey(): string
    {
        return 'Banco Chile';
    }

    public function getName(): string
    {
        return 'Key invalida';
    }

    public function collect(CollectorContext $context): array
    {
        return [];
    }
}

try {
    $transportCalls = [];
    $transport = static function (string $url, array $headers, array $options) use (&$transportCalls): CollectorHttpResponse {
        $transportCalls[] = ['url' => $url, 'headers' => $headers, 'options' => $options];

        if (str_contains($url, '/redirect')) {
            return new CollectorHttpResponse(302, '', ['location' => 'https://example.cl/final'], $url);
        }

        if (str_contains($url, '/evil-redirect')) {
            return new CollectorHttpResponse(302, '', ['location' => 'https://evil.cl/final'], $url);
        }

        if (str_contains($url, '/too-large')) {
            return new CollectorHttpResponse(200, str_repeat('a', 100), [], $url);
        }

        if (str_contains($url, '/server-error')) {
            return new CollectorHttpResponse(500, 'error', [], $url);
        }

        return new CollectorHttpResponse(200, '<html><body>OK</body></html>', ['content-type' => 'text/html; charset=utf-8'], $url);
    };
    $httpClient = new CollectorHttpClient(
        timeoutSeconds: 9,
        connectTimeoutSeconds: 4,
        maxRedirects: 1,
        maxResponseBytes: 64,
        userAgent: 'MiCentral-TestCollector/1.0',
        transport: $transport,
    );

    discount_collectors_assert($httpClient->timeoutSeconds() === 9 && $httpClient->connectTimeoutSeconds() === 4 && $httpClient->maxRedirects() === 1 && $httpClient->maxResponseBytes() === 64, 'HTTP client config was not exposed.');
    $response = $httpClient->get('https://example.cl/promos', ['example.cl']);
    discount_collectors_assert($response->statusCode() === 200 && str_contains($response->body(), 'OK') && ($transportCalls[0]['headers']['User-Agent'] ?? '') === 'MiCentral-TestCollector/1.0', 'HTTP client did not return mocked response.');
    $redirect = $httpClient->get('https://example.cl/redirect', ['example.cl']);
    discount_collectors_assert($redirect->statusCode() === 200 && count($transportCalls) >= 3, 'HTTP redirect was not followed through the same validation path.');

    foreach ([
        ['ftp://example.cl/promos', ['example.cl'], 'Invalid scheme was accepted.'],
        ['https://evil.cl/promos', ['example.cl'], 'Unallowed host was accepted.'],
        ['http://localhost/promos', ['localhost'], 'localhost was accepted.'],
        ['http://127.0.0.1/promos', ['127.0.0.1'], '127.0.0.1 was accepted.'],
        ['http://10.0.0.1/promos', ['10.0.0.1'], 'Private IP was accepted.'],
    ] as [$url, $hosts, $message]) {
        try {
            $httpClient->get($url, $hosts);
            throw new RuntimeException($message);
        } catch (CollectorHttpException) {
        }
    }

    foreach ([
        'https://example.cl/too-large' => 'Oversized response was accepted.',
        'https://example.cl/server-error' => 'HTTP 500 response was accepted.',
        'https://example.cl/evil-redirect' => 'Redirect to unallowed host was accepted.',
    ] as $url => $message) {
        try {
            $httpClient->get($url, ['example.cl']);
            throw new RuntimeException($message);
        } catch (CollectorHttpException) {
        }
    }

    $registry = new DiscountCollectorRegistry([]);
    discount_collectors_assert($registry->all() === [] && !$registry->has('fake_source'), 'Empty registry was not empty.');
    $registry->register(new FakeDiscountCollector());
    $registry->register(new EmptyDiscountCollector());
    $registry->register(new FailingDiscountCollector());
    discount_collectors_assert($registry->has('fake_source') && $registry->get('fake_source')->getName() === 'Fuente fake', 'Collector registry could not resolve fake collector.');
    discount_collectors_assert((new DiscountCollectorRegistry())->has('banco_chile'), 'Production registry did not include banco_chile.');

    try {
        $registry->register(new FakeDiscountCollector());
        throw new RuntimeException('Duplicate collector key was accepted.');
    } catch (CollectorException) {
    }

    try {
        $registry->register(new InvalidKeyDiscountCollector());
        throw new RuntimeException('Invalid collector key was accepted.');
    } catch (CollectorException) {
    }

    try {
        $registry->get('missing_source');
        throw new RuntimeException('Missing collector was resolved.');
    } catch (CollectorException) {
    }

    $runner = new DiscountCollectorRunner($registry, $httpClient, 'America/Santiago', [
        'fake_source' => ['allowed_hosts' => ['example.cl']],
    ]);
    $success = $runner->run('fake_source');
    discount_collectors_assert($success->success() && count($success->items()) === 2 && $success->collectorKey() === 'fake_source', 'Runner success result failed.');
    discount_collectors_assert($success->items()[0]->title() === '30% descuento en cafe nino' && $success->items()[0]->sourceKey() === 'promo-uno', 'CollectedPromotion DTO did not preserve raw data.');
    $successArray = $success->toArray();
    discount_collectors_assert(($successArray['items'][0]['benefit_names_raw'][0] ?? '') === 'Tarjetas Banco de Prueba', 'CollectorResult did not expose raw items.');

    $empty = $runner->run('empty_source');
    discount_collectors_assert($empty->success() && count($empty->items()) === 0, 'Zero-result collector was treated as failure.');

    $failed = $runner->run('failing_source');
    discount_collectors_assert(!$failed->success() && $failed->errorType() === 'CollectorException' && str_contains((string) $failed->errorMessage(), 'Error controlado'), 'Runner did not capture collector exception.');

    $missing = $runner->run('missing_source');
    discount_collectors_assert(!$missing->success() && $missing->errorType() === 'CollectorConfigurationException', 'Runner did not control missing collector.');

    foreach ([
        ['source_key' => '', 'title' => 'Titulo'],
        ['source_key' => 'promo', 'title' => ''],
        ['source_key' => 'promo', 'title' => 'Titulo', 'source_url' => 'not-a-url'],
    ] as $payload) {
        try {
            CollectedPromotion::fromArray($payload);
            throw new RuntimeException('Invalid raw promotion was accepted.');
        } catch (CollectorParseException) {
        }
    }

    $spanish = CollectedPromotion::fromArray([
        'source_key' => 'promocion-espanol',
        'title' => 'Promocion con nino y accion',
        'source_url' => 'https://example.cl/es',
    ]);
    discount_collectors_assert($spanish->title() === 'Promocion con nino y accion', 'Spanish characters were not preserved.');

    $xpath = CollectorHtmlHelper::xpath('<html><body><article><h2>Promo</h2></article></body></html>');
    discount_collectors_assert($xpath->query('//article/h2')->item(0)?->textContent === 'Promo', 'HTML helper did not parse DOMXPath.');

    $reflection = new ReflectionClass(FakeDiscountCollector::class);
    foreach ($reflection->getProperties() as $property) {
        discount_collectors_assert(strtolower($property->getName()) !== 'pdo', 'Fixture collector contains PDO property.');
    }

    ob_start();
    $cliCode = runDiscountCollectorCommand(['workers/run-discount-collector.php', 'fake_source', '--dry-run'], $registry, $config, $httpClient);
    $cliOutput = (string) ob_get_clean();
    discount_collectors_assert($cliCode === 0 && str_contains($cliOutput, 'Collector: fake_source') && str_contains($cliOutput, 'Status: OK') && str_contains($cliOutput, 'Found: 2') && str_contains($cliOutput, 'DRY RUN'), 'CLI command did not print success dry-run summary.');

    ob_start();
    $missingArgCode = runDiscountCollectorCommand(['workers/run-discount-collector.php'], $registry, $config, $httpClient);
    $missingArgOutput = (string) ob_get_clean();
    discount_collectors_assert($missingArgCode === 2 && str_contains($missingArgOutput, 'Usage:'), 'CLI command did not handle missing collector key.');

    ob_start();
    $invalidCliCode = runDiscountCollectorCommand(['workers/run-discount-collector.php', 'missing_source'], $registry, $config, $httpClient);
    $invalidCliOutput = (string) ob_get_clean();
    discount_collectors_assert($invalidCliCode === 1 && str_contains($invalidCliOutput, 'Status: FAILED'), 'CLI command did not handle invalid collector key.');

    $productionCli = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && php workers/run-discount-collector.php missing_source 2>&1');
    discount_collectors_assert(is_string($productionCli) && str_contains($productionCli, 'Collector: missing_source') && str_contains($productionCli, 'Status: FAILED'), 'Production CLI did not handle empty registry safely.');

    echo "Discount collectors: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Discount collectors: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
}

exit($exitCode);
