<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Settings loaded from config/config.php (see config/config.example.php).
 */
final class Config
{
    /** @var array<string, mixed> */
    private $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(?string $path = null): self
    {
        if ($path === null) {
            $path = dirname(__DIR__) . '/config/config.php';
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(
                'Configuration not found. Copy config/config.example.php to config/config.php and fill it in.'
            );
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Configuration file must return an array: ' . $path);
        }

        return new self($values);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Dot-separated lookup, e.g. get('mis.table').
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return mixed */
    public function require(string $key)
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException('Missing required configuration key: ' . $key);
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /** @return array<string, mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }
}
