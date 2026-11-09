<?php
declare(strict_types=1);
namespace App\Middleware;
use App\Core\Request;
use App\Core\Response;
final class PermissionMiddleware
{
    public function __construct(private string $permission) {}
    public function handle(Request $request): ?Response
    {
        $auth = $request->attribute('auth', []);
        $permissions = $auth['permissions'] ?? [];
        if (!in_array($this->permission, $permissions, true)) {
            return Response::json(['success' => false, 'error' => 'Forbidden', 'message' => 'Nemáte oprávnění: ' . $this->permission], 403);
        }
        return null;
    }
}
