<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Services\AuthService;

final class Router
{
    private array $routes = [];

    public function __construct(private Container $container) {}

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $normalized = rtrim($path, '/') ?: '/';

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $normalized, $matches);
        $paramNames = $matches[1] ?? [];

        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $normalized
        );

        $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';

        $this->routes[$method][] = [
            'path' => $normalized,
            'pattern' => $pattern,
            'params' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $methodRoutes = $this->routes[$request->method] ?? [];
        $route = null;

        foreach ($methodRoutes as $candidate) {
            if (preg_match($candidate['pattern'], $request->path, $matches)) {
                $route = $candidate;

                foreach ($candidate['params'] as $name) {
                    $request->setAttribute($name, $matches[$name] ?? null);
                }

                break;
            }
        }

        if (!$route) {
            return Response::json(['success' => false, 'error' => 'Not found'], 404);
        }

        foreach ($route['middleware'] as $middleware) {
            if (is_array($middleware)) {
                [$class, $argument] = $middleware;
                $instance = new $class($argument);
                $result = $instance->handle($request);
            } else {
                $instance = $middleware === AuthMiddleware::class
                    ? new AuthMiddleware($this->container->get(AuthService::class))
                    : new $middleware();
                $result = $instance->handle($request);
            }

            if ($result instanceof Response) {
                return $result;
            }
        }

        $handler = $route['handler'];

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            return $controller->$method($request);
        }

        return $handler($request);
    }
}
