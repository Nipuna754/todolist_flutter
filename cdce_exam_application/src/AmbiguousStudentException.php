<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Raised when one NIC number matches several eligible candidates in the MIS.
 * The student cannot resolve this themselves, so the message sends them to the
 * CDCE office rather than offering a retry.
 */
final class AmbiguousStudentException extends \RuntimeException
{
}
