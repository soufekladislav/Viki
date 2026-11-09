<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\LoginRateLimiter;
final class AuthController
{
    public function __construct(
        private AuthService $authService,
        private LoginRateLimiter $rateLimiter
    ) {}
    public function login(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $storeId = $request->input('store_id');
        if ($email === '' || $password === '') {
            return Response::json(['success' => false, 'error' => 'Validation failed', 'message' => 'Email a heslo jsou povinné.'], 422);
        }
        $retryAfter = $this->rateLimiter->retryAfter($request->clientIp(), $email);
        if ($retryAfter > 0) {
            return Response::json([
                'success' => false,
                'error' => 'Too many attempts',
                'message' => 'Příliš mnoho neúspěšných pokusů. Zkus to znovu později.',
                'retry_after' => $retryAfter,
            ], 429);
        }
        $result = $this->authService->login($email, $password, $storeId !== null ? (int)$storeId : null);
        if (($result['success'] ?? false) === true) {
            $this->rateLimiter->clear($request->clientIp(), $email);
        } else {
            $this->rateLimiter->registerFailure($request->clientIp(), $email);
        }
        return Response::json($result, $result['status'] ?? 200);
    }
    public function me(Request $request): Response
    {
        return Response::json(['success' => true, 'data' => $request->attribute('auth')]);
    }
}
