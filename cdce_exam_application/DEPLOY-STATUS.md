# Where this got to

Last updated: 2026-09-21

## Nothing has been deployed yet

The code is written, tested and pushed. **No change has been made to any CDCE
system** — not the MIS at `10.40.129.2`, not the web server at
`cdce.pdn.ac.lk`. Deployment had reached Step 1 (read-only recon of the web
server) and that step had not been run.

## What has and has not been touched

| System | State |
|---|---|
| CDCE MIS (`10.40.129.2`) | **Never contacted.** It is on the university's internal network and was unreachable from the environment this was built in. No connection, no query, no change. |
| CDCE web server (`cdce.pdn.ac.lk`) | **Never contacted.** Blocked by the build environment's network policy. Nothing uploaded, nothing configured. |
| The live tool at `/tools/apply_examination2/` | **Untouched.** It still serves whatever it served before. |

Everything was tested against throwaway fixtures: an in-memory SQLite database
for the test suite, and a local MariaDB holding eight fabricated student rows,
which was dropped afterwards. No real student data was ever read.

## Continuing in a different Claude conversation

`HANDOFF.md` holds a self-contained prompt to paste into a fresh chat, covering
what the tool does, what is already settled, what is still unknown and the
security checks that must not be skipped. Attach `apply_examination2.tar.gz` to
that message, since a chat session cannot read this repository.

## Resuming

1. Read `DEPLOY.md` (server side, over SSH) and `DEPLOY-WINDOWS.md` (upload and
   smoke test from a Windows machine).
2. Rebuild the upload package from a checkout:
   ```bash
   composer install --no-dev --optimize-autoloader
   cd .. && tar czf apply_examination2.tar.gz \
       --exclude='var' --exclude='config/config.php' \
       --transform 's,^cdce_exam_application,apply_examination2,' \
       cdce_exam_application
   ```
3. Start at `DEPLOY.md` **Step 0**.

## The two things still unknown

These are the only placeholders left in the code, both in
`config/config.example.php`, and both need someone with MIS access:

1. **The real table and column names.** Run
   `tools/discover_mis.sql` against the MIS.
2. **The eligibility rule** — which candidates are entitled to a 100 Level
   repeat form for 2026. Run `tools/check_data_quality.sql` once the names from
   step 1 are filled in. Getting this wrong in the permissive direction lets
   any student in the MIS download a form.

Both scripts are read-only and return schema and counts only, never a student
row.

## Before announcing the tool to students

`DEPLOY.md` Step 6 ends with two checks that must return 403 or 404:

```
https://cdce.pdn.ac.lk/tools/apply_examination2/config/config.php
https://cdce.pdn.ac.lk/tools/apply_examination2/var/download.log
```

The first holds the MIS password. If it returns 200, take the tool down before
doing anything else.
