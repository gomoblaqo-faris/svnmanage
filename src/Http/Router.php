<?php

declare(strict_types=1);

namespace SvnManage\Http;

/** Minimal method + pattern router. Patterns use {name} for one path segment. */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: array{0: class-string, 1: string}}> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function add(string $method, string $pattern, array $handler): self
    {
        preg_match_all('/\{(\w+)\}/', $pattern, $m);
        $regex = '#^' . preg_replace('/\\\\\{\w+\\\\\}/', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'params' => $m[1], 'handler' => $handler];

        return $this;
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $pattern, array $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $pattern, array $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, params: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $m[$i + 1]; // the front controller decoded the path already
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }
        throw $allowed ? new HttpException(405, 'Method not allowed.') : HttpException::notFound();
    }
}
