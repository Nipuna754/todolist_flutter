<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Carries a message written for the student. Anything a student should not see
 * goes to the audit log instead, never into this message.
 */
final class DownloadException extends \RuntimeException
{
}
