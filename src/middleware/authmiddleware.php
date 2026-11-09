<?php
declare(strict_types=1);
namespace App\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
final class AuthMiddleware
{
    public function __construct(private AuthService $authService) {}
    public function handle(Request $request): ?Response
    {
        $token = $request->bearerToken();
        if (!$token) return Response::json(['success' => false, 'error' => 'Unauthorized', 'message' => 'Chybí bearer token.'], 401);
        $payload = $this->authService->validateToken($token);
        if (!$payload) return Response::json(['success' => false, 'error' => 'Unauthorized', 'message' => 'Token je neplatný nebo expirovaný.'], 401);
        $request->setAttribute('auth', $payload);
        return null;
    }
}
