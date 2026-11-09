<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

require_once dirname(__DIR__) . '/src/helpers.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    /** @var App $app */
    $response = $app->handle(Request::capture());
    $response->send();
} catch (Throwable $e) {
    $safeMessage = 'Server aplikace není správně nakonfigurován.';
    if ($e instanceof PDOException) {
        $safeMessage = 'Viki se nemůže připojit k databázi. Zkontroluj DB údaje v souboru .env.';
    } elseif (str_contains($e->getMessage(), 'APP_KEY')) {
        $safeMessage = 'V souboru .env chybí platný APP_KEY.';
    }
    Response::json([
        'success' => false,
        'error' => 'Server error',
        'message' => env('APP_DEBUG', 'false') === 'true' ? $e->getMessage() : $safeMessage,
    ], 500)->send();
}
