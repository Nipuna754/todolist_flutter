<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Sri Lankan National Identity Card numbers.
 *
 * Two formats are in circulation and the MIS holds a mixture of both, so every
 * lookup has to be tried in each form:
 *
 *   old  9 digits + V/X   YY DDD NNNN C     e.g. 731234567V
 *   new  12 digits        YYYY DDD NNNNN    e.g. 197312304567
 *
 * DDD is the day of the year of birth, with 500 added for female holders.
 */
final class Nic
{
    private function __construct(
        private readonly string $normalised,
        private readonly bool $isNew,
    ) {
    }

    /**
     * Accepts anything a student is likely to type - spaces, dashes and a
     * lower case check letter are all tolerated.
     *
     * @throws \InvalidArgumentException when the value cannot be a valid NIC.
     */
    public static function parse(string $raw): self
    {
        $value = strtoupper(preg_replace('/[\s\-]+/', '', $raw) ?? '');

        if (preg_match('/^(\d{9})[VX]$/', $value, $m) === 1) {
            self::assertDayOfYear((int) substr($m[1], 2, 3));

            return new self($value, false);
        }

        if (preg_match('/^\d{12}$/', $value) === 1) {
            $year = (int) substr($value, 0, 4);
            if ($year < 1900 || $year > (int) date('Y')) {
                throw new \InvalidArgumentException('The year of birth in this NIC number is not valid.');
            }
            self::assertDayOfYear((int) substr($value, 4, 3));

            return new self($value, true);
        }

        throw new \InvalidArgumentException(
            'Enter a National ID number as 9 digits followed by V or X (e.g. 931234567V), or as 12 digits.'
        );
    }

    public static function tryParse(string $raw): ?self
    {
        try {
            return self::parse($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function value(): string
    {
        return $this->normalised;
    }

    /** The 12 digit form, e.g. 197312304567. */
    public function newFormat(): string
    {
        if ($this->isNew) {
            return $this->normalised;
        }

        // YY DDD NNNN -> 19YY DDD 0NNNN
        return '19' . substr($this->normalised, 0, 5) . '0' . substr($this->normalised, 5, 4);
    }

    /**
     * The 9 digits of the old form, without the check letter. The letter cannot
     * be recovered from a 12 digit number, so comparisons use this prefix.
     */
    public function oldFormatDigits(): string
    {
        if (!$this->isNew) {
            return substr($this->normalised, 0, 9);
        }

        // YYYY DDD NNNNN -> YY DDD NNNN (the leading zero of the serial is dropped)
        return substr($this->normalised, 2, 5) . substr($this->normalised, 8, 4);
    }

    /**
     * Every spelling this NIC may have been recorded under in the MIS, for use
     * as the IN (...) list of a lookup.
     *
     * @return list<string>
     */
    public function lookupVariants(): array
    {
        $digits = $this->oldFormatDigits();

        return array_values(array_unique([
            $this->normalised,
            $this->newFormat(),
            $digits . 'V',
            $digits . 'X',
            $digits,
        ]));
    }

    /** Masked for logs, so that a NIC number is never written out in full. */
    public function masked(): string
    {
        return substr($this->normalised, 0, 4) . str_repeat('*', max(0, strlen($this->normalised) - 6))
            . substr($this->normalised, -2);
    }

    private static function assertDayOfYear(int $day): void
    {
        $day = $day > 500 ? $day - 500 : $day;
        if ($day < 1 || $day > 366) {
            throw new \InvalidArgumentException('The date of birth encoded in this NIC number is not valid.');
        }
    }
}
