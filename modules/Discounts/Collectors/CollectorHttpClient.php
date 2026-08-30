<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class CollectorHttpClient
{
    /**
     * @param callable(string, array<string, string>, array<string, mixed>): CollectorHttpResponse|null $transport
     */
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $maxRedirects = 3,
        private readonly int $maxResponseBytes = 1048576,
        private readonly string $userAgent = 'MiCentral-DiscountCollector/1.0',
        private readonly mixed $transport = null,
    ) {
    }

    /**
     * @param array<int, string> $allowedHosts
     * @param array<string, string> $headers
     */
    public function get(string $url, array $allowedHosts, array $headers = []): CollectorHttpResponse
    {
        $currentUrl = $this->validatedUrl($url, $allowedHosts);
        $redirects = 0;

        do {
            $response = $this->requestOnce($currentUrl, $this->headers($headers));

            if (strlen($response->body()) > $this->maxResponseBytes) {
                throw new CollectorHttpException('Respuesta HTTP demasiado grande.');
            }

            if ($this->isRedirect($response->statusCode())) {
                $location = $response->header('location');

                if ($location === null || trim($location) === '') {
                    throw new CollectorHttpException('Redirect HTTP sin Location.', $response->statusCode());
                }

                if ($redirects >= $this->maxRedirects) {
                    throw new CollectorHttpException('Limite de redirects excedido.', $response->statusCode());
                }

                $currentUrl = $this->validatedUrl($this->resolveUrl($currentUrl, $location), $allowedHosts);
                $redirects++;
                continue;
            }

            if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
                throw new CollectorHttpException('Respuesta HTTP no exitosa: ' . $response->statusCode() . '.', $response->statusCode());
            }

            return $response;
        } while (true);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSeconds;
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects;
    }

    public function maxResponseBytes(): int
    {
        return $this->maxResponseBytes;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        return array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.5',
        ], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestOnce(string $url, array $headers): CollectorHttpResponse
    {
        if (is_callable($this->transport)) {
            $response = ($this->transport)($url, $headers, [
                'timeout_seconds' => $this->timeoutSeconds,
                'connect_timeout_seconds' => $this->connectTimeoutSeconds,
                'max_response_bytes' => $this->maxResponseBytes,
            ]);

            if (!$response instanceof CollectorHttpResponse) {
                throw new CollectorHttpException('Transporte HTTP invalido.');
            }

            return $response;
        }

        if (!function_exists('curl_init')) {
            throw new CollectorHttpException('cURL no esta disponible.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new CollectorHttpException('No se pudo iniciar HTTP.');
        }

        $body = '';
        $tooLarge = false;
        $responseHeaders = [];
        $headerLines = [];
        $requestHeaders = [];

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerLines): int {
                $headerLines[] = $line;

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxResponseBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = is_string(curl_getinfo($handle, CURLINFO_EFFECTIVE_URL)) ? (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) : $url;
        $error = curl_error($handle);

        if ($tooLarge) {
            throw new CollectorHttpException('Respuesta HTTP demasiado grande.');
        }

        if ($ok === false) {
            throw new CollectorHttpException('Fallo HTTP: ' . ($error !== '' ? $error : 'error desconocido') . '.');
        }

        foreach ($headerLines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $trimmed, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }

        return new CollectorHttpResponse($statusCode, $body, $responseHeaders, $effectiveUrl);
    }

    /**
     * @param array<int, string> $allowedHosts
     */
    private function validatedUrl(string $url, array $allowedHosts): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new CollectorHttpException('URL invalida.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new CollectorHttpException('Solo se permiten URLs HTTP/HTTPS con host.');
        }

        if (!$this->hostAllowed($host, $allowedHosts)) {
            throw new CollectorHttpException('Host no permitido: ' . $host . '.');
        }

        if ($this->isBlockedHost($host)) {
            throw new CollectorHttpException('Host bloqueado por seguridad.');
        }

        return $url;
    }

    /**
     * @param array<int, string> $allowedHosts
     */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim($allowedHost));

            if ($allowedHost === '') {
                continue;
            }

            if ($host === $allowedHost) {
                return true;
            }

            if (str_starts_with($allowedHost, '*.')) {
                $suffix = substr($allowedHost, 1);

                if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function isRedirect(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    private function resolveUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new CollectorHttpException('Redirect relativo invalido.');
        }

        $port = isset($base['port']) ? ':' . (string) $base['port'] : '';
        $root = (string) $base['scheme'] . '://' . (string) $base['host'] . $port;

        if (str_starts_with($location, '/')) {
            return $root . $location;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

        return $root . $directory . $location;
    }
}
