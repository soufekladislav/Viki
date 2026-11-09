<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        private array $data,
        private int $status = 200,
        private array $headers = ['Content-Type' => 'application/json; charset=utf-8']
    ) {}

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        send_cors_headers();
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode(
            $this->data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
}
