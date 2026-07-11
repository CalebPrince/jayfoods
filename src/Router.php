<?php

declare(strict_types=1);

/**
 * Minimal regex router for /api/v1/* endpoints.
 * Supports {named} path parameters, e.g. /api/v1/orders/{reference}.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path   = rtrim($path, '/') ?: '/';
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                // Keep only named capture groups as handler params.
                $params = array_filter(
                    $matches,
                    'is_string',
                    ARRAY_FILTER_USE_KEY
                );

                ($route['handler'])($params);
                return;
            }
        }

        Response::json(['error' => 'Not Found', 'path' => $path], 404);
    }

    private function compile(string $pattern): string
    {
        $pattern = rtrim($pattern, '/') ?: '/';
        $regex   = preg_replace(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            '(?P<$1>[^/]+)',
            $pattern
        );

        return '#^' . $regex . '$#';
    }
}
