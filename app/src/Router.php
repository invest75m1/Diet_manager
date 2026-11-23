<?php
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function dispatch(string $method, string $path): mixed
    {
        $path = '/' . trim($path, '/');
        $allowed = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mm)) {
                continue;
            }
            $allowed = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler(...$params);
        }
        throw new HttpError($allowed ? 405 : 404, $allowed ? 'Nepovolená metóda' : 'Neznáma požiadavka');
    }
}
