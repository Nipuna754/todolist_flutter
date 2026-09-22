<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

final class GeneratedApplication
{
    /** @var string */
    public $filename;

    /** @var string */
    public $contents;

    /** @var StudentRecord */
    public $student;

    public function __construct(string $filename, string $contents, StudentRecord $student)
    {
        $this->filename = $filename;
        $this->contents = $contents;
        $this->student = $student;
    }
}
