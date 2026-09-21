<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

final class GeneratedApplication
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly StudentRecord $student,
    ) {
    }
}
