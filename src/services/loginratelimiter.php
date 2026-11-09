<?php
declare(strict_types=1);

namespace App\Services;

final class LoginRateLimiter
{
    private string $directory;

    public function __construct(
        private int $maxAttempts = 8,
        private int $windowSeconds = 900
    ) {
        $root = dirname(__DIR__, 2);
        $storageRoot = rtrim((string) env('STORAGE_PATH', $root . '/storage/private'), '/');
        $this->directory = $storageRoot . '/login-rate';
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }
    }

    public function retryAfter(string $ip, string $email): int
    {
        $state = $this->read($ip, $email);
        if (($state['attempts'] ?? 0) < $this->maxAttempts) {
            return 0;
        }
        return max(0, (int) ($state['window_started_at'] ?? 0) + $this->windowSeconds - time());
    }

    public function registerFailure(string $ip, string $email): void
    {
        $state = $this->read($ip, $email);
        $now = time();
        if (($state['window_started_at'] ?? 0) + $this->windowSeconds <= $now) {
            $state = ['attempts' => 0, 'window_started_at' => $now];
        }
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $this->write($ip, $email, $state);
    }

    public function clear(string $ip, string $email): void
    {
        $path = $this->path($ip, $email);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function read(string $ip, string $email): array
    {
        $path = $this->path($ip, $email);
        $decoded = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (!is_array($decoded) || (int) ($decoded['window_started_at'] ?? 0) + $this->windowSeconds <= time()) {
            return ['attempts' => 0, 'window_started_at' => time()];
        }
        return $decoded;
    }

    private function write(string $ip, string $email, array $state): void
    {
        @file_put_contents($this->path($ip, $email), json_encode($state), LOCK_EX);
    }

    private function path(string $ip, string $email): string
    {
        return $this->directory . '/' . hash('sha256', $ip . '|' . mb_strtolower(trim($email))) . '.json';
    }
}
