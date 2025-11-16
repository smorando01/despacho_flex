<?php
if (!defined('API_SHARED_SECRET')) {
    define('API_SHARED_SECRET', 'k7p9zX3qR8bVwY5a');
}
if (!function_exists('flex_auth_start_session')) {
    function flex_auth_start_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('flex_auth_json_exit')) {
    function flex_auth_json_exit(int $status, array $payload): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('flex_auth_get_secret')) {
    function flex_auth_get_secret(): ?string
    {
        static $secret = null;

        if ($secret === null) {
            $env = getenv('API_SHARED_SECRET');
            if ($env !== false && $env !== '') {
                $secret = $env;
            } elseif (defined('API_SHARED_SECRET') && API_SHARED_SECRET !== '') {
                $secret = API_SHARED_SECRET;
            } else {
                $secret = '';
            }
        }

        return $secret !== '' ? $secret : null;
    }
}

if (!function_exists('require_api_auth')) {
    function require_api_auth(): void
    {
        flex_auth_start_session();

        $secret = flex_auth_get_secret();
        if ($secret === null) {
            flex_auth_json_exit(500, [
                'success' => false,
                'message' => 'API_SHARED_SECRET no está configurado en el servidor.'
            ]);
        }

        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($provided === '' || !hash_equals($secret, $provided)) {
            flex_auth_json_exit(401, [
                'success' => false,
                'message' => 'Token de API inválido o ausente.'
            ]);
        }
    }
}

if (!function_exists('issue_csrf_token')) {
    function issue_csrf_token(): string
    {
        flex_auth_start_session();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('require_csrf_token')) {
    function require_csrf_token(): void
    {
        flex_auth_start_session();
        $expected = $_SESSION['csrf_token'] ?? null;

        if ($expected === null) {
            $expected = issue_csrf_token();
        }

        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($provided === '' || !hash_equals($expected, $provided)) {
            flex_auth_json_exit(403, [
                'success' => false,
                'message' => 'Token CSRF inválido o ausente.'
            ]);
        }
    }
}
