# Deploying to https://cdce.pdn.ac.lk/tools/apply_examination2/

Target: **FreeBSD 12.2 jail, Apache 2.4 + mod_php 7.4, mod_rewrite on,
`AllowOverride All`, no root, no `Alias`, no PHP command line.**

The package sits **inside** the document root at:

```
/usr/local/www/cdce.pdn.ac.lk/tools/apply_examination2/
```

Dependencies are bundled, so nothing needs Composer or internet access on the
server. Because there is no CLI, the checks that would normally run from a
shell are done by a one-time web page instead.

---

## Step 1 — Back up what is there now

```sh
cd /usr/local/www/cdce.pdn.ac.lk/tools
mv apply_examination2 apply_examination2.old-$(date +%Y%m%d)
```

Renaming rather than deleting is the whole rollback plan. Do not skip it.

---

## Step 2 — Upload and extract

From your own machine:

```powershell
scp apply_examination2.tar.gz you@cdce.pdn.ac.lk:/tmp/
```

On the server:

```sh
cd /usr/local/www/cdce.pdn.ac.lk/tools
tar xzf /tmp/apply_examination2.tar.gz
ls apply_examination2/public/index.php    # must exist
```

---

## Step 3 — Ownership and permissions

Apache in a FreeBSD jail runs as **`www`**. Only `var/` needs to be writable;
everything else can stay read-only to the web user.

```sh
cd /usr/local/www/cdce.pdn.ac.lk/tools/apply_examination2

mkdir -p var
chown -R root:wheel .
chown -R www:www var
chmod 750 var
find . -type d ! -path './var*' -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
```

`config/config.php` comes later and gets tighter permissions than the rest.

---

## Step 4 — Configure

```sh
cp config/config.example.php config/config.php
chown root:www config/config.php
chmod 640 config/config.php
vi config/config.php
```

The MIS values are already filled in for `dbcdce2`:

| Setting | Value |
|---|---|
| dsn | `mysql:host=10.40.129.2;port=3306;dbname=dbcdce2;charset=utf8mb4` |
| username | `cdce_apply_ro` |
| table | `tblstudent` |
| columns | `reg_no`, `nic`, `name_ini`, `full_name` |
| eligibility | BA programme, active, no open offence hold |

Two things you must set by hand:

1. **`password`** — the MIS password for `cdce_apply_ro`.
2. **`setup_token`** — a long random string for Step 6. Generate one anywhere:

   ```sh
   openssl rand -hex 32
   ```

The MIS account needs `SELECT` on **both** `tblstudent` and `tbl_hold`
(the eligibility rule reads the hold table) and nothing else:

```sql
CREATE USER 'cdce_apply_ro'@'<jail-ip>' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON dbcdce2.tblstudent TO 'cdce_apply_ro'@'<jail-ip>';
GRANT SELECT ON dbcdce2.tbl_hold  TO 'cdce_apply_ro'@'<jail-ip>';
FLUSH PRIVILEGES;
```

Confirm it cannot write — this must be refused:

```sql
UPDATE tblstudent SET full_name = 'x' WHERE 1 = 0;
-- ERROR 1142 (42000): UPDATE command denied to user 'cdce_apply_ro'...
```

---

## Step 5 — Check the jail can reach the MIS

The jail's network is not your desk's. Nothing works if this fails:

```sh
nc -z -v 10.40.129.2 3306
```

If it is refused, the jail needs a firewall rule or the MIS needs to accept the
jail's address. Sort that out before going further.

---

## Step 6 — Run the one-time web check

There is no PHP command line in the jail, so the checks run as a web page. It
is guarded by the `setup_token` you set in Step 4, and returns **404** to
anyone without it.

```
https://cdce.pdn.ac.lk/tools/apply_examination2/setup_check.php?token=<your-token>
```

It verifies PHP 7.4, the extensions, the bundled `vendor/`, the template, a
writable `var/`, the MIS connection, and that the table, the four columns and
the eligibility clause all resolve.

Then use the form on that page to generate a sample application for a
candidate you know is eligible. **Open the PDF and check all four particulars
land in the right boxes.** Try the same student in the other NIC format too —
both must produce the same application.

---

