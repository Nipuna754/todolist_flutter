<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Where the auto-printed particulars go on templates/ba_100_level_2026.pdf.
 *
 * All coordinates are PDF points on page 1, measured from the BOTTOM-LEFT
 * corner of the page, and were read off the template's own vector geometry
 * rather than estimated - see docs/template-geometry.md. ApplicationPdf flips
 * them to FPDF's top-left origin when it draws.
 *
 * If the registrar re-issues the template, re-run `php tools/calibrate.php
 * --guides` and nudge the numbers here; nothing else needs to change.
 */
final class FormLayout
{
    public const PAGE_WIDTH = 595.44;
    public const PAGE_HEIGHT = 842.04;

    /** The page the particulars are printed on (1-based). */
    public const PAGE = 1;

    /**
     * Item 01. The template pre-prints "AE/BA/…………...../........................"
     * inside a box spanning x 81.50-257.93, y 621.46-657.70. The dotted
     * placeholder is painted over and the real number printed in its place.
     */
    public const REGISTRATION_NO = [
        'erase' => ['x' => 83.0, 'y' => 622.0, 'width' => 173.4, 'height' => 16.0],
        'x' => 86.3,
        'baseline' => 624.0,
        'max_width' => 168.0,
        'font_size' => 11.0,
        'min_font_size' => 7.5,
    ];

    /** Item 02. An empty ruled box spanning x 394.05-565.05, y 637.55-658.20. */
    public const NATIONAL_ID = [
        'x' => 400.0,
        'baseline' => 643.9,
        'max_width' => 159.0,
        'font_size' => 11.0,
        'min_font_size' => 8.0,
    ];

    /**
     * Item 04. The blank band between the "Name with Initials:-" label
     * (baseline 583.1) and the discrepancy note box (top edge 547.31).
     */
    public const NAME_WITH_INITIALS = [
        'x' => 80.3,
        'top' => 579.5,
        'bottom' => 549.5,
        'width' => 466.0,
        'max_lines' => 2,
        'font_size' => 12.0,
        'min_font_size' => 8.5,
        'leading' => 14.0,
    ];

    /**
     * Item 05. The blank band between the "Name in Full:-" label
     * (baseline 451.0) and the second discrepancy note box (top edge 396.27).
     */
    public const NAME_IN_FULL = [
        'x' => 80.3,
        'top' => 447.0,
        'bottom' => 399.0,
        'width' => 466.0,
        'max_lines' => 3,
        'font_size' => 12.0,
        'min_font_size' => 8.5,
        'leading' => 14.0,
    ];

    /**
     * The correction grids are deliberately left empty - a student uses them
     * only when an auto-printed name is wrong. They are listed so that
     * tools/calibrate.php can draw them and prove nothing overlaps.
     *
     * @return list<array{label: string, x: float, y: float, cell_width: float, height: float, cells: int}>
     */
    public static function correctionGrids(): array
    {
        return [
            ['label' => '04 correction row 1', 'x' => 71.54, 'y' => 491.23, 'cell_width' => 12.78, 'height' => 17.76, 'cells' => 36],
            ['label' => '04 correction row 2', 'x' => 71.54, 'y' => 472.99, 'cell_width' => 12.78, 'height' => 17.76, 'cells' => 36],
            ['label' => '05 correction row 1', 'x' => 71.54, 'y' => 331.61, 'cell_width' => 13.14, 'height' => 17.76, 'cells' => 35],
            ['label' => '05 correction row 2', 'x' => 71.54, 'y' => 313.49, 'cell_width' => 13.14, 'height' => 17.64, 'cells' => 35],
            ['label' => '05 correction row 3', 'x' => 71.54, 'y' => 295.25, 'cell_width' => 13.14, 'height' => 17.76, 'cells' => 35],
        ];
    }
}
