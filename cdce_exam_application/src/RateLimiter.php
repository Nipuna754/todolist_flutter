<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * A NIC number is the only thing standing between a visitor and a student's
 * name and registration number, so lookups are capped per client to stop the
 * number space being walked. Counters are files rather than rows, to keep the
 * tool independent of the MIS being up.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->string('rate_limit.directory', dirname(__DIR__) . '/var/rate-limit'),
            $config->int('rate_limit.max_attempts', 10),
            $config->int('rate_limit.window_seconds', 900),
        );
    }

    public function tooManyAttempts(string $clientKey): bool
    {
        return count($this->recentAttempts($clientKey)) >= $this->maxAttempts;
    }

    public function record(string $clientKey): void
    {
        $attempts = $this->recentAttempts($clientKey);
        $attempts[] = time();

        $this->write($clientKey, $attempts);
    }

    public function secondsUntilReset(string $clientKey): int
    {
        $attempts = $this->recentAttempts($clientKey);
        if ($attempts === []) {
            return 0;
        }

        return max(0, ($attempts[0] + $this->windowSeconds) - time());
    }

    /** @return list<int> timestamps still inside the window, oldest first */
    private function recentAttempts(string $clientKey): array
    {
        $path = $this->path($clientKey);
        if (!is_readable($path)) {
            return [];
        }

        $cutoff = time() - $this->windowSeconds;
        $attempts = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line) && (int) $line > $cutoff) {
                $attempts[] = (int) $line;
            }
        }

        sort($attempts);

        return $attempts;
    }

    /** @param list<int> $attempts */
    private function write(string $clientKey, array $attempts): void
    {
        $path = $this->path($clientKey);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }

        file_put_contents($path, implode("\n", $attempts), LOCK_EX);
    }

    /** The client key is hashed, so the store never holds a bare IP address. */
    private function path(string $clientKey): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $clientKey) . '.txt';
    }
}
