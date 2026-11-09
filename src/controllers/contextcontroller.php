<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Request;
use App\Core\Response;
use App\Services\StoreContextService;
final class ContextController
{
    public function __construct(private StoreContextService $service) {}
    public function switchStore(Request $request): Response
    {
        $auth = $request->attribute('auth');
        $storeId = (int)$request->input('store_id', 0);
        if ($storeId <= 0) return Response::json(['success' => false, 'error' => 'Validation failed', 'message' => 'store_id je povinné.'], 422);
        $result = $this->service->switchStore((int)$auth['user']['id'], $storeId);
        return Response::json($result, $result['status'] ?? 200);
    }
    public function permissions(Request $request): Response
    {
        $auth = $request->attribute('auth');
        return Response::json(['success' => true, 'data' => ['store' => $auth['current_store'], 'permissions' => $auth['permissions']]]);
    }
}
