# BA 100 Level repeat application — download with auto-printed particulars

Lets a student enter their **National ID number** at
`https://cdce.pdn.ac.lk/tools/apply_examination2/` and download their
*Bachelor of Arts (External – New Syllabus) – Registration and Examination
Application, 100 Level, December / January 2026/2027* with four particulars
already printed on it:

1. Registration No (item 01)
2. National ID No (item 02)
3. Name with Initials (item 04)
4. Name in Full (item 05)

Those four are read from the CDCE MIS (`http://10.40.129.2/cdcesys/mis_1/`).
Everything else on the form is left blank for the student to complete by hand,
including the correction grids under each name.

## Read this before deploying

This module was written without access to the CDCE network, so two things are
**placeholders that must be set to the real values** before it goes live:

- **The MIS schema.** `config/config.example.php` guesses at `student`,
  `reg_no`, `nic_no`, `name_with_initials`, `name_in_full`. Replace them with
  the real table and column names.
- **The eligibility rule.** `mis.eligibility.sql` decides who is entitled to a
  100 Level repeat form for 2026. The example clause is a guess. Get this wrong
  in the permissive direction and any student in the MIS can pull a form.

`php tools/check_mis.php` verifies both against the live MIS before you open
the tool to students. Nothing in this module writes to the MIS.

## Requirements

- PHP 8.0+ with `pdo`, and `pdo_mysql` for the MIS
- Composer
- Network access from the web server to the MIS host

## Install

Deploying to the CDCE server? Follow **[DEPLOY.md](DEPLOY.md)** (server side,
over SSH) and **[DEPLOY-WINDOWS.md](DEPLOY-WINDOWS.md)** (upload and smoke test
from Windows PowerShell). The release archive bundles `vendor/`, so the server
needs no Composer.

From a checkout:

```bash
composer install --no-dev --optimize-autoloader
cp config/config.example.php config/config.php
# edit config/config.php
mkdir -p var && chown www-data:www-data var    # rate-limit counters and the audit log
php tools/preflight.php      # can this server run it?
php tools/check_mis.php      # is the config right?
```

Point the vhost's document root at `public/`. Only `public/` may be
web-reachable: `config/config.php` holds the MIS password, and `var/` holds the
audit log.

If the tool has to live under an existing document root instead, deny the rest:

```apache
<Directory /var/www/tools/apply_examination2>
    <FilesMatch "\.(php|log|sqlite)$">
        Require all denied
    </FilesMatch>
</Directory>
<Directory /var/www/tools/apply_examination2/public>
    Require all granted
    <FilesMatch "\.php$">
        Require all granted
    </FilesMatch>
</Directory>
```

Use a MIS account with `SELECT` on the student table and nothing else.

## How a download goes

1. Student enters a NIC — old format (`931234567V`) or new (`199312304567`).
2. The request is counted against a per-client cap (default 10 per 15 minutes).
3. The NIC is normalised. Both formats, and both check letters, are tried
   against the MIS, because the MIS holds a mixture of the two.
4. The eligibility clause narrows that to candidates entitled to this exam.
5. Exactly one match → the application is stamped and streamed as
   `BA-100-Level-2026-AE-BA-21-1234.pdf`.

The student is told to check the printed names and, if one is wrong, to write
the correct name in the capital-letter boxes the form provides.

### When it refuses

| Situation | What the student sees |
|---|---|
| NIC is not a valid number | how to enter it correctly |
| No eligible candidate | not found; check the number, else contact CDCE |
| NIC matches two eligible candidates | contact CDCE — the MIS needs correcting |
| A particular is blank in the MIS | which one is missing; CDCE must complete it |
| MIS unreachable | try again shortly |
| Over the attempt cap | how long to wait |

A half-filled form would be rejected at the counter, so the tool refuses to
print one rather than issuing an application with a blank name.

## Layout

```
config/config.example.php   copy to config.php (git-ignored — holds credentials)
public/index.php            the student-facing page; GET shows the form, POST streams the PDF
public/bootstrap.php        session, CSRF, security headers, client identification
src/Nic.php                 old/new NIC parsing, conversion and lookup variants
src/MisRepository.php       the read-only MIS lookup
src/ApplicationPdf.php      stamps the particulars onto the template
src/FormLayout.php          the coordinates — the only file a layout change touches
src/RateLimiter.php         per-client attempt cap
src/AuditLog.php            append-only issue log, NICs masked
templates/                  the registrar's blank form, unchanged
tools/preflight.php         check the server can run it, before any config
tools/calibrate.php         render a sample without the MIS
tools/check_mis.php         verify the config against the live MIS
tools/try_nic.php           show how a NIC is parsed, no database needed
tests/run.php               the test suite
docs/template-geometry.md   where every coordinate came from
```

## Tests

```bash
php tests/run.php
```

32 tests, no test framework needed. The MIS tests run against an in-memory
SQLite database shaped like the MIS table, so the suite passes off the CDCE
network. They cover NIC conversion in both directions, matching a NIC stored in
the other format or with stray spaces, the eligibility filter turning away a
200 Level student, refusing an ambiguous match, rejecting a table name from
config that is not a plain identifier, refusing to fold a 2000s NIC down to a
1900 number, the rate-limit window, and that a generated application keeps both
pages and carries all four particulars with long names wrapped.

## Adjusting the printing

`php tools/calibrate.php --guides` renders the form with the target areas
outlined, using sample data and no database. `--long` uses the longest
plausible names. See `docs/template-geometry.md` for the coordinates and the
re-calibration steps.

## Checking National ID numbers

`php tools/try_nic.php 931234567V 200012345678` shows how each number is
parsed, which spellings the MIS will be compared against, and how it appears in
the audit log. It needs no database, so it can be run anywhere. Feed it a list
with `--file=nics.txt`.

To check numbers against the live MIS instead, use
`php tools/check_mis.php <nic>`.

## Notes for whoever maintains this

- **The NIC is printed in whichever format the MIS holds**, old or new, since
  that is the number on the card in the student's pocket. It is tidied first,
  so a value stored as `88 5234-567 X` prints as `885234567X` rather than
  putting hand-entry noise on an official form; a value that is not
  recognisable as a NIC is printed exactly as recorded, so the office can see
  what the MIS holds. To normalise every application to the 12-digit form
  instead, print `Nic::parse($student->nationalId)->newFormat()` in
  `ApplicationService::generate()`.
- **The dotted `AE/BA/…` placeholder is covered, not deleted.** It is painted
  over with a white rectangle, so it is invisible on screen and in print, but a
  text extraction of the PDF still reports the original placeholder string
  alongside the real registration number. This does not affect the printed
  form; it would matter only if something downstream parsed the PDF's text.
- **`MisRepository` quotes identifiers with backticks**, which is MySQL (and
  SQLite). A move to PostgreSQL needs `MisRepository::quote()` changed to
  double quotes.
- **A NIC is the only thing protecting a student's name and registration
  number.** The attempt cap makes walking the number space slow, but if the
  CDCE wants a real second factor, add a date-of-birth field to
  `public/index.php` and a second bound condition in
  `MisRepository::findEligibleStudent()`.
- **Names are printed in Helvetica**, a PDF core font, so no font file has to
  ship. Names outside Windows-1252 are transliterated rather than dropped.
