# Where the particulars sit on the template

`templates/ba_100_level_2026.pdf` is the registrar's form, unchanged. It is a
flat PDF: there are **no AcroForm fields**, so the four particulars are drawn at
fixed coordinates rather than filled into widgets. Those coordinates live in
`src/FormLayout.php`.

Both pages are 595.44 x 842.04 pt (A4). Everything below is in PDF points
measured from the **bottom-left** corner of page 1, which is how
`FormLayout` stores them; `ApplicationPdf::fromBottom()` flips them for FPDF.

The numbers were read out of the template's own vector geometry (its `re`
operators and text matrices), not estimated from a screenshot.

## The four auto-printed items

| Item | What the template provides | Where we print |
|---|---|---|
| 01 Registration No | Ruled box `x 81.50-257.93`, `y 621.46-657.70`, pre-printed `AE/BA/…………...../........................` at baseline 624.0 | Placeholder painted over with white `x 83.0-256.4`, `y 622.0-638.0`; number printed at `x 86.3`, baseline 624.0 |
| 02 National ID No | Empty ruled box `x 394.05-565.05`, `y 637.55-658.20` | `x 400.0`, baseline 643.9 (optically centred in the box) |
| 04 Name with Initials | Blank band between the label (baseline 583.1) and the discrepancy note box (top edge 547.31) | Block `x 80.3`, `y 549.5-579.5`, width 466, up to 2 lines |
| 05 Name in Full | Blank band between the label (baseline 451.0) and the second note box (top edge 396.27) | Block `x 80.3`, `y 399.0-447.0`, width 466, up to 3 lines |

Items 04 and 05 shrink from 12 pt down to 8.5 pt and wrap before they will
overrun their band, so a long name cannot collide with the label above or the
note below. `tools/calibrate.php --long` exercises that path.

## The correction grids are left empty on purpose

The template puts a grid of single-character cells under each name:

| Grid | Rows (bottom `y`) | Cells | Cell width | Height |
|---|---|---|---|---|
| Item 04 correction | 491.23, 472.99 | 36 | 12.78 | 17.76 |
| Item 05 correction | 331.61, 313.49, 295.25 | 35 | 13.14 | ~17.7 |

All five rows start at `x 71.54`. They exist for the student to write a
corrected name in capitals when the auto-printed one is wrong, so nothing is
printed into them. They are listed in `FormLayout::correctionGrids()` only so
that `tools/calibrate.php --guides` can outline them and show that the printed
blocks clear them.

## Re-calibrating after a template change

1. Drop the new form in as `templates/ba_100_level_2026.pdf`.
2. Run `php tools/calibrate.php --guides --out=/tmp/guides.pdf` and open it.
   The red outlines are the name blocks and the NIC box; the green outlines are
   the correction grids.
3. Nudge the constants in `src/FormLayout.php` until the outlines sit inside the
   ruled areas.
4. Run `php tools/calibrate.php --long` and check that the longest names still
   fit.
5. Run `php tests/run.php`.

Nothing outside `src/FormLayout.php` needs to change for a layout adjustment.
