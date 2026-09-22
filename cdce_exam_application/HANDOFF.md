# Handoff prompt

Paste the block below into a fresh Claude conversation to continue the
deployment. Attach `apply_examination2.tar.gz` to that message if you have it,
since a chat session cannot read this repository.

---

I need help deploying a PHP tool to a university web server. I run all the
commands myself over SSH from Windows PowerShell and paste you the output — you
cannot reach either machine.

## What the tool does

Students at the Centre for Distance and Continuing Education, University of
Peradeniya, go to `https://cdce.pdn.ac.lk/tools/apply_examination2/`, enter
their National Identity Card number, and download their *BA (External – New
Syllabus) Registration and Examination Application, 100 Level, December/January
2026/2027* as a PDF with four particulars already printed on it:

1. Registration No (item 01 on the form)
2. National ID No (item 02)
3. Name with Initials (item 04)
4. Name in Full (item 05)

Those come from the CDCE MIS, a MySQL database at `10.40.129.2`
(web interface `http://10.40.129.2/cdcesys/mis_1/`). Everything else on the
form is left blank for the student to fill in by hand, including the
capital-letter correction grids under each name, which exist so a student can
correct a wrongly printed name.

## Current state

The code is written, tested (32 tests passing) and complete. It is a
self-contained PHP 8 package, given to me as `apply_examination2.tar.gz`
(~1.3 MB) with its Composer dependencies already bundled, so the server needs
no Composer and no internet access.

**Nothing has been deployed and no CDCE system has been changed.** Neither
`10.40.129.2` nor `cdce.pdn.ac.lk` was ever contacted while the code was
written — both were unreachable from that environment. It was all tested
against throwaway fixtures holding fabricated students. The live tool at that
URL still serves whatever it served before.

## Package layout

```
apply_examination2/
  public/index.php          student-facing page; GET shows the form, POST streams the PDF
  public/bootstrap.php      session, CSRF, security headers, client identification
  src/Nic.php               NIC parsing, old/new conversion, lookup variants
  src/MisRepository.php     read-only MIS lookup
  src/ApplicationPdf.php    stamps the particulars onto the blank form
  src/FormLayout.php        the print coordinates - the only file a layout change touches
  src/RateLimiter.php       per-client attempt cap
  src/AuditLog.php          append-only log, NIC numbers masked
  src/StudentRecord.php     the four particulars, cleaned
  src/ApplicationService.php  orchestrates one download
  src/Config.php            loads config/config.php
  config/config.example.php copy to config/config.php - holds MIS credentials
  templates/ba_100_level_2026.pdf   the registrar's blank form, unchanged
  tools/preflight.php       can this server run it? no config needed
  tools/discover_mis.sql    read-only: find the real MIS table and column names
  tools/check_data_quality.sql  read-only: will the tool work on this data?
  tools/check_mis.php       verify config against the live MIS, generate a real PDF
  tools/try_nic.php         show how a NIC is parsed, no database needed
  tools/calibrate.php       render a sample form, no database needed
  tests/run.php             32 tests, no framework required
  DEPLOY.md                 server-side deployment steps
  DEPLOY-WINDOWS.md         PowerShell upload and smoke test
  docs/template-geometry.md where every print coordinate came from
  vendor/                   bundled dependencies (setasign/fpdf, setasign/fpdi)
```

`DEPLOY.md` and `DEPLOY-WINDOWS.md` inside the package already contain the full
deployment procedure. Please read them before advising me, and correct them if
something does not match what my server actually looks like.

## Where we stopped

At **Step 1: read-only recon of the web server**, which I had not yet run. The
next thing to do is establish, over SSH:

- the PHP version Apache runs (not just the CLI version) and whether
  `pdo_mysql` is installed
- the document root, and how `/tools/apply_examination2/` is currently served
- whether the **web server** can reach `10.40.129.2` on port 3306 — I can reach
  the MIS from my desk, but that does not mean the web server can, and nothing
  works if it cannot
- whether I have sudo and whether `mod_rewrite` is enabled

## The two unknowns that still need filling in

Both live in `config/config.php` and both need my MIS access:

1. **The real table and column names.** `config.example.php` ships with
   guesses (`student`, `reg_no`, `nic_no`, `name_with_initials`,
   `name_in_full`). Run `tools/discover_mis.sql` against the MIS to find the
   real ones.
2. **The eligibility rule** (`mis.eligibility.sql`) — which candidates are
   entitled to a 100 Level repeat form for 2026. This is the important one: if
   it is too permissive, any student in the MIS can download a form. Run
   `tools/check_data_quality.sql` once the names from step 1 are in.

Both scripts are read-only and return schema and counts only, never a student
row, so their output is safe to paste to you.

## Things already settled - please do not redo these

- **The print coordinates.** The blank form is a flat PDF with no fillable
  fields, so values are stamped at fixed points read from the template's own
  vector geometry. They are all in `src/FormLayout.php`, documented in
  `docs/template-geometry.md`, and verified by rendering the output. Run
  `php tools/calibrate.php --guides` if you want to see them.
- **NIC handling.** The MIS holds both the 9-digit (`931234567V`) and 12-digit
  (`199312304567`) formats, some with stray spaces and dashes. A lookup tries
  every spelling, so either format finds the student. Old-format equivalents
  are generated only for 19xx births — folding a 2000s number down to 9 digits
  produces a number that reads as a 1900 birth and could match a mistyped
  record, handing a student someone else's name.
- **Printed NIC is tidied but not converted.** `88 5234-567 X` prints as
  `885234567X`; a 9-digit number is not converted to 12-digit, because the old
  number is what is on the card in the student's pocket.
- **Refusals.** An unknown NIC, a NIC matching two eligible candidates, and a
  record missing any of the four particulars are all refused with a message
  telling the student what to do, rather than printing a half-filled form that
  would be rejected at the counter.

## Security requirements I must not skip

- The MIS account must have `SELECT` only on the student table. I will verify
  an `UPDATE` is refused before going live.
- `config/config.php` holds the MIS password and must never be web-reachable.
  `var/` holds the audit log and must not be either. Before the tool is
  announced to students, both of these must return **403 or 404**:
  - `https://cdce.pdn.ac.lk/tools/apply_examination2/config/config.php`
  - `https://cdce.pdn.ac.lk/tools/apply_examination2/var/download.log`

  If the first returns 200, the tool comes down immediately.
- Preferred layout is the package **outside** the document root with an Apache
  `Alias` pointing at `public/`, so nothing else is reachable at all. The
  package also ships `.htaccess` files for the case where it must sit inside
  the document root, but that is the weaker option.
- A NIC number is the only thing protecting a student's name and registration
  number, which is why there is a per-client attempt cap and why the audit log
  masks NIC numbers.

## How I would like to work

One step at a time. Give me a single block of commands, wait for me to paste
the output, then give me the next step. I am on Windows PowerShell with
OpenSSH; the server is Linux. Tell me plainly when a step is destructive or
hard to undo, and give me the rollback for it.

## Start by asking me for

The output of the Step 1 recon block in `DEPLOY-WINDOWS.md` / `DEPLOY.md`
Step 0 — or write me a better one.
