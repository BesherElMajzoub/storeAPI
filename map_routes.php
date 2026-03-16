<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$routes = app('router')->getRoutes();

$laravelApiRoutes = [];
foreach ($routes as $route) {
    if (strpos($route->uri(), 'api/') === 0) {
        $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);
        foreach ($methods as $method) {
            $laravelApiRoutes[] = [
                'method' => $method,
                'uri' => $route->uri(),
                'action' => $route->getActionName(),
                'name' => $route->getName()
            ];
        }
    }
}

$postmanJson = file_get_contents('postman_collection.json');
$postman = json_decode($postmanJson, true);

$postmanEndpoints = [];
function extractPostmanItems($items, &$postmanEndpoints, $prefix = '') {
    foreach ($items as $item) {
        if (isset($item['item'])) {
            extractPostmanItems($item['item'], $postmanEndpoints, $prefix . ($item['name'] ?? '') . '/');
        } else {
            $path = "UNKNOWN";
            if (isset($item['request']['url'])) {
                $urlData = $item['request']['url'];
                if (is_string($urlData)) {
                    $raw = $urlData;
                } else if (isset($urlData['raw'])) {
                    $raw = $urlData['raw'];
                } else if (isset($urlData['path'])) {
                    $raw = implode('/', is_array($urlData['path']) ? $urlData['path'] : [$urlData['path']]);
                }
                
                if (isset($raw)) {
                    $pathParts = parse_url($raw, PHP_URL_PATH);
                    if ($pathParts) {
                        $path = ltrim($pathParts, '/');
                    } else {
                        // Sometimes parse_url fails on {{base_url}}/something
                        $path = preg_replace('/\{\{.*?\}\}\//', '', $raw);
                        $path = explode('?', $path)[0];
                    }
                }
            }
            
            $method = strtoupper($item['request']['method'] ?? 'GET');
            $postmanEndpoints[] = [
                'name' => $item['name'] ?? 'Unnamed',
                'path' => $path,
                'method' => $method,
                'request' => $item['request'] ?? [],
                'response' => $item['response'] ?? []
            ];
        }
    }
}

if (isset($postman['item'])) {
    extractPostmanItems($postman['item'], $postmanEndpoints);
}

$out = "Laravel API Routes: " . count($laravelApiRoutes) . "\n";
$out .= "Postman API Routes: " . count($postmanEndpoints) . "\n\n";

foreach ($laravelApiRoutes as $lr) {
    $found = false;
    foreach ($postmanEndpoints as $pe) {
        $pePathRegex = '#^' . preg_replace('/\{[a-zA-Z0-9_]+\}/', '[^/]+', $lr['uri']) . '(?:/)?$#i';
        $p = str_replace(['{{base_url}}/', 'http://localhost:8000/', 'http://localhost:80/'], '', $pe['path']);
        $p = ltrim($p, '/');
        
        if ($lr['method'] === $pe['method'] && preg_match($pePathRegex, $p)) {
            $found = true;
            break;
        }
    }
    $out .= "[".($found ? "X" : " ")."] " . str_pad($lr['method'], 6) . " " . str_pad($lr['uri'], 40) . " => " . $lr['action'] . "\n";
}

$out .= "\n--- Postman Routes NOT matched in Laravel ---\n";
foreach ($postmanEndpoints as $pe) {
    $found = false;
    foreach ($laravelApiRoutes as $lr) {
        $pePathRegex = '#^' . preg_replace('/\{[a-zA-Z0-9_]+\}/', '[^/]+', $lr['uri']) . '(?:/)?$#i';
        $p = str_replace(['{{base_url}}/', 'http://localhost:8000/', 'http://localhost:80/'], '', $pe['path']);
        $p = ltrim($p, '/');
        if ($lr['method'] === $pe['method'] && preg_match($pePathRegex, $p)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $out .= $pe['method'] . " " . $pe['path'] . "\n";
    }
}

file_put_contents('mapping_result.txt', $out);

