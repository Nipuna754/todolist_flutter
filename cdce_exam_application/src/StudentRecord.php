<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * The four particulars the application is printed with, as held by the MIS.
 */
final class StudentRecord
{
    public function __construct(
        public readonly string $registrationNo,
        public readonly string $nationalId,
        public readonly string $nameWithInitials,
        public readonly string $nameInFull,
    ) {
    }

    /**
     * @param array<string, mixed> $row keyed by the logical column names used
     *                                  in config (registration_no, nic, ...).
     */
    public static function fromRow(array $row): self
    {
        return new self(
            self::clean((string) ($row['registration_no'] ?? '')),
            self::tidyNationalId(self::clean((string) ($row['nic'] ?? ''))),
            self::clean((string) ($row['name_with_initials'] ?? '')),
            self::clean((string) ($row['name_in_full'] ?? '')),
        );
    }

    /**
     * NIC numbers in the MIS carry spaces and dashes from years of hand entry.
     * Printing "88 5234-567 X" on an application that goes to the registrar
     * looks like a mistake, so the number is tidied to the plain form - keeping
     * whichever format the MIS holds, because that is the number on the card in
     * the student's pocket.
     *
     * Anything that is not recognisable as a NIC is printed as recorded rather
     * than dropped: the office needs to see what the MIS actually holds.
     */
    private static function tidyNationalId(string $value): string
    {
        return Nic::tryParse($value)?->value() ?? $value;
    }

    /**
     * Names in the MIS carry stray double spaces and the odd non-breaking
     * space from years of copy-paste; squash them so the print is even.
     */
    private static function clean(string $value): string
    {
        $value = str_replace("\xC2\xA0", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * A record is printable only if every field the form needs is present -
     * a half-filled application would be rejected at the counter.
     *
     * @return list<string> the labels of the missing particulars
     */
    public function missingFields(): array
    {
        $missing = [];
        foreach ([
            'Registration No' => $this->registrationNo,
            'National ID' => $this->nationalId,
            'Name with Initials' => $this->nameWithInitials,
            'Name in Full' => $this->nameInFull,
        ] as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** A filename-safe form of the registration number, e.g. AE-BA-21-1234. */
    public function slug(): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $this->registrationNo) ?? '';

        return trim($slug, '-') ?: 'application';
    }
}
