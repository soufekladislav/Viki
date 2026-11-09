<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\ContextController;
use App\Controllers\StoreController;
use App\Controllers\UserController;
use App\Controllers\WooController;
use App\Core\App;
use App\Core\Container;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Repositories\CatalogRepository;
use App\Repositories\StoreRepository;
use App\Repositories\UserRepository;
use App\Repositories\StoreConnectionRepository;
use App\Repositories\WooMapRepository;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\StoreContextService;
use App\Services\WooConnectorService;
use App\Services\WooSyncService;
use App\Services\LoginRateLimiter;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    static $resolved = [];

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    if (isset($resolved[$class])) {
        require_once $resolved[$class];
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        $resolved[$class] = $file;
        require_once $file;
        return;
    }

    // Nektere FTP klienty a spravci souboru prevadeji nazvy pri uploadu
    // na mala pismena. Linux je rozlisuje, proto najdeme kazdou cast cesty
    // bez ohledu na velikost pismen a pouzijeme skutecny nazev na serveru.
    $segments = explode('\\', $relativeClass);
    $current = rtrim($baseDir, '/');

    foreach ($segments as $index => $segment) {
        $expected = $segment . ($index === array_key_last($segments) ? '.php' : '');
        if (!is_dir($current)) {
            return;
        }

        $matched = null;
        foreach (scandir($current) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strcasecmp($entry, $expected) === 0) {
                $matched = $entry;
                break;
            }
        }

        if ($matched === null) {
            return;
        }
        $current .= '/' . $matched;
    }

    if (is_file($current) && is_readable($current)) {
        $resolved[$class] = $current;
        require_once $current;
    }
});

load_env(dirname(__DIR__) . '/.env');

$container = new Container();

$container->singleton('config.app', fn () => require dirname(__DIR__) . '/config/app.php');
$container->singleton('config.database', fn () => require dirname(__DIR__) . '/config/database.php');
$container->singleton(PDO::class, fn () => db());

$container->singleton(UserRepository::class, fn ($c) => new UserRepository(
    $c->get(PDO::class),
    $c->get('config.database')
));

$container->singleton(StoreRepository::class, fn ($c) => new StoreRepository(
    $c->get(PDO::class),
    $c->get('config.database')
));

$container->singleton(CatalogRepository::class, fn ($c) => new CatalogRepository(
    $c->get(PDO::class),
    $c->get('config.database')
));

$container->singleton(StoreConnectionRepository::class, fn ($c) => new StoreConnectionRepository(
    $c->get(PDO::class),
    $c->get('config.database'),
    $c->get('config.app')
));

$container->singleton(WooMapRepository::class, fn ($c) => new WooMapRepository(
    $c->get(PDO::class),
    $c->get('config.database')
));

$container->singleton(AuthService::class, fn ($c) => new AuthService(
    $c->get(UserRepository::class),
    $c->get(StoreRepository::class),
    $c->get('config.app')
));
$container->singleton(LoginRateLimiter::class, fn () => new LoginRateLimiter());

$container->singleton(StoreContextService::class, fn ($c) => new StoreContextService(
    $c->get(StoreRepository::class),
    $c->get(AuthService::class)
));

$container->singleton(CatalogService::class, fn ($c) => new CatalogService(
    $c->get(CatalogRepository::class)
));

$container->singleton(WooConnectorService::class, fn ($c) => new WooConnectorService(
    $c->get(PDO::class),
    $c->get(StoreConnectionRepository::class),
    $c->get('config.database')
));

$container->singleton(WooSyncService::class, fn ($c) => new WooSyncService(
    $c->get(CatalogRepository::class),
    $c->get(StoreConnectionRepository::class),
    $c->get(WooMapRepository::class),
    $c->get(WooConnectorService::class)
));

$container->singleton(AuthController::class, fn ($c) => new AuthController(
    $c->get(AuthService::class),
    $c->get(LoginRateLimiter::class)
));

$container->singleton(StoreController::class, fn ($c) => new StoreController(
    $c->get(StoreRepository::class)
));

$container->singleton(ContextController::class, fn ($c) => new ContextController(
    $c->get(StoreContextService::class)
));

$container->singleton(CatalogController::class, fn ($c) => new CatalogController(
    $c->get(CatalogService::class)
));

$container->singleton(UserController::class, fn ($c) => new UserController(
    $c->get(UserRepository::class),
    $c->get(StoreRepository::class)
));

$container->singleton(WooController::class, fn ($c) => new WooController(
    $c->get(WooConnectorService::class),
    $c->get(WooSyncService::class),
    $c->get(StoreRepository::class)
));

$router = new Router($container);

