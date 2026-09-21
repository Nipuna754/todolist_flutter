<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

use setasign\Fpdi\Fpdi;

/**
 * Stamps a student's particulars onto the blank BA 100 Level application.
 *
 * The template is a flat PDF with no form fields, so each value is drawn at
 * the coordinates in FormLayout. Both pages are carried through untouched
 * apart from the four items on page 1.
 */
final class ApplicationPdf extends Fpdi
{
    private const FONT = 'Helvetica';

    public function __construct(private readonly string $templatePath)
    {
        parent::__construct('P', 'pt', [FormLayout::PAGE_WIDTH, FormLayout::PAGE_HEIGHT]);

        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->SetCreator('CDCE Examination Application Tool');
        $this->SetTitle('BA (External) 100 Level - Registration and Examination Application 2026/2027');
    }

    /**
     * @param bool $guides draw the target areas as outlines, for calibration.
     */
    public function render(StudentRecord $student, bool $guides = false): void
    {
        if (!is_readable($this->templatePath)) {
            throw new \RuntimeException('Application template is missing: ' . $this->templatePath);
        }

        $pageCount = $this->setSourceFile($this->templatePath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $imported = $this->importPage($page);
            $this->AddPage('P', [FormLayout::PAGE_WIDTH, FormLayout::PAGE_HEIGHT]);
            $this->useTemplate($imported, 0, 0, FormLayout::PAGE_WIDTH, FormLayout::PAGE_HEIGHT);

            if ($page === FormLayout::PAGE) {
                if ($guides) {
                    $this->drawGuides();
                }
                $this->printParticulars($student);
            }
        }
    }

    /** The finished PDF as a binary string. */
    public function toBinaryString(): string
    {
        return (string) $this->Output('S');
    }

    private function printParticulars(StudentRecord $student): void
    {
        $this->SetTextColor(0, 0, 0);

        $this->printOverDottedLine(FormLayout::REGISTRATION_NO, $student->registrationNo);
        $this->printSingleLine(FormLayout::NATIONAL_ID, $student->nationalId);
        $this->printBlock(FormLayout::NAME_WITH_INITIALS, $student->nameWithInitials);
        $this->printBlock(FormLayout::NAME_IN_FULL, $student->nameInFull);
    }

    /**
     * Item 01: paint out the "AE/BA/…../……" placeholder and print the real
     * registration number in its place, so the two can never be read together.
     *
     * @param array<string, mixed> $spec
     */
    private function printOverDottedLine(array $spec, string $value): void
    {
        $erase = $spec['erase'];
        $this->SetFillColor(255, 255, 255);
        $this->Rect(
            (float) $erase['x'],
            $this->fromBottom((float) $erase['y'] + (float) $erase['height']),
            (float) $erase['width'],
            (float) $erase['height'],
            'F'
        );

        $this->printSingleLine($spec, $value);
    }

    /** @param array<string, mixed> $spec */
    private function printSingleLine(array $spec, string $value): void
    {
        if ($value === '') {
            return;
        }

        $size = $this->fitFontSize(
            $value,
            (float) $spec['max_width'],
            (float) $spec['font_size'],
            (float) $spec['min_font_size']
        );

        $this->SetFont(self::FONT, '', $size);
        $this->Text((float) $spec['x'], $this->fromBottom((float) $spec['baseline']), $this->encode($value));
    }

    /**
     * Items 04 and 05: wrap the name into the blank band under the label and
     * centre the resulting block vertically, shrinking the type until it fits.
     *
     * @param array<string, mixed> $spec
     */
    private function printBlock(array $spec, string $value): void
    {
        if ($value === '') {
            return;
        }

        $width = (float) $spec['width'];
        $maxLines = (int) $spec['max_lines'];
        $leading = (float) $spec['leading'];
        $available = (float) $spec['top'] - (float) $spec['bottom'];

        $size = (float) $spec['font_size'];
        $min = (float) $spec['min_font_size'];
        $lines = [];

        while (true) {
            $this->SetFont(self::FONT, '', $size);
            $lines = $this->wrap($value, $width);
            $lineLeading = $leading * ($size / (float) $spec['font_size']);

            if (count($lines) <= $maxLines && count($lines) * $lineLeading <= $available) {
                $leading = $lineLeading;
                break;
            }

            if ($size <= $min) {
                // Unreachable for any real name: 3 lines of 466pt at 8.5pt hold
                // far more than the longest on record. If it ever happens,
                // overflowing into the note below would make the whole form
                // unreadable, so the block is clipped to the lines it may use.
                $leading = $lineLeading;
                $lines = array_slice($lines, 0, $maxLines);
                break;
            }

            $size = max($min, $size - 0.5);
        }

        $this->SetFont(self::FONT, '', $size);

        $blockHeight = count($lines) * $leading;
        $firstBaseline = (float) $spec['top'] - (($available - $blockHeight) / 2) - $size * 0.8;

        foreach ($lines as $i => $line) {
            $this->Text(
                (float) $spec['x'],
                $this->fromBottom($firstBaseline - $i * $leading),
                $this->encode($line)
            );
        }
    }

    /**
     * Greedy word wrap. Assumes the current font is already set.
     *
     * @return list<string>
     */
    private function wrap(string $value, float $width): array
    {
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $this->GetStringWidth($this->encode($candidate)) > $width) {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [$value] : $lines;
    }

    private function fitFontSize(string $value, float $maxWidth, float $size, float $min): float
    {
        while ($size > $min) {
            $this->SetFont(self::FONT, '', $size);
            if ($this->GetStringWidth($this->encode($value)) <= $maxWidth) {
                break;
            }
            $size -= 0.25;
        }

        return max($size, $min);
    }

    /** FPDF measures y from the top of the page; FormLayout from the bottom. */
    private function fromBottom(float $y): float
    {
        return FormLayout::PAGE_HEIGHT - $y;
    }

    /**
     * FPDF's core fonts are single byte. MIS names are ASCII in practice, but
     * a stray accented character must not emit a broken glyph.
     */
    private function encode(string $value): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);

        return $converted === false ? $value : $converted;
    }

    private function drawGuides(): void
    {
        $this->SetDrawColor(220, 0, 0);
        $this->SetLineWidth(0.4);

        foreach ([
            ['spec' => FormLayout::NAME_WITH_INITIALS],
            ['spec' => FormLayout::NAME_IN_FULL],
        ] as $block) {
            $spec = $block['spec'];
            $this->Rect(
                (float) $spec['x'],
                $this->fromBottom((float) $spec['top']),
                (float) $spec['width'],
                (float) $spec['top'] - (float) $spec['bottom']
            );
        }

        $nic = FormLayout::NATIONAL_ID;
        $this->Rect(
            (float) $nic['x'],
            $this->fromBottom((float) $nic['baseline'] + 12.0),
            (float) $nic['max_width'],
            16.0
        );

        $this->SetDrawColor(0, 140, 0);
        foreach (FormLayout::correctionGrids() as $grid) {
            $this->Rect(
                (float) $grid['x'],
                $this->fromBottom((float) $grid['y'] + (float) $grid['height']),
                (float) $grid['cell_width'] * (float) $grid['cells'],
                (float) $grid['height']
            );
        }

        $this->SetDrawColor(0, 0, 0);
    }
}