## Step 7 — The checks that must FAIL

The package is inside the document root, so `.htaccess` is the only thing
keeping the MIS password off the web. **Every one of these must return 403 or
404.** If `config/config.php` returns 200, take the tool down immediately.

```sh
B=https://cdce.pdn.ac.lk/tools/apply_examination2
for p in config/config.php var/download.log src/MisRepository.php \
         vendor/autoload.php tools/check_mis.php README.md composer.json; do
  printf '%-32s ' "$p"
  fetch -q -o /dev/null "$B/$p" 2>&1 | head -1 || echo denied
done
```

Or from PowerShell:

```powershell
$B = 'https://cdce.pdn.ac.lk/tools/apply_examination2'
foreach ($p in 'config/config.php','var/download.log','src/MisRepository.php',
               'vendor/autoload.php','tools/check_mis.php','README.md','composer.json') {
    try   { $r = Invoke-WebRequest "$B/$p" -UseBasicParsing -ErrorAction Stop
            Write-Host ("{0,-32} {1}  <-- EXPOSED" -f $p, $r.StatusCode) -ForegroundColor Red }
    catch { $c = $_.Exception.Response.StatusCode.value__
            Write-Host ("{0,-32} {1}" -f $p, $c) -ForegroundColor $(if ($c -in 403,404) {'Green'} else {'Red'}) }
}
```

One more, easy to miss: confirm PHP is actually **executing** rather than being
served as text. If mod_php were misconfigured, every `.php` file under
`public/` would be handed out as source.

```sh
fetch -q -o - "$B/" | head -c 40
```

It must start with `<!DOCTYPE html`, never `<?php`. Same check on the setup
page, which must return **404** without a token once PHP is running:

```sh
fetch -q -o /dev/null "$B/setup_check.php"   # expect 404
```

`config/config.php` stays denied by `.htaccess` whatever happens to mod_php, so
the password is not at risk either way — but source disclosure is silent, and
this is the only thing that catches it.

These rules were tested against Apache 2.4 with the package at this exact path,
including `../` traversal, encoded slashes, double slashes and case variation.
They hold only while `AllowOverride All` and `mod_rewrite` are on — the
`.htaccess` denies everything outright if `mod_rewrite` is missing, so a module
change fails closed rather than exposing the package.

---

## Step 8 — Delete the setup page

```sh
rm /usr/local/www/cdce.pdn.ac.lk/tools/apply_examination2/public/setup_check.php
```

Then blank `setup_token` in `config/config.php`. The page can read student
records and its token has been sitting in your browser history and the Apache
access log.

---

## Step 9 — Smoke test as a student

```
https://cdce.pdn.ac.lk/tools/apply_examination2/
```

Enter a real eligible NIC. You should get
`BA-100-Level-2026-AE-BA-21-1234.pdf`, two pages, four particulars printed.

---

## Rolling back

```sh
cd /usr/local/www/cdce.pdn.ac.lk/tools
rm -rf apply_examination2
mv apply_examination2.old-<date> apply_examination2
```

Nothing in this tool writes to the MIS, so there is nothing else to undo.

---

## Notes for this server

- **PHP 7.4.** The code targets 7.4 and was tested on 7.4.33; it also runs
  unchanged on PHP 8. `src/compat.php` polyfills `str_starts_with` and
  `str_contains`, and does nothing on PHP 8.
- **MySQL 5.5.38.** The NIC lookup compares
  `UPPER(REPLACE(REPLACE(nic,' ',''),'-',''))`, which cannot use an index —
  MySQL 5.5 has no functional indexes. On a large `tblstudent` that is a full
  scan per lookup. If downloads feel slow during application season, add a
  normalised column maintained by a trigger and point `mis.columns.nic` at it.
  An index on `tbl_hold(student_id, type, hold_status)` is worth having either
  way.
- **The eligibility rule reads two tables.** `tblstudent` and `tbl_hold`. A
  candidate is refused only by an **open** offence hold (`hold_status = 1`);
  a released hold, or a hold of another type, does not block them.
- **`var/` must stay writable by `www`** or every download fails at the
  rate-limiter. This is the most common cause of a working tool breaking after
  a file copy.
