<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * An append-only record of who asked for what, for the registrar to reconcile
 * against applications received. NIC numbers are written masked.
 */
final class AuditLog
{
    public function __construct(private readonly string $path)
    {
    }

    public function write(string $event, string ...$details): void
    {
        $line = sprintf(
            "%s\t%s\t%s\n",
            date('c'),
            $event,
            implode("\t", array_map(static fn (string $d): string => str_replace(["\t", "\n"], ' ', $d), $details))
        );

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
