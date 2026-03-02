<?php
require_once "./Database.php";
require_once "./jwt.php";

class Router {
    private array $routes = [];

    public function __construct() {
        $this->setSecurityHeaders();
    }

    private function setSecurityHeaders(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
    }

    public function get(string $path, callable $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, callable $handler): void {
        $this->routes['PUT'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function route(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];
        $path = parse_url($requestUri, PHP_URL_PATH);
        
        if (preg_match('#/index\.php/?(.*)$#', $path, $matches)) {
            $path = '/' . ltrim($matches[1], '/');
        } else {
            $path = '/';
        }
        
        $path = rtrim($path, '/');

        $debugInfo = [
            'debug' => [
                'full_uri' => $requestUri,
                'calculated_path' => $path,
                'method' => $method,
                'available_routes' => array_keys($this->routes[$method] ?? [])
            ]
        ];

        if (!empty($path) && isset($this->routes[$method][$path])) {
            try {
                $handler = $this->routes[$method][$path];
                $handler();
            } catch (Exception $e) {
                $this->error(500, 'Error interno del servidor: ');
            }
        } else {
            http_response_code(404);
            echo json_encode(array_merge([
                'status' => 'error',
                'code' => '404',
                'answer' => "Ruta no encontrada: $path"
            ], $debugInfo), JSON_UNESCAPED_UNICODE);
        }
    }

    private function error(int $code, string $message): void {
        http_response_code($code);
        echo json_encode([
            'status' => 'error', 
            'code' => (string)$code, 
            'answer' => $message
        ], JSON_UNESCAPED_UNICODE);
    }
}
