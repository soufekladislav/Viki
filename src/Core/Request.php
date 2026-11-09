<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly array $files = []
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Fallback pro hostingy bez mod_rewrite / aktivniho .htaccess.
        // Frontend pak vola primo api.php nebo public/index.php a cilovou
        // API cestu preda v parametru route.
        $routeOverride = $_GET['route'] ?? null;
        if (is_string($routeOverride) && preg_match('#^/(?:api(?:/|$)|health$)#', $routeOverride)) {
            $path = parse_url($routeOverride, PHP_URL_PATH) ?: '/';
        } else {
            $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
                $path = substr($path, strlen($scriptName)) ?: '/';
            }
        }

        if ($path === '/public') {
            $path = '/';
        } elseif (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
            if ($path === '') {
                $path = '/';
            }
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

        $body = [];
        if (is_array($decoded)) {
            $body = $decoded;
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $body);
        } else {
            $body = $_POST;
        }

        return new self(
            $method,
            rtrim($path, '/') ?: '/',
            $_GET,
            is_array($body) ? $body : [],
            function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [],
            $rawBody,
            $_FILES ?? []
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = (string)$this->header('authorization', '');
        if (preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
