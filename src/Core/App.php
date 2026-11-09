<?php
declare(strict_types=1);
namespace App\Core;
final class App
{
    public function __construct(private Container $container, private Router $router) {}
    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
}