$healthHandler = function () {
    return \App\Core\Response::json([
        'success' => true,
        'data' => [
            'service' => 'Viki API',
            'version' => '0.8.2',
            'time' => date(DATE_ATOM),
            'configuration' => [
                'app_key_secure' => strlen((string) env('APP_KEY', '')) >= 32,
                'app_url_configured' => trim((string) env('APP_URL', '')) !== '',
            ],
        ],
    ]);
};

$router->get('/health', $healthHandler);
$router->get('/api/health', $healthHandler);
$router->get('/api/v2/health', $healthHandler);

$router->post('/api/v2/auth/login', [AuthController::class, 'login']);
$router->get('/api/v2/auth/me', [AuthController::class, 'me'], [AuthMiddleware::class]);

$router->get('/api/v2/stores', [StoreController::class, 'index'], [AuthMiddleware::class]);
$router->get('/api/v2/stores/{id}', [StoreController::class, 'show'], [AuthMiddleware::class]);
$router->post('/api/v2/stores', [StoreController::class, 'create'], [AuthMiddleware::class]);
$router->put('/api/v2/stores/{id}', [StoreController::class, 'update'], [AuthMiddleware::class]);

$router->get('/api/v2/users', [UserController::class, 'index'], [AuthMiddleware::class]);
$router->post('/api/v2/users', [UserController::class, 'create'], [AuthMiddleware::class]);

$router->post('/api/v2/context/store', [ContextController::class, 'switchStore'], [AuthMiddleware::class]);
$router->get('/api/v2/permissions', [ContextController::class, 'permissions'], [AuthMiddleware::class]);

$router->get('/api/v2/products', [CatalogController::class, 'products'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->post('/api/v2/products', [CatalogController::class, 'createProduct'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->get('/api/v2/products/{id}', [CatalogController::class, 'product'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->put('/api/v2/products/{id}', [CatalogController::class, 'updateProduct'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->delete('/api/v2/products/{id}', [CatalogController::class, 'deleteProduct'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/products/{id}/delete', [CatalogController::class, 'deleteProduct'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/products/bulk/move', [CatalogController::class, 'bulkMove'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/products/bulk/delete', [CatalogController::class, 'bulkDelete'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->get('/api/v2/categories', [CatalogController::class, 'categories'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->post('/api/v2/categories', [CatalogController::class, 'createCategory'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->get('/api/v2/categories/{id}', [CatalogController::class, 'category'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->put('/api/v2/categories/{id}', [CatalogController::class, 'updateCategory'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/categories/reorder', [CatalogController::class, 'reorderCategory'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->delete('/api/v2/categories/{id}', [CatalogController::class, 'deleteCategory'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/categories/{id}/delete', [CatalogController::class, 'deleteCategory'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);


$router->get('/api/v2/woo-connections', [WooController::class, 'index'], [AuthMiddleware::class]);
$router->get('/api/v2/stores/{id}/woo-connection', [WooController::class, 'show'], [AuthMiddleware::class]);
$router->put('/api/v2/stores/{id}/woo-connection', [WooController::class, 'save'], [AuthMiddleware::class]);
$router->post('/api/v2/stores/{id}/woo-discover', [WooController::class, 'discover'], [AuthMiddleware::class]);
$router->post('/api/v2/stores/{id}/woo-import', [WooController::class, 'firstImport'], [AuthMiddleware::class]);
$router->get('/api/v2/stores/{id}/backups', [WooController::class, 'backups'], [AuthMiddleware::class]);
$router->post('/api/v2/stores/{id}/backups', [WooController::class, 'createBackup'], [AuthMiddleware::class]);
$router->post('/api/v2/stores/{id}/backups/restore', [WooController::class, 'restoreBackup'], [AuthMiddleware::class]);
$router->get('/api/v2/stores/{id}/settings', [WooController::class, 'settings'], [AuthMiddleware::class]);
$router->put('/api/v2/stores/{id}/settings', [WooController::class, 'saveSettings'], [AuthMiddleware::class]);

$router->get('/api/v2/woo/sync-summary', [WooController::class, 'syncSummary'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->post('/api/v2/products/{id}/woo-map', [WooController::class, 'saveProductMap'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->post('/api/v2/categories/{id}/woo-map', [WooController::class, 'saveCategoryMap'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

$router->get('/api/v2/products/{id}/woo-payload', [WooController::class, 'productPayload'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.read'],
]);

$router->post('/api/v2/products/{id}/woo-sync', [WooController::class, 'syncProduct'], [
    AuthMiddleware::class,
    [PermissionMiddleware::class, 'catalog.write'],
]);

return new App($container, $router);
